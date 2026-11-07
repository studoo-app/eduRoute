# Migration

[English](../en/migration.md)

Cette page explique comment passer de [`nikic/fast-route`](https://github.com/nikic/FastRoute)
à `studoo/edu-route` 1.1. EduRoute est un fork de la branche `master` de FastRoute (la future
2.0) : si vous venez de FastRoute 1.x, comme edu-framework, la plupart des changements viennent
de cette branche ; d'autres sont propres à EduRoute.

## Avant de commencer

- EduRoute demande **PHP 8.4 ou 8.5** (`~8.4.0 || ~8.5.0`).
- La migration est la même pour la 1.0 et la 1.1, à une différence près, décrite dans
  [Changements de comportement](#changements-de-comportement-en-10x-et-11).
- Rien ne vous oblige à tout migrer d'un coup : l'ancien accès tableau aux résultats fonctionne
  encore (avec un avertissement de dépréciation). Faites d'abord fonctionner votre code, puis
  supprimez les dépréciations.

## Depuis `nikic/fast-route` 1.x

C'est le cas d'edu-framework. Suivez les étapes dans l'ordre.

### 1. Dépendance et namespace

```diff
- "nikic/fast-route": "^1.3"
+ "studoo/edu-route": "^1.1"
```

Remplacez **tous** les préfixes `FastRoute\` par `Studoo\EduRoute\` : lignes `use`, noms
pleinement qualifiés dans le code (`\FastRoute\RouteCollector`), types de propriétés et de
paramètres, et PHPDoc (`@var \FastRoute\RouteCollector`).

```diff
- use FastRoute\Dispatcher;
- use FastRoute\RouteCollector;
- use FastRoute\RouteParser\Std;
+ use Studoo\EduRoute\ConfigureRoutes;
+ use Studoo\EduRoute\DataGenerator;
+ use Studoo\EduRoute\Dispatcher;
+ use Studoo\EduRoute\EduRoute;
+ use Studoo\EduRoute\RouteCollector;
+ use Studoo\EduRoute\RouteParser\Std;
```

> **Note**
> Après le remplacement, une recherche de `FastRoute\` dans tout le projet (code,
> configuration, PHPDoc) ne doit plus rien trouver, hormis `composer.lock` avant le
> `composer update`. Cherchez bien `FastRoute\` (avec la barre oblique inverse) : vos propres
> noms de classes peuvent contenir `FastRoute`, comme `FastRouteCore` dans edu-framework.

### 2. Construire le dispatcher

Les fonctions `FastRoute\simpleDispatcher()` et `FastRoute\cachedDispatcher()` n'existent plus,
ni `RouteCollector::getData()`. Utilisez le constructeur
[`EduRoute`](../../src/EduRoute.php) :

```diff
- $dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r) {
-     $r->addRoute('GET', '/users', 'handler');
- });
+ $dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
+     $r->addRoute('GET', '/users', 'handler');
+ }, 'unused')->disableCache()->dispatcher();
```

Le deuxième argument de `recommendedSettings()` est la clé du cache (pour `FileCache`, le chemin
du fichier). Il est obligatoire même si vous désactivez ensuite le cache : une valeur comme
`'unused'` suffit alors.

| Avant (fonction ou option) | Après |
|---|---|
| `simpleDispatcher($cb)` | `EduRoute::recommendedSettings($cb, 'unused')->disableCache()->dispatcher()` |
| `cachedDispatcher($cb, ['cacheFile' => $f])` | `EduRoute::recommendedSettings($cb, $f)->dispatcher()` (voir l'avertissement ci-dessous) |
| option `cacheDisabled => true` | `->disableCache()` |
| options `dataGenerator` + `dispatcher` | `->useMarkDispatcher()`, `->useGroupCountDispatcher()`, `->useGroupPosDispatcher()`, `->useCharCountDispatcher()` ou `->useCustomDispatcher($generateur, $dispatcher)` |
| options `routeParser` / `routeCollector` | pas d'équivalent sur `EduRoute` : construisez le collecteur vous-même (voir plus bas) |
| `$collector->getData()` | `$collector->processedRoutes()` |

Quelques différences à connaître :

- la fonction de définition des routes doit être une `Closure` (une fonction anonyme, ou
  `$objet->methode(...)`), pas une chaîne ni un tableau « callable » ;
- typez son paramètre avec l'interface `ConfigureRoutes`. Le type `RouteCollector` fonctionne
  aussi (c'est la classe utilisée), mais l'interface est le contrat stable ;
- la stratégie par défaut est désormais `MarkBased` (c'était `GroupCountBased`). Les quatre
  stratégies donnent les mêmes résultats ; voir [Comment ça marche](how-it-works.md#les-quatre-stratégies) ;
- chaque méthode `use*()`, `with*()` ou `disableCache()` renvoie une **nouvelle** instance :
  gardez la valeur renvoyée.

> **Attention**
> **Le format du cache a changé.** Supprimez l'ancien fichier `cacheFile` (ou utilisez un autre
> chemin) au déploiement. Un fichier à l'ancien format de FastRoute 1.x (2 entrées) est détecté
> et régénéré, mais un cache écrit avec une autre stratégie de dispatch ne l'est pas. Avec un
> cache, les handlers doivent être exportables (chaînes, tableaux, scalaires) : une closure lève
> [`CannotCacheRoutes`](errors.md#cannotcacheroutes). Voir [Cache](cache.md#invalider-le-cache).

#### Construire le collecteur vous-même

C'est ce que fait edu-framework (`FastRouteCore`), et c'est la seule façon d'utiliser votre
propre `RouteParser` ou `ConfigureRoutes` :

```diff
- $this->routeCollector = new \FastRoute\RouteCollector(
-     new \FastRoute\RouteParser\Std(),
-     new \FastRoute\DataGenerator\GroupCountBased()
- );
- $dispatcher = new \FastRoute\Dispatcher\GroupCountBased($this->routeCollector->getData());
+ $this->routeCollector = new \Studoo\EduRoute\RouteCollector(
+     new \Studoo\EduRoute\RouteParser\Std(),
+     new \Studoo\EduRoute\DataGenerator\MarkBased(),
+ );
+ $dispatcher = new \Studoo\EduRoute\Dispatcher\MarkBased($this->routeCollector->processedRoutes());
```

`GroupCountBased` reste disponible ; `MarkBased` est simplement le plus rapide. Le générateur de
données et le dispatcher doivent toujours être **de la même famille**. Exemple complet :

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\DataGenerator\MarkBased as MarkBasedGenerator;
use Studoo\EduRoute\Dispatcher\MarkBased as MarkBasedDispatcher;
use Studoo\EduRoute\RouteCollector;
use Studoo\EduRoute\RouteParser\Std;

$routeCollector = new RouteCollector(new Std(), new MarkBasedGenerator());
$routeCollector->get('/hello/{name}', 'hello');

// processedRoutes() remplace getData()
$dispatcher = new MarkBasedDispatcher($routeCollector->processedRoutes());
$result = $dispatcher->dispatch('GET', '/hello/alice');

echo $result->handler, ' ', $result->variables['name'], PHP_EOL;
```

Sortie :

```text
hello alice
```

### 3. Lire le résultat du dispatch

`dispatch()` renvoie maintenant un objet : `Matched`, `MethodNotAllowed` ou `NotMatched` (voir
[Trouver la route d'une requête](dispatching.md)). L'ancien code qui lit un tableau fonctionne encore,
mais chaque lecture émet un `E_USER_DEPRECATED` :

```text
Method Studoo\EduRoute\Dispatcher\Result\Matched::offsetGet() is deprecated since 1.0, use $result instanceof Matched and its properties instead
```

Remplacez le `switch` sur `$routeInfo[0]` par `instanceof` et les propriétés :

```diff
- switch ($routeInfo[0]) {
-     case Dispatcher::NOT_FOUND: ...
-     case Dispatcher::METHOD_NOT_ALLOWED: $allowed = $routeInfo[1]; ...
-     case Dispatcher::FOUND: $handler = $routeInfo[1]; $vars = $routeInfo[2]; ...
- }
+ match (true) {
+     $result instanceof NotMatched => ...,
+     $result instanceof MethodNotAllowed => ... $result->allowedMethods ...,
+     $result instanceof Matched => ... $result->handler ... $result->variables ...,
+ };
```

| Avant (tableau) | Après (objet) |
|---|---|
| `$routeInfo[0] === Dispatcher::NOT_FOUND` | `$result instanceof NotMatched` |
| `$routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED` | `$result instanceof MethodNotAllowed` |
| `$routeInfo[1]` (méthodes autorisées) | `$result->allowedMethods` |
| `$routeInfo[0] === Dispatcher::FOUND` | `$result instanceof Matched` |
| `$routeInfo[1]` (handler) | `$result->handler` |
| `$routeInfo[2]` (variables) | `$result->variables` |
| *(n'existait pas)* | `$result->extraParameters` |

Exemple complet :

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->addRoute('GET', '/users/{id:\d+}', 'show_user');
}, 'unused')->disableCache()->dispatcher();

foreach ([['GET', '/users/42'], ['POST', '/users/42'], ['GET', '/posts']] as [$method, $uri]) {
    $result = $dispatcher->dispatch($method, $uri);

    echo match (true) {
        $result instanceof NotMatched => '404',
        $result instanceof MethodNotAllowed => '405, Allow: ' . implode(', ', $result->allowedMethods),
        $result instanceof Matched => $result->handler . ' ' . $result->variables['id'],
    }, PHP_EOL;
}
```

Sortie :

```text
show_user 42
405, Allow: GET
404
```

Les constantes `Dispatcher::FOUND`, `NOT_FOUND` et `METHOD_NOT_ALLOWED` n'ont de sens qu'avec
l'accès tableau : elles sont dépréciées elles aussi (en PHPDoc : PHPStan et votre IDE le
signalent, PHP n'émet rien à l'exécution). L'accès tableau et ces constantes seront supprimés
en 2.0.

> **Note**
> Les résultats sont immuables. Si votre code modifiait le tableau renvoyé (par exemple pour
> ajouter une variable), copiez plutôt les valeurs dans vos propres variables : une écriture
> lève une `Error` (propriétés) ou une `RuntimeException` (`Result cannot be changed`, accès
> tableau). Voir [Erreurs](errors.md#autres-erreurs).

### 4. Groupes et cache : `Closure` obligatoire

`addGroup()` et `Cache::get()` n'acceptent plus que des `Closure`, plus de chaîne ni de tableau
« callable ». Un tableau lève une `TypeError` :

```text
Studoo\EduRoute\RouteCollector::addGroup(): Argument #2 ($callback) must be of type Closure, array given, called in … on line …
```

La syntaxe `méthode(...)` (*first-class callable*) transforme une méthode en `Closure` :

```diff
- $r->addGroup('/admin', [$this, 'adminRoutes']);
+ $r->addGroup('/admin', $this->adminRoutes(...));
```

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

final class AdminRoutes
{
    public function register(ConfigureRoutes $r): void
    {
        // Avant : $r->addGroup('/admin', [$this, 'adminRoutes']);
        $r->addGroup('/admin', $this->adminRoutes(...));
    }

    public function adminRoutes(ConfigureRoutes $r): void
    {
        $r->get('/users', 'admin_users');
    }
}

$dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    (new AdminRoutes())->register($r);
}, 'unused')->disableCache()->dispatcher();

echo $dispatcher->dispatch('GET', '/admin/users')->handler, PHP_EOL;
```

Sortie :

```text
admin_users
```

### 5. Extensions

Ce point ne concerne que le code qui étend ou réimplémente le routeur.

- Les classes concrètes sont `final` (`RouteCollector`, `RouteParser\Std`, les dispatchers et
  générateurs de données, `BadRouteException`) : composez, ou implémentez les interfaces
  `ConfigureRoutes`, `RouteParser`, `DataGenerator`, `Dispatcher`.
- Les constantes publiques sont typées (`Dispatcher::FOUND` est un `int`,
  `ConfigureRoutes::ROUTE_NAME` une `string`) : une redéclaration doit reprendre le même type.
- Les objets résultat sont des classes `readonly` aux propriétés « write-once »
  (`public(set) readonly`) : un dispatcher maison les crée avec `new Matched()` puis écrit
  **une seule fois chacune** des propriétés `handler`, `variables` (plus de valeur par défaut
  `[]`) et `extraParameters`. Toute modification ultérieure lève une `Error`.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\Dispatcher\Result\Matched;

// Dans un dispatcher maison : les trois propriétés, une fois chacune
$result = new Matched();
$result->handler = 'show_user';
$result->variables = ['id' => '42'];
$result->extraParameters = [];

try {
    $result->handler = 'other'; // deuxième écriture : refusée
} catch (Error $e) {
    echo $e->getMessage(), PHP_EOL;
}
```

Sortie :

```text
Cannot modify readonly property Studoo\EduRoute\Dispatcher\Result\Matched::$handler
```

Oublier une propriété (par exemple `variables`) provoque une `Error` à la première lecture :
`Typed property Studoo\EduRoute\Dispatcher\Result\Matched::$variables must not be accessed before initialization`.

## Depuis `nikic/fast-route` 2.0-dev (branche `master`)

Tous les points ci-dessus s'appliquent (les fonctions supprimées au point 2 y étaient déjà
dépréciées), plus :

- le constructeur `FastRoute\FastRoute` devient `Studoo\EduRoute\EduRoute`, avec les mêmes
  méthodes ;
- les options `cacheDriver` / `cacheKey` deviennent `->withCache($driver, $cle)`.

## Changements de comportement en 1.0.x et 1.1

Ces changements ne demandent pas de modifier votre code, mais ils peuvent changer ce que vous
observez.

| Version | Avant | Maintenant |
|---|---|---|
| 1.0.0 | Avec `GroupPosBased`, un paramètre qui capture une chaîne vide (`{slug:[a-z]*}` sur `/page/`) envoyait vers la mauvaise route. | La bonne route est trouvée. Le format du cache de cette stratégie change. |
| 1.0.0 | Des routes contenant une closure étaient écrites dans un cache fichier illisible (erreur fatale à la requête suivante). | [`CannotCacheRoutes`](errors.md#cannotcacheroutes) est levée dès la première requête ; aucun fichier n'est écrit. |
| 1.0.1 | Si le moteur d'expressions régulières échouait (limite `pcre.backtrack_limit`, motif coûteux), la requête recevait un 404 sans explication. | [`RegexMatchingFailed`](errors.md#regexmatchingfailed) est levée, avec l'URI et l'erreur PCRE. |
| 1.1.0 | Un nom de route refusé (vide, pas une chaîne, déjà utilisé) laissait la route enregistrée si l'exception était attrapée. | Le nom est vérifié **avant** l'enregistrement : la route n'est pas ajoutée. Une erreur de nom est donc signalée avant une erreur de route en double ou masquée. |

Par exemple, en 1.1 :

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator\MarkBased as MarkBasedGenerator;
use Studoo\EduRoute\Dispatcher\MarkBased as MarkBasedDispatcher;
use Studoo\EduRoute\RouteCollector;
use Studoo\EduRoute\RouteParser\Std;

$r = new RouteCollector(new Std(), new MarkBasedGenerator());
$r->get('/', 'home', [ConfigureRoutes::ROUTE_NAME => 'home']);

try {
    // Nom déjà utilisé
    $r->get('/accueil', 'home2', [ConfigureRoutes::ROUTE_NAME => 'home']);
} catch (BadRouteException $e) {
    echo $e->getMessage(), PHP_EOL;
}

// La route refusée n'a pas été enregistrée
$dispatcher = new MarkBasedDispatcher($r->processedRoutes());
echo $dispatcher->dispatch('GET', '/accueil')::class, PHP_EOL;
```

Sortie :

```text
Cannot register two routes under the name "home"
Studoo\EduRoute\Dispatcher\Result\NotMatched
```

## Ce que vous gagnez

Une fois la migration faite, ces fonctionnalités sont disponibles (aucune n'est obligatoire) :

| Fonctionnalité | Page |
|---|---|
| Énumération `HttpMethod` (`HttpMethod::Get`…), acceptée avec les chaînes | [Définir les routes](routes.md) |
| Routes nommées et génération d'URL | [Générer des URL](uri-generation.md) |
| Routes par attributs `#[Route]` / `#[RouteGroup]` | [Routes par attributs](attributes.md) |
| Liste des routes déclarées (`declaredRoutes()`, 1.1) | [Lister les routes](listing-routes.md) |
| Cache PSR-16 et chargement paresseux (`lazyDispatcher()`) | [Cache](cache.md) |
| Middleware PSR-15 `RoutingMiddleware` (404, 405 avec `Allow`) | [Middleware PSR-15](middleware.md) |

## Liste de contrôle

1. `composer.json` : `nikic/fast-route` remplacé par `studoo/edu-route`, PHP 8.4 ou 8.5.
2. Plus aucune occurrence de `FastRoute\` dans le code ni dans la PHPDoc.
3. `simpleDispatcher()` / `cachedDispatcher()` remplacés par `EduRoute::recommendedSettings()`,
   `getData()` par `processedRoutes()`.
4. Ancien fichier de cache supprimé au déploiement ; handlers exportables si le cache est actif.
5. `addGroup()` reçoit des `Closure` (`$this->methode(...)`).
6. Résultats lus avec `instanceof` et les propriétés : plus aucun `E_USER_DEPRECATED` dans les
   journaux ou les tests.

---

← [Page précédente](errors.md) · [Sommaire](index.md)
