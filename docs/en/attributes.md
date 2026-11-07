# Attribute routes

[Français](../fr/attributes.md)

Instead of listing every route in a closure, you can declare them right on your controller
methods using [PHP attributes](https://www.php.net/manual/en/language.attributes.php). Each
route then sits directly above the code that handles it.

Three classes are involved:

| Class | Role |
|---|---|
| [`Studoo\EduRoute\Attribute\Route`](../../src/Attribute/Route.php) | **method** attribute: declares a route (repeatable) |
| [`Studoo\EduRoute\Attribute\RouteGroup`](../../src/Attribute/RouteGroup.php) | **class** attribute: a prefix shared by every route of the controller |
| [`Studoo\EduRoute\Attribute\AttributeRouteLoader`](../../src/Attribute/AttributeRouteLoader.php) | reads the attributes and registers the routes |

Attributes do not replace the router: `AttributeRouteLoader` simply calls `addRoute()` on your
behalf. Everything described in [Defining routes](routes.md) (patterns, optional segments, extra
parameters) applies unchanged.

## `#[Route]`: declaring a route

```php
#[Route(string $path, HttpMethod|string|array $methods = HttpMethod::Get, ?string $name = null, array $extra = [])]
```

| Argument | Purpose | Default |
|---|---|---|
| `path` | route pattern (`/user/{id:\d+}`), relative to the `#[RouteGroup]` prefix | required |
| `methods` | one HTTP method or a list: `HttpMethod` enum cases or strings (`'PATCH'`) | `HttpMethod::Get` |
| `name` | route name, used for [URI generation](uri-generation.md); prefixed by `namePrefix` | `null` (unnamed route) |
| `extra` | [extra parameters](routes.md), returned in `Matched::$extraParameters` | `[]` |

The attribute is **repeatable**: a single method can answer several routes.

## `#[RouteGroup]`: a shared prefix

```php
#[RouteGroup(string $prefix, string $namePrefix = '')]
```

| Argument | Purpose |
|---|---|
| `prefix` | prepended to the `path` of every `#[Route]` in the controller |
| `namePrefix` | prepended to the `name` of every named route in the controller (unnamed routes stay unnamed) |

`#[RouteGroup]` goes on the class, once.

## Full example

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
        return 'Home';
    }
}

#[RouteGroup('/admin/product', namePrefix: 'admin.product.')]
final class ProductController
{
    #[Route('', name: 'index')]                         // GET /admin/product
    public function index(): string
    {
        return 'Product list';
    }

    #[Route('/{id:\d+}', methods: [HttpMethod::Put, 'PATCH'], name: 'update')]
    #[Route('/{id:\d+}/edit', methods: HttpMethod::Post, extra: ['csrf' => true])]
    public function update(string $id): string
    {
        return "Product $id updated";
    }
}

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        // Controllers are read in the order given
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

    // The handler is a "Class::method" string: instantiating the controller is up to you
    [$class, $method] = explode('::', $result->handler);
    echo (new $class())->$method(...$result->variables), PHP_EOL; // Product 42 updated
}

$uris = $router->uriGenerator();
echo $uris->forRoute('admin.product.update', ['id' => '7']), PHP_EOL; // /admin/product/7
echo $uris->forRoute('admin.product.index'), PHP_EOL;                 // /admin/product
```

This registers exactly the same routes as:

```php
$r->get('/', 'App\Controller\HomeController::index', [ConfigureRoutes::ROUTE_NAME => 'home']);
$r->get('/admin/product', 'App\Controller\ProductController::index', [ConfigureRoutes::ROUTE_NAME => 'admin.product.index']);
$r->addRoute([HttpMethod::Put, 'PATCH'], '/admin/product/{id:\d+}', 'App\Controller\ProductController::update', [ConfigureRoutes::ROUTE_NAME => 'admin.product.update']);
$r->post('/admin/product/{id:\d+}/edit', 'App\Controller\ProductController::update', ['csrf' => true]);
```

## The handler: a `"Class::method"` string

Each route's *handler* is the fully qualified class name, followed by `::` and the method name:
`'App\Controller\ProductController::update'`. Two things follow from this:

- the string is **exportable**, so attribute routes work with the [cache](cache.md) without
  `disableCache()`;
- the router never instantiates anything. Creating the controller and calling the method is the
  job of your code (or your framework, or a dependency injection container), as in the example
  above.

For an invokable controller, put `#[Route]` on `__invoke()`: the handler is then
`'App\Controller\ShowUser::__invoke'`.

## Mixing attributes and regular routes

`AttributeRouteLoader::register()` takes the `ConfigureRoutes` object of your route definition
function. You can therefore combine both styles, and even call `register()` inside an
`addGroup()`: the group prefix is then added in front of the `#[RouteGroup]` prefix.

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
        $r->get('/', 'App\Controller\HomeController::index');         // regular route
        $r->addGroup('/api/v1', static function (ConfigureRoutes $r): void {
            AttributeRouteLoader::register($r, ApiController::class);  // prefixed with /api/v1
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

`declaredRoutes()` is covered in [Listing routes](listing-routes.md).

## Registration order

Order matters: just like with `addRoute()`, a static route declared **after** a variable route
that shadows it is rejected. `AttributeRouteLoader` registers routes in this order:

1. controllers, in the order of the `register()` arguments;
2. within each controller, its own methods, in file order;
3. then the methods of its traits;
4. for a method carrying several `#[Route]` attributes, top to bottom.

An error caused by ordering:

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
        return "$name's profile";
    }

    #[Route('/user/me')]       // shadowed by /user/{name}, declared just before
    public function me(): string
    {
        return 'My profile';
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

To fix it, move the `me()` method **above** `show()` in the file.

> **Note**
> Errors thrown by `addRoute()` (duplicate route, shadowed route, name already in use...) are
> rethrown by `AttributeRouteLoader` with the suffix `(route déclarée sur Class::method())`
> ("route declared on Class::method()"), so you can see straight away which method is at fault.
> The original exception is still available through `$e->getPrevious()`.

## Checked rules

`AttributeRouteLoader` checks every controller and every method at registration time. A broken
rule throws a [`BadRouteException`](errors.md) whose message names the class at fault and, where relevant, the method.
These messages are in French; the table gives their meaning in English.

| Rule | Message (example) | Meaning |
|---|---|---|
| the controller exists | `Contrôleur "App\Missing" introuvable` | controller not found |
| it is a class, not an interface, trait or enum | `"App\Controller\Payable" est une interface, pas un contrôleur instanciable` (or `un trait`, `une enum`) | "... is an interface, not an instantiable controller" |
| the class is instantiable: not abstract, no non-public constructor | `Le contrôleur App\Controller\BaseController n'est pas instanciable (classe abstraite ou constructeur non public)` | controller is not instantiable (abstract class or non-public constructor) |
| `#[Route]` is on a method, not on the class | `#[Route] se place sur une méthode, pas sur la classe App\Controller\HomeController : utilisez #[RouteGroup] pour un préfixe commun` | `#[Route]` belongs on a method; use `#[RouteGroup]` for a shared prefix |
| `#[RouteGroup]` is on the class, not on a method | `#[RouteGroup] se place sur la classe, pas sur la méthode App\Controller\HomeController::index()` | `#[RouteGroup]` belongs on the class |
| the routed method is public, non-static, non-magic (except `__invoke`) and declared in the controller itself or in one of its traits | `La méthode App\Controller\HomeController::index() porte #[Route] mais doit être publique, non statique, non magique et déclarée dans le contrôleur App\Controller\HomeController` | the method has `#[Route]` but must be public, non-static, non-magic and declared in the controller |
| `methods` is not an empty list | `#[Route] sans méthode HTTP sur App\Controller\HomeController::index()` | `#[Route]` with no HTTP method |
| `name` is not an empty string | `Nom de route vide sur App\Controller\HomeController::index()` | empty route name |

A few details:

- **Inheritance.** A routed method inherited from a parent class is rejected; the message names
  the parent class (`La méthode App\Controller\BaseController::index() porte #[Route] ...`). To
  reuse the route, redeclare the method in the child controller and put `#[Route]` there.
- **Traits.** Methods from a trait used by the controller are accepted. Their handler carries the
  controller's name, not the trait's (`'App\Controller\UserController::fromTrait'`).
- **`#[RouteGroup]` is not inherited**: it only applies to the class that carries it. A child of a
  class marked `#[RouteGroup('/admin')]` gets no prefix.
- Methods without `#[Route]` are ignored, whatever their visibility.

## Attributes and the cache

With `recommendedSettings()`, routes are cached in a file. The router does not watch your
controllers: after adding, changing or removing a `#[Route]`, **delete the cache file** (or turn
the cache off during development with `disableCache()`). See [Cache](cache.md).

> **Warning**
> Reading attributes goes through PHP's reflection API. It is fast, but pointless on every
> request: keep the cache on in production so that `AttributeRouteLoader` only runs once.

---

← [Previous](uri-generation.md) · [Contents](index.md) · [Next](listing-routes.md) →
