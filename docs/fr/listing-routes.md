# Lister les routes

[English](../en/listing-routes.md)

Quand une application grandit, il devient utile d'afficher toutes ses routes d'un coup : pour
vérifier un préfixe de groupe, retrouver le contrôleur d'une URL ou repérer une route oubliée.
C'est le rôle d'une commande `routes:list`, que l'on trouve dans la plupart des frameworks.

eduRoute fournit les données nécessaires avec la méthode `declaredRoutes()`. L'affichage
(tableau, couleurs, JSON…) reste à votre charge.

## `declaredRoutes()`

La méthode existe à deux endroits, avec le même résultat :

| Appel | Quand l'utiliser |
|---|---|
| [`EduRoute::declaredRoutes()`](../../src/EduRoute.php) | vous utilisez la façade `EduRoute` (`recommendedSettings()`…) |
| [`RouteCollector::declaredRoutes()`](../../src/RouteCollector.php) | vous construisez le `RouteCollector` vous-même (comme edu-framework) |

Elle renvoie une liste (`list<DeclaredRoute>`) des routes **déclarées avec succès**, dans
l'**ordre de déclaration**.

## L'objet `DeclaredRoute`

Chaque élément est un objet immuable [`Studoo\EduRoute\DeclaredRoute`](../../src/DeclaredRoute.php).
Vous n'avez jamais à le construire vous-même : c'est le routeur qui le crée.

| Propriété | Type | Contenu |
|---|---|---|
| `method` | `string` | `'GET'`, `'POST'`… ; une méthode non standard telle quelle ; `'*'` pour une route de repli |
| `pattern` | `string` | motif d'origine, **préfixe de groupe inclus**, segments optionnels non éclatés (`/articles/{year}[/{month}]`) |
| `handler` | `mixed` | le *handler* brut, tel que vous l'avez passé à `addRoute()` |
| `name` | `?string` | nom de la route (`ConfigureRoutes::ROUTE_NAME`), `null` si elle n'est pas nommée |
| `extraParameters` | `array` | paramètres supplémentaires, **sans** les clés réservées `_route` et `_name` |

Et deux méthodes :

| Méthode | Renvoie |
|---|---|
| `isFallback(): bool` | `true` pour une route de repli, déclarée avec `any()` ou `HttpMethod::Any` (`method` vaut `'*'`) |
| `describeHandler(): string` | un texte lisible du handler, à afficher (voir ci-dessous) |

## `describeHandler()` : afficher un handler

Un handler peut être n'importe quelle valeur : une chaîne, un tableau, une closure, un objet…
Un simple `echo $route->handler` provoquerait une erreur (`Array to string conversion`) ou un
affichage illisible. `describeHandler()` produit toujours une chaîne :

| Handler | Texte renvoyé |
|---|---|
| chaîne `'App\Controller\UserController::show'` | la chaîne telle quelle |
| tableau `['App\Controller\UserController', 'show']` | `App\Controller\UserController::show` |
| tableau `[$objet, 'show']` | `<classe de l'objet>::show` |
| objet invocable (méthode `__invoke`) | `<classe>::__invoke` |
| closure | `Closure (<fichier>:<ligne>)` : nom du fichier (sans le dossier) et ligne de début ; simplement `Closure` si PHP ne connaît pas le fichier |
| toute autre valeur | son type, au sens de `get_debug_type()` (`int`, `null`, `array`, `stdClass`…) |

La valeur brute reste disponible dans `$route->handler`.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator\MarkBased;
use Studoo\EduRoute\RouteCollector;
use Studoo\EduRoute\RouteParser\Std;

final class ShowUser
{
    public function __invoke(string $id): string
    {
        return "Utilisateur $id";
    }
}

final class UserController
{
    public function list(): string
    {
        return 'Utilisateurs';
    }
}

$collector = new RouteCollector(new Std(), new MarkBased());

$collector->get('/hello', static fn (): string => 'Bonjour');             // closure
$collector->get('/users', [new UserController(), 'list']);                 // [objet, méthode]
$collector->get('/users/{id:\d+}', new ShowUser(), [ConfigureRoutes::ROUTE_NAME => 'user.show', 'cache' => 60]);
$collector->get('/ping', 42);                                              // n'importe quelle valeur

foreach ($collector->declaredRoutes() as $route) {
    echo $route->pattern, ' => ', $route->describeHandler(), PHP_EOL;
}

$route = $collector->declaredRoutes()[2];
var_export($route->name);                                // 'user.show'
echo PHP_EOL;
var_export($route->extraParameters);                     // array ('cache' => 60) : sans _route ni _name
echo PHP_EOL;
var_export($route->handler instanceof ShowUser);         // true
echo PHP_EOL;
```

Sortie, si le fichier s'appelle `list.php` :

```text
/hello => Closure (list.php:29)
/users => UserController::list
/users/{id:\d+} => ShowUser::__invoke
/ping => int
'user.show'
array (
  'cache' => 60,
)
true
```

> **Note**
> Les closures et les objets fonctionnent ici parce que le `RouteCollector` n'a pas de cache.
> Avec `EduRoute` et un cache, seuls les handlers exportables sont acceptés au dispatch (voir
> [Cache](cache.md)) ; `declaredRoutes()`, elle, les accepte toujours (voir plus bas).

## Exemple : une commande `routes:list`

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/', 'App\Controller\HomeController', [ConfigureRoutes::ROUTE_NAME => 'home']);
        $r->get('/articles/{year:\d{4}}[/{month:\d{2}}]', 'archive', [ConfigureRoutes::ROUTE_NAME => 'blog.archive']);
        $r->addRoute(['GET', 'POST'], '/contact', ['App\Controller\ContactController', 'handle']);
        $r->addGroup('/admin', static function (ConfigureRoutes $r): void {
            $r->delete('/user/{id:\d+}', 'App\Controller\UserController::delete', ['role' => 'admin']);
        });
        $r->any('/{path:.+}', 'App\Controller\NotFoundController');
    },
    __DIR__ . '/var/cache/routes.php',
);

foreach ($router->declaredRoutes() as $route) {
    printf(
        "%-7s %-42s %-40s %s\n",
        $route->isFallback() ? 'ANY' : $route->method,  // '*' affiché ANY
        $route->pattern,
        $route->describeHandler(),                      // texte affichable, même pour un tableau
        $route->name ?? '-',
    );
}
```

```text
GET     /                                          App\Controller\HomeController            home
GET     /articles/{year:\d{4}}[/{month:\d{2}}]     archive                                  blog.archive
GET     /contact                                   App\Controller\ContactController::handle -
POST    /contact                                   App\Controller\ContactController::handle -
DELETE  /admin/user/{id:\d+}                       App\Controller\UserController::delete    -
ANY     /{path:.+}                                 App\Controller\NotFoundController        -
```

Avec des [routes par attributs](attributes.md), la commande est identique : chaque handler est
une chaîne `"Classe::méthode"`.

## À savoir

- **Une ligne par méthode.** `addRoute(['GET', 'POST'], …)` donne deux entrées consécutives.
- **HEAD n'est pas listé.** Le routeur répond à `HEAD` avec les routes `GET` (voir
  [Dispatcher une requête](dispatching.md)) ; cette réponse implicite n'apparaît pas dans la
  liste. Seule une route déclarée explicitement pour `HEAD` y figure.
- **`'*'` désigne une route de repli** (`any()`), d'où le test `isFallback()` pour l'afficher
  autrement.
- **Les segments optionnels ne sont pas éclatés** : `/articles/{year}[/{month}]` reste une
  seule entrée, telle que déclarée.
- **L'ordre est celui de la déclaration.** Pour trier, par exemple par motif :

  ```php
  $routes = $router->declaredRoutes();
  usort($routes, static fn (DeclaredRoute $a, DeclaredRoute $b): int => strcmp($a->pattern, $b->pattern));
  ```

  (avec `use Studoo\EduRoute\DeclaredRoute;`).

## `EduRoute::declaredRoutes()` et le cache

`$router->declaredRoutes()` **rejoue la fonction de définition des routes à chaque appel**, sur un
collecteur neuf, sans lire ni écrire le cache. Conséquences :

- la liste reflète toujours le code actuel, même si le fichier de cache est périmé ;
- les handlers non exportables (closures, objets) sont acceptés, même si le cache est activé ;
- les erreurs de définition (route en double, route masquée…) sont levées comme au dispatch,
  sous forme de [`BadRouteException`](errors.md).

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\Cache\CannotCacheRoutes;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/hello', static fn (): string => 'Bonjour');   // closure : non exportable
    },
    __DIR__ . '/var/cache/routes.php',
);

echo count($router->declaredRoutes()), PHP_EOL;                // 1 : la liste fonctionne

try {
    $router->dispatcher();                                      // le cache, lui, refuse la closure
} catch (CannotCacheRoutes $e) {
    echo $e::class, PHP_EOL;                                    // Studoo\EduRoute\Cache\CannotCacheRoutes
}
```

> **Attention**
> Comme elle reconstruit toutes les routes, `declaredRoutes()` est un outil de développement
> (commande CLI, page de débogage, test), pas une méthode à appeler à chaque requête.

## `RouteCollector` après une erreur

Si `addRoute()` lève une `BadRouteException`, ne réutilisez pas le collecteur : une route déclarée
pour plusieurs méthodes peut avoir été enregistrée en partie. Par exemple, avec une route
`GET /b` existante, `addRoute(['POST', 'GET'], '/b', …)` enregistre `POST /b` dans les données de
dispatch avant d'échouer sur `GET` ; `declaredRoutes()` ne liste pourtant pas cette route,
puisqu'elle n'a pas été déclarée avec succès. Recréez le collecteur.

---

← [Page précédente](attributes.md) · [Sommaire](index.md) · [Page suivante](cache.md) →
