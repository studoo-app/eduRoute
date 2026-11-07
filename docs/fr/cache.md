# Cache et chargement paresseux

[English](../en/cache.md)

Avant de pouvoir router une requête, EduRoute doit analyser chaque motif d'URL et construire
les expressions régulières combinées (voir [Comment ça marche](how-it-works.md)). Ce travail
a un coût, et il donne toujours le même résultat tant que vos routes ne changent pas. Le cache
le fait une seule fois, puis réutilise le résultat à chaque requête.

Cette page explique :

- comment fonctionne le cache par défaut (`FileCache`) ;
- comment le désactiver, ou le remplacer par un cache PSR-16 ou par votre propre cache ;
- quand et comment l'invalider ;
- pourquoi les *handlers* doivent être exportables ;
- comment retarder le chargement des routes avec `lazyDispatcher()` et `lazyUriGenerator()`.

## Le cache par défaut : `FileCache`

`EduRoute::recommendedSettings($routes, $cacheKey)` active le cache
[`FileCache`](../../src/Cache/FileCache.php). Le second argument, `$cacheKey`, est **le chemin du
fichier de cache**.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\EduRoute;

$cacheFile = __DIR__ . '/var/cache/routes.php';

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users', 'App\Controller\UserController::list');
        $r->get('/user/{id:\d+}', 'App\Controller\UserController::show', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
    },
    $cacheFile,
);

echo file_exists($cacheFile) ? 'cache présent' : 'pas de cache', PHP_EOL;

$result = $router->dispatcher()->dispatch('GET', '/user/42');

echo file_exists($cacheFile) ? 'cache présent' : 'pas de cache', PHP_EOL;

if ($result instanceof Matched) {
    echo $result->handler, ' ', $result->variables['id'], PHP_EOL;
}

echo $router->uriGenerator()->forRoute('user.show', ['id' => '7'])->path, PHP_EOL;
```

Premier lancement :

```text
pas de cache
cache présent
App\Controller\UserController::show 42
/user/7
```

Aux lancements suivants, la première ligne devient `cache présent` : les routes sont lues dans le
fichier, la fonction de définition n'est plus appelée.

Ce qu'il faut savoir sur `FileCache` :

- **Quand le fichier est-il lu ou écrit ?** Au premier appel de `dispatcher()` ou
  `uriGenerator()` (ou au premier appel d'une méthode d'un objet paresseux, voir
  [plus bas](#chargement-paresseux)), pas à la création de `EduRoute`.
- **Format.** Le fichier est un script PHP qui contient `<?php return array (...);`, écrit avec
  `var_export()`. Il est relu par un simple `include` : avec OPcache, il reste en mémoire
  compilée et sa lecture est presque gratuite.
- **Contenu.** Un tableau à trois entrées : routes statiques, routes dynamiques (expressions
  régulières) et routes nommées. Ces dernières servent au
  [générateur d'URI](uri-generation.md).
- **Écriture sûre.** Le fichier est d'abord écrit dans `<fichier>.tmp` (avec verrou), puis
  renommé : une requête concurrente ne lit jamais un fichier à moitié écrit. Le fichier reçoit
  les droits `0664`.
- **Dossier.** Le dossier est créé s'il n'existe pas (droits `0775`). S'il ne peut pas être
  créé, ou s'il n'est pas accessible en écriture, une `RuntimeException` est levée :
  `The cache directory is not writable "/chemin/du/dossier"`.
- **Fichier d'un autre format.** Un fichier qui ne contient pas un tableau à trois entrées (par
  exemple un cache de FastRoute 1.x, à deux entrées) est ignoré et régénéré.

> **Note**
> Une même instance de `EduRoute` ne calcule (ou ne lit) les routes qu'une fois :
> `dispatcher()` et `uriGenerator()` appelés sur la même instance partagent le résultat. Chaque
> méthode `with*()`, `use*()` ou `disableCache()` renvoie une **nouvelle** instance, qui repart
> de zéro.

## Désactiver le cache

En développement, ou si vos *handlers* sont des closures, désactivez le cache avec
`disableCache()`. La clé passée à `recommendedSettings()` n'est alors pas utilisée : une valeur
quelconque convient.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$builds = 0;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r) use (&$builds): void {
        $builds++;
        $r->get('/user/{id:\d+}', 'UserController::show', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
    },
    'unused',
)->disableCache();

$router->dispatcher();
$router->dispatcher();
$router->uriGenerator();
echo $builds, PHP_EOL; // 1 : les routes sont calculées une seule fois par instance

$router->useGroupCountDispatcher()->dispatcher(); // nouvelle instance : nouveau calcul
echo $builds, PHP_EOL; // 2
```

Sans cache, les routes sont recalculées à chaque requête PHP (une fois par instance de
`EduRoute`). Vous voyez donc toujours vos dernières modifications, au prix d'un léger surcoût.

## Invalider le cache

**Le cache n'est jamais invalidé automatiquement.** Tant que le fichier existe, il est utilisé,
même si vos routes ont changé :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$cacheFile = __DIR__ . '/var/cache/routes.php';
@unlink($cacheFile); // on part d'un cache vide pour l'exemple

$v1 = static function (ConfigureRoutes $r): void {
    $r->get('/hello', 'HelloController::v1');
};
$v2 = static function (ConfigureRoutes $r): void {
    $r->get('/hello', 'HelloController::v2'); // le handler a changé
    $r->get('/new', 'NewController::index');  // nouvelle route
};

// 1er déploiement : le cache est écrit avec les routes v1
EduRoute::recommendedSettings($v1, $cacheFile)->dispatcher();

// Les routes changent, mais le fichier de cache existe déjà
$dispatcher = EduRoute::recommendedSettings($v2, $cacheFile)->dispatcher();
echo $dispatcher->dispatch('GET', '/hello')->handler, PHP_EOL; // HelloController::v1 (périmé !)
echo $dispatcher->dispatch('GET', '/new')::class, PHP_EOL;     // Studoo\EduRoute\Dispatcher\Result\NotMatched

// Invalidation : on supprime le fichier, il est régénéré au prochain build
unlink($cacheFile);
$dispatcher = EduRoute::recommendedSettings($v2, $cacheFile)->dispatcher();
echo $dispatcher->dispatch('GET', '/hello')->handler, PHP_EOL; // HelloController::v2
```

Supprimez donc le fichier de cache :

- **quand vos routes changent** (à chaque déploiement, par exemple) ;
- **quand vous changez de stratégie de dispatch** (`useGroupCountDispatcher()`…). Le format des
  données dépend de la stratégie : un cache écrit par la stratégie Mark et relu par la stratégie
  GroupCount provoque des avertissements PHP (`Undefined array key`…), des résultats faux, voire
  une erreur fatale (`TypeError`). Une
  autre solution : une clé (un nom de fichier) différente par stratégie, par exemple
  `var/cache/routes-groupcount.php`.

> **Attention**
> Si OPcache est configuré avec `opcache.validate_timestamps=0` (fréquent en production), PHP
> ne vérifie plus si les fichiers ont changé. Après avoir supprimé le fichier de cache, videz
> aussi OPcache (redémarrage de PHP-FPM ou `opcache_reset()`), sinon l'ancienne version compilée
> peut encore être servie.

> **Note**
> `declaredRoutes()`, qui sert à [lister les routes](listing-routes.md), ne lit ni n'écrit le
> cache : la liste reflète toujours le code actuel.

## Handlers exportables et `CannotCacheRoutes`

`FileCache` écrit les routes avec `var_export()`. Tout ce que vous enregistrez dans une route
(le *handler* et les [paramètres supplémentaires](routes.md#paramètres-supplémentaires)) doit donc être **exportable** :

| Valeur | Avec `FileCache` |
|---|---|
| chaîne : `'App\Controller\UserController::show'` | oui |
| tableau : `['App\Controller\UserController', 'show']` | oui |
| scalaire (`int`, `float`, `bool`), `null` | oui |
| closure : `static fn () => ...` | non |
| objet (service, contrôleur instancié, instance d'enum comme `HttpMethod::Get`…) | non |

Un objet exporté par `var_export()` prend la forme `Classe::__set_state(...)`, que PHP ne sait
pas toujours relire : la requête suivante finirait en erreur fatale. `FileCache` vérifie donc les
données **avant** d'écrire et lève
[`CannotCacheRoutes`](../../src/Cache/CannotCacheRoutes.php) dès qu'il rencontre un objet, à
n'importe quelle profondeur. Aucun fichier n'est écrit.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\Cache\CannotCacheRoutes;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$routes = static function (ConfigureRoutes $r): void {
    $r->get('/hello', static fn (): string => 'Bonjour !'); // closure : non exportable
};

try {
    EduRoute::recommendedSettings($routes, __DIR__ . '/var/cache/routes.php')->dispatcher();
} catch (CannotCacheRoutes $e) {
    echo $e->getMessage(), PHP_EOL;
}

// Sans cache, la closure fonctionne
$result = EduRoute::recommendedSettings($routes, 'unused')
    ->disableCache()
    ->dispatcher()
    ->dispatch('GET', '/hello');

echo ($result->handler)(), PHP_EOL; // Bonjour !
```

Sortie :

```text
Les routes ne peuvent pas être mises en cache dans un fichier : elles contiennent un objet (Closure). Avec un cache, utilisez des handlers exportables (chaîne "App\Controller\UserController::show", tableau, scalaire) ; les closures et objets ne fonctionnent qu'avec ->disableCache().
Bonjour !
```

Le type de l'objet fautif est indiqué entre parenthèses (`Closure`,
`Studoo\EduRoute\HttpMethod`…). Pour corriger, deux possibilités :

- remplacez l'objet par une valeur exportable (chaîne `"Classe::méthode"`, tableau,
  `HttpMethod::Get->value` au lieu de `HttpMethod::Get`…) ;
- ou désactivez le cache avec `->disableCache()`.

Voir aussi la [référence des erreurs](errors.md#cannotcacheroutes).

## Un autre stockage : `Psr16Cache`

Pour stocker les routes ailleurs que dans un fichier (Redis, APCu, Memcached…), utilisez
[`Psr16Cache`](../../src/Cache/Psr16Cache.php), qui enveloppe n'importe quel cache
[PSR-16](https://www.php-fig.org/psr/psr-16/) (`Psr\SimpleCache\CacheInterface`, le paquet
`psr/simple-cache` est déjà une dépendance d'EduRoute). Passez-le à `withCache($driver, $cacheKey)`,
qui remplace le cache et la clé choisis par `recommendedSettings()` :

```php
use Studoo\EduRoute\Cache\Psr16Cache;

// $psr16Cache : votre cache PSR-16 (Redis, APCu…)
$router = EduRoute::recommendedSettings($routes, 'routes')
    ->withCache(new Psr16Cache($psr16Cache), 'routes.v1');
```

Exemple complet, avec un petit cache PSR-16 en mémoire écrit pour l'occasion :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Psr\SimpleCache\CacheInterface;
use Studoo\EduRoute\Cache\Psr16Cache;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

// Cache PSR-16 minimal en mémoire, pour l'exemple (en vrai : Redis, APCu…)
final class ArrayPsr16 implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $items = [];

    public function get(string $key, mixed $default = null): mixed { return $this->items[$key] ?? $default; }
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool { $this->items[$key] = $value; return true; }
    public function delete(string $key): bool { unset($this->items[$key]); return true; }
    public function clear(): bool { $this->items = []; return true; }
    public function getMultiple(iterable $keys, mixed $default = null): iterable { foreach ($keys as $k) { yield $k => $this->get($k, $default); } }
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool { foreach ($values as $k => $v) { $this->set($k, $v); } return true; }
    public function deleteMultiple(iterable $keys): bool { foreach ($keys as $k) { $this->delete($k); } return true; }
    public function has(string $key): bool { return isset($this->items[$key]); }
}

$psr16Cache = new ArrayPsr16();

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/user/{id:\d+}', 'App\Controller\UserController::show');
    },
    'routes', // remplacée par la clé de withCache()
)->withCache(new Psr16Cache($psr16Cache), 'routes.v1');

echo $router->dispatcher()->dispatch('GET', '/user/42')->handler, PHP_EOL; // App\Controller\UserController::show
echo implode(', ', array_keys($psr16Cache->items)), PHP_EOL;              // routes.v1

$psr16Cache->delete('routes.v1'); // invalidation : l'entrée sera recalculée au prochain build
```

Avec `Psr16Cache` :

- la clé est une **clé PSR-16**, pas un chemin : évitez les caractères réservés par PSR-16
  (`{}()/\@:`). Mettre un numéro de version ou le nom de la stratégie dans la clé
  (`routes.v1`, `routes.groupcount`) est une façon simple d'invalider ;
- l'entrée est écrite sans durée de vie (TTL) : elle reste jusqu'à ce que vous la supprimiez
  (`delete()`, `clear()`) ou que le cache l'évince ;
- une entrée absente, ou qui n'est pas un tableau à trois entrées, est recalculée et réécrite ;
- `Psr16Cache` ne vérifie pas les objets comme le fait `FileCache` : ce qui se passe avec une
  closure dépend de votre implémentation PSR-16 (la plupart sérialisent les valeurs, et une
  closure ne peut pas être sérialisée). Gardez des *handlers* exportables.

`withCache()` accepte aussi un nom de classe, instanciée sans argument : par exemple
`->withCache(FileCache::class, __DIR__ . '/var/cache/routes.php')`. `withCache()` réactive aussi
le cache après un `disableCache()`.

## Écrire votre propre cache

Un cache est une classe qui implémente l'interface [`Studoo\EduRoute\Cache`](../../src/Cache.php),
qui n'a qu'une méthode :

```php
public function get(string $key, Closure $loader): array;
```

Elle reçoit la clé et une fonction `$loader` qui calcule les routes. Elle renvoie les données en
cache si elles existent ; sinon, elle appelle `$loader()`, stocke le résultat et le renvoie.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\Cache;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

// Un cache maison : garde les routes en mémoire et affiche ce qu'il fait
final class LoggingMemoryCache implements Cache
{
    /** @var array<string, array<mixed>> */
    private array $entries = [];

    public function get(string $key, Closure $loader): array
    {
        if (isset($this->entries[$key])) {
            echo "lecture de « $key »", PHP_EOL;

            return $this->entries[$key];
        }

        echo "calcul de « $key »", PHP_EOL;

        return $this->entries[$key] = $loader();
    }
}

$cache = new LoggingMemoryCache();
$routes = static function (ConfigureRoutes $r): void {
    $r->get('/', 'HomeController::index');
};

EduRoute::recommendedSettings($routes, 'unused')->withCache($cache, 'routes')->dispatcher(); // calcul de « routes »
EduRoute::recommendedSettings($routes, 'unused')->withCache($cache, 'routes')->dispatcher(); // lecture de « routes »
```

## Chargement paresseux

`dispatcher()` et `uriGenerator()` chargent les routes immédiatement. Or certaines requêtes ne
routent rien : commande CLI, fichier servi par un middleware en amont, réponse en cache HTTP…
Pour elles, ce chargement est inutile.

`lazyDispatcher()` et `lazyUriGenerator()` renvoient des **objets paresseux** (*lazy objects*,
nouveauté de PHP 8.4) : l'objet est créé tout de suite, mais les routes ne sont lues dans le
cache, ou calculées, qu'au **premier appel d'une méthode**.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$builds = 0;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r) use (&$builds): void {
        $builds++;
        $r->get('/user/{id:\d+}', 'UserController::show');
    },
    'unused',
)->disableCache();

$dispatcher = $router->lazyDispatcher(); // rien n'est chargé ici
echo $builds, PHP_EOL; // 0

$result = $dispatcher->dispatch('GET', '/user/42'); // les routes sont chargées ici
echo $builds, PHP_EOL; // 1

$dispatcher->dispatch('GET', '/user/43'); // déjà chargé : aucun nouveau calcul
echo $builds, PHP_EOL; // 1
echo $result->handler, PHP_EOL; // UserController::show
```

À retenir :

- le type de retour ne change pas : `lazyDispatcher()` renvoie un `Dispatcher`,
  `lazyUriGenerator()` un `GenerateUri`. Vous pouvez les passer partout où un objet ordinaire est
  attendu (par exemple au [middleware PSR-15](middleware.md)) ;
- une fois initialisé, l'objet est un dispatcher (ou un générateur) ordinaire, sans surcoût ;
- les erreurs sont **retardées** elles aussi : une `CannotCacheRoutes` ou une
  [`BadRouteException`](errors.md) n'est levée qu'au premier `dispatch()` ou `forRoute()`, pas à
  l'appel de `lazyDispatcher()` ;
- seule limite : un dispatcher ou un générateur maison (voir
  [implémentations personnalisées](how-it-works.md#implémentations-personnalisées)) qui hérite d'une classe interne de PHP ne
  peut pas être paresseux (limite du moteur). Il est alors construit immédiatement, sans erreur.

---

← [Page précédente](listing-routes.md) · [Sommaire](index.md) · [Page suivante](middleware.md) →
