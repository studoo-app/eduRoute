# Erreurs et exceptions

[English](../en/errors.md)

Cette page recense les exceptions levées par EduRoute : quand elles apparaissent, le message
exact produit par le code, et comment corriger la cause.

## Vue d'ensemble

Toutes les exceptions propres à la bibliothèque implémentent l'interface
[`Studoo\EduRoute\Exception`](../../src/Exception.php) (qui étend `Throwable`).

| Exception | Classe parente | Quand | Messages |
|---|---|---|---|
| [`BadRouteException`](#badrouteexception) | `LogicException` | à l'enregistrement d'une route | anglais (français pour les attributs) |
| [`RegexMatchingFailed`](#regexmatchingfailed) | `RuntimeException` | au dispatch, ou en vérifiant le masquage d'une route statique | français |
| [`GenerateUri\UriCouldNotBeGenerated`](#uricouldnotbegenerated) | `LogicException` | à la génération d'une URL | anglais |
| [`Cache\CannotCacheRoutes`](#cannotcacheroutes) | `LogicException` | à l'écriture du cache fichier | français |

Les exceptions qui héritent de `LogicException` signalent une erreur dans votre code (une route
mal écrite, un nom inconnu) : corrigez le code plutôt que de les attraper. `RegexMatchingFailed`
hérite de `RuntimeException`, car elle dépend aussi de l'URI reçue et de la configuration de PHP.

Pour attraper n'importe laquelle d'entre elles, utilisez l'interface :

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\Exception as EduRouteException;

try {
    $dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
        $r->get('/users', 'list_users');
        $r->get('/users', 'list_users_again'); // même route, même méthode
    }, 'unused')->disableCache()->dispatcher();
} catch (EduRouteException $e) {
    echo $e::class, ': ', $e->getMessage(), PHP_EOL;
}
```

Sortie :

```text
Studoo\EduRoute\BadRouteException: Cannot register two routes matching "/users" for method "GET"
```

> **Note**
> Avec `EduRoute`, la fonction qui déclare les routes ne s'exécute qu'au premier appel de
> `dispatcher()`, `uriGenerator()` ou `declaredRoutes()` (au premier `dispatch()` avec
> `lazyDispatcher()`). Les erreurs d'enregistrement apparaissent donc à ce moment-là. Si un
> fichier de [cache](cache.md) valide existe déjà, `dispatcher()` et `uriGenerator()`
> n'exécutent pas du tout la fonction : une route fautive ajoutée depuis ne sera signalée qu'à
> la régénération du cache. Videz le cache pendant le développement, ou utilisez
> `disableCache()`. `declaredRoutes()`, lui, ignore le cache et exécute toujours la fonction.

## BadRouteException

[`Studoo\EduRoute\BadRouteException`](../../src/BadRouteException.php) — classe `final`, hérite
de `LogicException`.

Levée par `addRoute()` (et ses raccourcis `get()`, `post()`…) quand une route ne peut pas être
enregistrée. Les vérifications se font dans cet ordre :

1. la syntaxe du motif (segments optionnels, paramètres) ;
2. la ou les méthodes HTTP (une valeur invalide lève une `TypeError`, voir
   [Autres erreurs](#autres-erreurs)) ;
3. le nom de la route (`ConfigureRoutes::ROUTE_NAME`) ;
4. les conflits avec les routes déjà enregistrées (doublon, masquage).

Depuis la 1.1, un nom refusé n'enregistre pas la route : si vous attrapez l'exception, la route
ne devient pas dispatchable pour autant.

> **Attention**
> Une route déclarée pour plusieurs méthodes (`addRoute(['GET', 'POST'], …)`) est enregistrée
> méthode par méthode. Si l'erreur survient sur la deuxième méthode, la première reste
> enregistrée. Après une `BadRouteException`, ne réutilisez pas le collecteur : recréez-le.

### Erreurs de syntaxe du motif

Levées par l'analyseur [`RouteParser\Std`](../../src/RouteParser/Std.php) (voir
[Définir les routes](routes.md)).

| Message | Exemple fautif | Correction |
|---|---|---|
| `Optional segments can only occur at the end of a route` | `/a[/b]/c` | Placez les segments optionnels en fin de motif : `/a/c[/b]`, ou déclarez deux routes. |
| `Number of opening '[' and closing ']' does not match` | `/a[/b` | Fermez chaque `[` par un `]`. |
| `Empty optional part` | `/a[]` | Supprimez les crochets vides. |
| `Cannot use the same placeholder "id" twice` | `/a/{id}/{id}` | Donnez un nom différent à chaque paramètre. |
| `Regex "(en\|fr)" for parameter "lang" contains a capturing group` | `/a/{lang:(en\|fr)}` | Utilisez un groupe non capturant : `{lang:(?:en\|fr)}`. |

Le routeur ajoute lui-même un groupe capturant autour de chaque paramètre ; un groupe capturant
de plus dans votre motif décalerait les valeurs. D'où l'interdiction, et la forme `(?:…)`.

### Nom de route invalide

| Message | Cause | Correction |
|---|---|---|
| `Route name must be a non-empty string, "''" given` | `[ConfigureRoutes::ROUTE_NAME => '']` | Donnez un nom non vide. |
| `Route name must be a non-empty string, "42" given` | `[ConfigureRoutes::ROUTE_NAME => 42]` | Le nom doit être une chaîne. La valeur fautive est affichée par `var_export()`. |
| `Cannot register two routes under the name "home"` | deux routes nommées `home` | Chaque nom doit être unique : renommez l'une des routes. |

Voir [Générer des URL](uri-generation.md) pour l'usage des noms.

### Route déjà enregistrée

```text
Cannot register two routes matching "/users" for method "GET"
Cannot register two routes matching "/u/([^/]+)" for method "GET"
```

Deux routes ont le même motif pour la même méthode HTTP. Pour une route variable, le message
affiche l'expression régulière construite, pas le motif d'origine : `/u/{id}` et `/u/{name}`
donnent tous deux `/u/([^/]+)`, car seul le nom du paramètre change.

Correction : supprimez le doublon, ou différenciez les motifs (par exemple `{id:\d+}` d'un côté).

### Route statique masquée par une route variable

```text
Static route "/user/new" is shadowed by previously defined variable route "/user/([^/]+)" for method "GET"
```

Une route variable déclarée plus tôt (`/user/{name}`) correspond déjà à l'URL de la route
statique (`/user/new`) : cette dernière ne serait jamais atteinte. Déclarez la route statique
**avant** la route variable :

```php
$r->get('/user/new', 'new_user_form');   // d'abord la route statique
$r->get('/user/{name}', 'show_user');    // ensuite la route variable
```

Dans cet ordre, aucune exception n'est levée : `/user/new` va au formulaire, les autres URL à
`show_user`.

### Erreurs des routes par attributs

[`AttributeRouteLoader::register()`](../../src/Attribute/AttributeRouteLoader.php) lève aussi
des `BadRouteException`, avec des messages en français. Voir [Routes par attributs](attributes.md).

| Message (exemple) | Correction |
|---|---|
| `Contrôleur "App\Missing" introuvable` | Vérifiez le nom de la classe et son chargement automatique. |
| `"App\IC" est une interface, pas un contrôleur instanciable` (aussi : `un trait`, `une enum`) | Passez une classe concrète. |
| `Le contrôleur App\AbsC n'est pas instanciable (classe abstraite ou constructeur non public)` | Rendez la classe concrète et son constructeur public. |
| `#[Route] se place sur une méthode, pas sur la classe App\OnClass : utilisez #[RouteGroup] pour un préfixe commun` | Déplacez `#[Route]` sur une méthode ; pour un préfixe, utilisez `#[RouteGroup]`. |
| `#[RouteGroup] se place sur la classe, pas sur la méthode App\C::m()` | Déplacez `#[RouteGroup]` sur la classe. |
| `#[Route] sans méthode HTTP sur App\C::m()` | Retirez `methods: []` ou indiquez au moins une méthode. |
| `Nom de route vide sur App\C::m()` | Retirez `name: ''` ou donnez un nom. |
| `La méthode App\C::m() porte #[Route] mais doit être publique, non statique, non magique et déclarée dans le contrôleur App\C` | Rendez la méthode publique et non statique, et déclarez-la dans le contrôleur lui-même (pas dans une classe parente). |

Quand l'enregistrement de la route échoue (doublon, erreur de syntaxe…), le message d'origine est
complété par la méthode en cause, et l'exception d'origine est accessible par `getPrevious()` :

```text
Cannot register two routes matching "/admin/a" for method "GET" (route déclarée sur App\Dup::n())
```

## RegexMatchingFailed

[`Studoo\EduRoute\RegexMatchingFailed`](../../src/RegexMatchingFailed.php) — classe `final`,
hérite de `RuntimeException`. Ajoutée en 1.0.1.

Le moteur d'expressions régulières de PHP (PCRE) a échoué en testant une route. Sans cette
exception, l'échec passerait pour une absence de route, et une URL valide recevrait un 404 sans
explication (bug hérité de FastRoute). Le message cite l'URI ou la route, puis l'erreur PCRE
(`preg_last_error_msg()`) entre parenthèses, puis un conseil.

### Pendant le dispatch

Levée par `dispatch()`, avec les quatre stratégies de dispatch. Exemple avec un motif très
coûteux et une limite volontairement basse :

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\RegexMatchingFailed;

$dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->get('/{p:(?:a?a?)*}/complicated', 'complicated');
    $r->get('/{p:a+}', 'a');
}, 'unused')->disableCache()->dispatcher();

ini_set('pcre.backtrack_limit', '1000'); // limite basse pour l'exemple

echo $dispatcher->dispatch('GET', '/aaa')->handler, PHP_EOL; // une URI courte passe encore

try {
    $dispatcher->dispatch('GET', '/' . str_repeat('a', 40));
} catch (RegexMatchingFailed $e) {
    echo $e->getMessage(), PHP_EOL;
}
```

Sortie (l'URI est abrégée ici) :

```text
a
Impossible de chercher la route de l'URI "/aaaa…aaaa" : l'expression régulière des routes a échoué (Backtrack limit exhausted). Un paramètre de route utilise sans doute un motif trop coûteux, par exemple {p:(?:a?a?)*} : simplifiez-le, ou augmentez pcre.backtrack_limit / pcre.jit_stacklimit.
```

Le texte entre parenthèses peut aussi être `JIT stack limit exhausted` (pile du compilateur JIT
épuisée). Corrections, de la meilleure à la moins bonne :

- simplifiez le motif du paramètre : évitez les répétitions imbriquées comme `(?:a?a?)*`,
  préférez une classe de caractères simple (`[a-z]+`, `\d+`, `[^/]+`) ;
- en dernier recours, augmentez `pcre.backtrack_limit` ou `pcre.jit_stacklimit` dans `php.ini`.

### Motif de paramètre invalide

Un motif qui ne compile pas, par exemple `{p:a)}` (parenthèse fermante en trop), n'est pas
détecté à l'enregistrement. Il casse l'expression régulière combinée de tout un groupe de
routes : un `dispatch()` vers une **autre** route du même groupe échoue aussi. PHP émet alors un
avertissement `preg_match(): Compilation failed: …` qui précise l'erreur, et EduRoute lève :

```text
Impossible de chercher la route de l'URI "/u/1" : l'expression régulière des routes a échoué (Internal error). Le motif d'un paramètre de route est sans doute invalide (le message d'avertissement de preg_match() donne l'erreur de compilation) : corrigez-le.
```

Correction : lisez l'avertissement de `preg_match()` et corrigez le motif du paramètre.

### En vérifiant le masquage d'une route statique

À l'enregistrement d'une route statique, EduRoute teste chaque route variable déjà déclarée pour
la même méthode (voir [route masquée](#route-statique-masquée-par-une-route-variable)). Si ce test
échoue, l'exception est levée par `addRoute()` :

```text
Impossible de vérifier si la route statique "/aaaa…aaab/complicated" est masquée par la route variable "/((?:a?a?)*)/complicated" : l'expression régulière a échoué (Backtrack limit exhausted). Simplifiez le motif de la route variable.
```

Avec un motif invalide, la fin du message est le même conseil que ci-dessus (« Le motif d'un
paramètre de route est sans doute invalide… »).

## UriCouldNotBeGenerated

[`Studoo\EduRoute\GenerateUri\UriCouldNotBeGenerated`](../../src/GenerateUri/UriCouldNotBeGenerated.php)
— classe `final`, hérite de `LogicException`.

Levée par `GenerateUri::forRoute()` (voir [Générer des URL](uri-generation.md)). Exemples avec
cette route :

```php
$r->get('/user/{id:\d+}[/{slug}]', 'show_user', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
```

| Appel | Message | Correction |
|---|---|---|
| `forRoute('user.edit')` | `There is no route with name "user.edit" defined` | Vérifiez l'orthographe du nom, et que la route est déclarée avec `ROUTE_NAME`. |
| `forRoute('user.show')` | `Route "user.show" expects at least parameter values for [id], but received none` | Fournissez les paramètres obligatoires (ceux hors segments optionnels). |
| `forRoute('user.show', ['slug' => 'bob'])` | `Route "user.show" expects at least parameter values for [id], but received [slug]` | Idem : `id` manque, `slug` seul ne suffit pas. |
| `forRoute('user.show', ['id' => 'abc'])` | ``Route "user.show" expects the parameter [id] to match the regex `\d+` `` | Passez une valeur qui respecte le motif du paramètre. |

## CannotCacheRoutes

[`Studoo\EduRoute\Cache\CannotCacheRoutes`](../../src/Cache/CannotCacheRoutes.php) — classe
`final`, hérite de `LogicException`.

Levée par [`FileCache`](../../src/Cache/FileCache.php), le cache par défaut de
`recommendedSettings()`, quand les routes contiennent un objet (le plus souvent une closure
utilisée comme handler). Un tel objet ne peut pas être relu depuis un fichier PHP : plutôt que
d'écrire un cache qui provoquerait une erreur fatale à la requête suivante, `FileCache` refuse
d'écrire et lève l'exception dès la première requête. Aucun fichier n'est créé.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\Cache\CannotCacheRoutes;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    // Une closure ne peut pas être écrite dans un fichier de cache
    $r->get('/', static fn (): string => 'accueil');
}, __DIR__ . '/var/cache/routes.php');

try {
    $router->dispatcher();
} catch (CannotCacheRoutes $e) {
    echo $e->getMessage(), PHP_EOL;
}

// Sans cache, la closure fonctionne
$handler = $router->disableCache()->dispatcher()->dispatch('GET', '/')->handler;
echo $handler(), PHP_EOL;
```

Sortie :

```text
Les routes ne peuvent pas être mises en cache dans un fichier : elles contiennent un objet (Closure). Avec un cache, utilisez des handlers exportables (chaîne "App\Controller\UserController::show", tableau, scalaire) ; les closures et objets ne fonctionnent qu'avec ->disableCache().
accueil
```

Le type entre parenthèses (`Closure` ici) est celui de l'objet trouvé. Corrections :

- utilisez des handlers exportables : une chaîne comme `'App\Controller\UserController::show'`,
  un tableau ou un scalaire (les [routes par attributs](attributes.md) produisent déjà des
  chaînes `Classe::méthode`) ;
- ou désactivez le cache avec `->disableCache()`.

Voir [Cache](cache.md).

## Autres erreurs

Ces erreurs n'implémentent **pas** `Studoo\EduRoute\Exception`.

| Erreur | Message | Quand |
|---|---|---|
| `TypeError` | `HTTP method must be Studoo\EduRoute\HttpMethod or string, int given` | Un tableau de méthodes passé à `addRoute()` contient autre chose qu'un cas de `HttpMethod` ou une chaîne (ici `[405]`). |
| `TypeError` | message de PHP (`… must be of type Closure, array given …`) | `addGroup()` ou `Cache::get()` reçoit un callable qui n'est pas une `Closure`. Voir [Migration](migration.md). |
| `RuntimeException` | `The cache directory is not writable "<dossier>"` | `FileCache` ne peut pas créer ou écrire dans le dossier du fichier de cache. Corrigez le chemin ou les droits. |
| `Error` | `Cannot modify readonly property Studoo\EduRoute\Dispatcher\Result\Matched::$handler` | Vous modifiez une propriété d'un résultat de dispatch, qui est immuable. |
| `RuntimeException` | `Result cannot be changed` | Écriture ou `unset()` via l'accès tableau déprécié (`$result[1] = …`). |
| `OutOfBoundsException` | (vide) | Lecture d'un index inexistant via l'accès tableau déprécié (par exemple `$result[3]`). |

L'accès tableau aux résultats (`$result[0]`, `$result[1]`…) fonctionne encore mais émet un
`E_USER_DEPRECATED` : voir [Trouver la route d'une requête](dispatching.md).

---

← [Page précédente](how-it-works.md) · [Sommaire](index.md) · [Page suivante](migration.md) →
