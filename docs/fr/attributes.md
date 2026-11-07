# Routes par attributs

[English](../en/attributes.md)

Plutôt que de lister toutes les routes dans une closure, vous pouvez les déclarer directement sur
les méthodes de vos contrôleurs, avec des [attributs PHP](https://www.php.net/manual/fr/language.attributes.php).
La route est alors écrite juste au-dessus du code qui la traite.

Trois classes sont en jeu :

| Classe | Rôle |
|---|---|
| [`Studoo\EduRoute\Attribute\Route`](../../src/Attribute/Route.php) | attribut de **méthode** : déclare une route (répétable) |
| [`Studoo\EduRoute\Attribute\RouteGroup`](../../src/Attribute/RouteGroup.php) | attribut de **classe** : préfixe commun à toutes les routes du contrôleur |
| [`Studoo\EduRoute\Attribute\AttributeRouteLoader`](../../src/Attribute/AttributeRouteLoader.php) | lit les attributs et enregistre les routes |

Les attributs ne remplacent pas le routeur : `AttributeRouteLoader` se contente d'appeler
`addRoute()` pour vous. Tout ce qui est décrit dans [Définir les routes](routes.md) (motifs,
segments optionnels, paramètres supplémentaires) s'applique donc à l'identique.

## `#[Route]` : déclarer une route

```php
#[Route(string $path, HttpMethod|string|array $methods = HttpMethod::Get, ?string $name = null, array $extra = [])]
```

| Argument | Rôle | Défaut |
|---|---|---|
| `path` | motif de la route (`/user/{id:\d+}`), relatif au préfixe de `#[RouteGroup]` | obligatoire |
| `methods` | une méthode HTTP ou une liste : cas de l'enum `HttpMethod` ou chaîne (`'PATCH'`) | `HttpMethod::Get` |
| `name` | nom de la route, pour la [génération d'URL](uri-generation.md) ; préfixé par `namePrefix` | `null` (route anonyme) |
| `extra` | [paramètres supplémentaires](routes.md), renvoyés dans `Matched::$extraParameters` | `[]` |

L'attribut est **répétable** : une même méthode peut répondre à plusieurs routes.

## `#[RouteGroup]` : préfixe commun

```php
#[RouteGroup(string $prefix, string $namePrefix = '')]
```

| Argument | Rôle |
|---|---|
| `prefix` | ajouté devant le `path` de chaque `#[Route]` du contrôleur |
| `namePrefix` | ajouté devant le `name` de chaque route nommée du contrôleur (les routes sans nom restent anonymes) |

`#[RouteGroup]` se place sur la classe, une seule fois.

## Exemple complet

```php
<?php
declare(strict_types=1);

namespace App\Controller;

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\Attribute\AttributeRouteLoader;
use Studoo\EduRoute\Attribute\Route;
use Studoo\EduRoute\Attribute\RouteGroup;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\HttpMethod;

final class HomeController
{
    #[Route('/', name: 'home')]
    public function index(): string
    {
        return 'Accueil';
    }
}

#[RouteGroup('/admin/product', namePrefix: 'admin.product.')]
final class ProductController
{
    #[Route('', name: 'index')]                         // GET /admin/product
    public function index(): string
    {
        return 'Liste des produits';
    }

    #[Route('/{id:\d+}', methods: [HttpMethod::Put, 'PATCH'], name: 'update')]
    #[Route('/{id:\d+}/edit', methods: HttpMethod::Post, extra: ['csrf' => true])]
    public function update(string $id): string
    {
        return "Produit $id mis à jour";
    }
}

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        // Les contrôleurs sont lus dans l'ordre donné
        AttributeRouteLoader::register($r, HomeController::class, ProductController::class);
    },
    __DIR__ . '/var/cache/routes.php',
);

$result = $router->dispatcher()->dispatch('POST', '/admin/product/42/edit');

if ($result instanceof Matched) {
    echo $result->handler, PHP_EOL;                 // App\Controller\ProductController::update
    var_export($result->variables);                  // array ('id' => '42')
    echo PHP_EOL;
    var_export($result->extraParameters['csrf']);    // true
    echo PHP_EOL;

    // Le handler est une chaîne "Classe::méthode" : à vous d'instancier le contrôleur
    [$class, $method] = explode('::', $result->handler);
    echo (new $class())->$method(...$result->variables), PHP_EOL; // Produit 42 mis à jour
}

$uris = $router->uriGenerator();
echo $uris->forRoute('admin.product.update', ['id' => '7']), PHP_EOL; // /admin/product/7
echo $uris->forRoute('admin.product.index'), PHP_EOL;                 // /admin/product
```

Ce code enregistre exactement les mêmes routes que :

```php
$r->get('/', 'App\Controller\HomeController::index', [ConfigureRoutes::ROUTE_NAME => 'home']);
$r->get('/admin/product', 'App\Controller\ProductController::index', [ConfigureRoutes::ROUTE_NAME => 'admin.product.index']);
$r->addRoute([HttpMethod::Put, 'PATCH'], '/admin/product/{id:\d+}', 'App\Controller\ProductController::update', [ConfigureRoutes::ROUTE_NAME => 'admin.product.update']);
$r->post('/admin/product/{id:\d+}/edit', 'App\Controller\ProductController::update', ['csrf' => true]);
```

## Le handler : la chaîne `"Classe::méthode"`

Le *handler* de chaque route est le nom complet de la classe, suivi de `::` et du nom de la
méthode : `'App\Controller\ProductController::update'`. Ce choix a deux conséquences :

- la chaîne est **exportable** : les routes par attributs fonctionnent avec le
  [cache](cache.md), sans `disableCache()` ;
- le routeur n'instancie rien. C'est à votre code (ou à votre framework, ou à un conteneur
  d'injection de dépendances) de créer le contrôleur et d'appeler la méthode, comme dans
  l'exemple ci-dessus.

Pour un contrôleur invocable, placez `#[Route]` sur `__invoke()` : le handler vaut alors
`'App\Controller\ShowUser::__invoke'`.

## Mélanger attributs et routes classiques

`AttributeRouteLoader::register()` reçoit l'objet `ConfigureRoutes` de votre fonction de
définition. Vous pouvez donc combiner les deux styles, et même appeler `register()` dans un
`addGroup()` : le préfixe du groupe s'ajoute alors devant celui de `#[RouteGroup]`.

```php
<?php
declare(strict_types=1);

namespace App\Controller;

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\Attribute\AttributeRouteLoader;
use Studoo\EduRoute\Attribute\Route;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

final class ApiController
{
    #[Route('/status', name: 'status')]
    public function status(): string
    {
        return 'ok';
    }
}

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/', 'App\Controller\HomeController::index');         // route classique
        $r->addGroup('/api/v1', static function (ConfigureRoutes $r): void {
            AttributeRouteLoader::register($r, ApiController::class);  // préfixée par /api/v1
        });
    },
    __DIR__ . '/var/cache/routes.php',
);

foreach ($router->declaredRoutes() as $route) {
    echo $route->method, ' ', $route->pattern, ' ', $route->describeHandler(), PHP_EOL;
}
// GET / App\Controller\HomeController::index
// GET /api/v1/status App\Controller\ApiController::status

echo $router->uriGenerator()->forRoute('status'), PHP_EOL; // /api/v1/status
```

`declaredRoutes()` est présentée dans [Lister les routes](listing-routes.md).

## Ordre d'enregistrement

L'ordre compte : comme avec `addRoute()`, une route statique déclarée **après** une route
variable qui la masque est refusée. `AttributeRouteLoader` enregistre les routes dans cet ordre :

1. les contrôleurs, dans l'ordre des arguments de `register()` ;
2. dans chaque contrôleur, ses propres méthodes, dans l'ordre du fichier ;
3. puis les méthodes de ses traits ;
4. pour une méthode qui porte plusieurs `#[Route]`, les attributs de haut en bas.

Exemple d'erreur due à l'ordre :

```php
<?php
declare(strict_types=1);

namespace App\Controller;

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\Attribute\AttributeRouteLoader;
use Studoo\EduRoute\Attribute\Route;
use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

final class UserController
{
    #[Route('/user/{name}')]
    public function show(string $name): string
    {
        return "Profil de $name";
    }

    #[Route('/user/me')]       // masquée par /user/{name}, déclarée juste avant
    public function me(): string
    {
        return 'Mon profil';
    }
}

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        AttributeRouteLoader::register($r, UserController::class);
    },
    __DIR__ . '/var/cache/routes.php',
);

try {
    $router->dispatcher();
} catch (BadRouteException $e) {
    echo $e->getMessage(), PHP_EOL;
}
```

```text
Static route "/user/me" is shadowed by previously defined variable route "/user/([^/]+)" for method "GET" (route déclarée sur App\Controller\UserController::me())
```

Pour corriger, placez la méthode `me()` **avant** `show()` dans le fichier.

> **Note**
> Les erreurs levées par `addRoute()` (route en double, route masquée, nom déjà utilisé…) sont
> reprises par `AttributeRouteLoader` avec le suffixe `(route déclarée sur Classe::méthode())`,
> pour vous indiquer directement la méthode en cause. L'exception d'origine reste accessible
> avec `$e->getPrevious()`.

## Règles vérifiées

`AttributeRouteLoader` vérifie chaque contrôleur et chaque méthode au moment de l'enregistrement.
Une règle non respectée lève une [`BadRouteException`](errors.md) dont le message (en français)
nomme la classe en cause et, le cas échéant, la méthode.

| Règle | Message (exemple) |
|---|---|
| le contrôleur existe | `Contrôleur "App\Missing" introuvable` |
| c'est une classe, pas une interface, un trait ou une enum | `"App\Controller\Payable" est une interface, pas un contrôleur instanciable` (ou `un trait`, `une enum`) |
| la classe est instanciable : ni abstraite, ni avec un constructeur non public | `Le contrôleur App\Controller\BaseController n'est pas instanciable (classe abstraite ou constructeur non public)` |
| `#[Route]` est sur une méthode, pas sur la classe | `#[Route] se place sur une méthode, pas sur la classe App\Controller\HomeController : utilisez #[RouteGroup] pour un préfixe commun` |
| `#[RouteGroup]` est sur la classe, pas sur une méthode | `#[RouteGroup] se place sur la classe, pas sur la méthode App\Controller\HomeController::index()` |
| la méthode routée est publique, non statique, non magique (sauf `__invoke`) et déclarée dans le contrôleur lui-même ou dans un de ses traits | `La méthode App\Controller\HomeController::index() porte #[Route] mais doit être publique, non statique, non magique et déclarée dans le contrôleur App\Controller\HomeController` |
| `methods` n'est pas une liste vide | `#[Route] sans méthode HTTP sur App\Controller\HomeController::index()` |
| `name` n'est pas une chaîne vide | `Nom de route vide sur App\Controller\HomeController::index()` |

Quelques précisions :

- **Héritage.** Une méthode routée héritée d'une classe parente est refusée : le message nomme
  la classe parente (`La méthode App\Controller\BaseController::index() porte #[Route] …`).
  Pour réutiliser la route, redéclarez la méthode dans le contrôleur enfant et placez-y
  `#[Route]`.
- **Traits.** Les méthodes d'un trait utilisé par le contrôleur sont acceptées. Leur handler
  porte le nom du contrôleur, pas celui du trait (`'App\Controller\UserController::fromTrait'`).
- **`#[RouteGroup]` n'est pas hérité** : il ne s'applique qu'à la classe qui le porte. Un
  contrôleur enfant d'une classe portant `#[RouteGroup('/admin')]` n'a pas de préfixe.
- Les méthodes sans `#[Route]` sont ignorées, quelle que soit leur visibilité.

## Attributs et cache

Avec `recommendedSettings()`, les routes sont mises en cache dans un fichier. Le routeur ne
surveille pas vos contrôleurs : après avoir ajouté, modifié ou supprimé un `#[Route]`, **supprimez
le fichier de cache** (ou désactivez le cache en développement avec `disableCache()`). Voir
[Cache](cache.md).

> **Attention**
> Lire les attributs passe par l'API de réflexion de PHP. C'est rapide, mais inutile à chaque
> requête : en production, gardez le cache activé pour que `AttributeRouteLoader` ne soit
> appelé qu'une fois.

---

← [Page précédente](uri-generation.md) · [Sommaire](index.md) · [Page suivante](listing-routes.md) →
