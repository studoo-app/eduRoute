# Listing routes

[Français](../fr/listing-routes.md)

As an application grows, it becomes handy to display all of its routes at once: to check a group
prefix, find which controller handles a URL, or spot a forgotten route. That is what a
`routes:list` command does, and most frameworks ship one.

eduRoute provides the data you need through the `declaredRoutes()` method. How you display it
(table, colors, JSON...) is up to you.

## `declaredRoutes()`

The method exists in two places and returns the same result:

| Call | When to use it |
|---|---|
| [`EduRoute::declaredRoutes()`](../../src/EduRoute.php) | you use the `EduRoute` facade (`recommendedSettings()`...) |
| [`RouteCollector::declaredRoutes()`](../../src/RouteCollector.php) | you build the `RouteCollector` yourself (as edu-framework does) |

It returns a list (`list<DeclaredRoute>`) of the routes that were **successfully declared**, in
**declaration order**.

## The `DeclaredRoute` object

Each item is an immutable [`Studoo\EduRoute\DeclaredRoute`](../../src/DeclaredRoute.php) object.
You never build one yourself: the router creates it.

| Property | Type | Content |
|---|---|---|
| `method` | `string` | `'GET'`, `'POST'`...; a non-standard method as is; `'*'` for a fallback route |
| `pattern` | `string` | the original pattern, **including the group prefix**, with optional segments kept together (`/articles/{year}[/{month}]`) |
| `handler` | `mixed` | the raw handler, exactly as you passed it to `addRoute()` |
| `name` | `?string` | the route name (`ConfigureRoutes::ROUTE_NAME`), `null` for an unnamed route |
| `extraParameters` | `array` | extra parameters, **without** the reserved `_route` and `_name` keys |

And two methods:

| Method | Returns |
|---|---|
| `isFallback(): bool` | `true` for a fallback route, declared with `any()` or `HttpMethod::Any` (`method` is `'*'`) |
| `describeHandler(): string` | a human-readable description of the handler, for display (see below) |

## `describeHandler()`: displaying a handler

A handler can be any value: a string, an array, a closure, an object... A plain
`echo $route->handler` would raise an error (`Array to string conversion`) or print something
unreadable. `describeHandler()` always produces a string:

| Handler | Returned text |
|---|---|
| string `'App\Controller\UserController::show'` | the string as is |
| array `['App\Controller\UserController', 'show']` | `App\Controller\UserController::show` |
| array `[$object, 'show']` | `<object class>::show` |
| invokable object (has an `__invoke` method) | `<class>::__invoke` |
| closure | `Closure (<file>:<line>)`: the file name (without its directory) and the starting line; just `Closure` if PHP does not know the file |
| any other value | its type, as given by `get_debug_type()` (`int`, `null`, `array`, `stdClass`...) |

The raw value is still available in `$route->handler`.

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
        return "User $id";
    }
}

final class UserController
{
    public function list(): string
    {
        return 'Users';
    }
}

$collector = new RouteCollector(new Std(), new MarkBased());

$collector->get('/hello', static fn (): string => 'Hello');               // closure
$collector->get('/users', [new UserController(), 'list']);                 // [object, method]
$collector->get('/users/{id:\d+}', new ShowUser(), [ConfigureRoutes::ROUTE_NAME => 'user.show', 'cache' => 60]);
$collector->get('/ping', 42);                                              // any value at all

foreach ($collector->declaredRoutes() as $route) {
    echo $route->pattern, ' => ', $route->describeHandler(), PHP_EOL;
}

$route = $collector->declaredRoutes()[2];
var_export($route->name);                                // 'user.show'
echo PHP_EOL;
var_export($route->extraParameters);                     // array ('cache' => 60): no _route or _name
echo PHP_EOL;
var_export($route->handler instanceof ShowUser);         // true
echo PHP_EOL;
```

Output, if the file is named `list.php`:

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
> Closures and objects work here because the `RouteCollector` has no cache. With `EduRoute` and a
> cache, only exportable handlers are accepted when dispatching (see [Cache](cache.md));
> `declaredRoutes()`, on the other hand, always accepts them (see below).

## Example: a `routes:list` command

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
        $route->isFallback() ? 'ANY' : $route->method,  // '*' shown as ANY
        $route->pattern,
        $route->describeHandler(),                      // printable text, even for an array
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

With [attribute routes](attributes.md), the command is the same: every handler is a
`"Class::method"` string.

## Good to know

- **One entry per method.** `addRoute(['GET', 'POST'], ...)` produces two consecutive entries.
- **HEAD is not listed.** The router answers `HEAD` requests with `GET` routes (see
  [Dispatching a request](dispatching.md)); that implicit behavior does not show up in the list.
  Only a route explicitly declared for `HEAD` appears.
- **`'*'` means a fallback route** (`any()`), hence the `isFallback()` check to display it
  differently.
- **Optional segments are not expanded**: `/articles/{year}[/{month}]` stays a single entry,
  exactly as declared.
- **Order is declaration order.** To sort, for instance by pattern:

  ```php
  $routes = $router->declaredRoutes();
  usort($routes, static fn (DeclaredRoute $a, DeclaredRoute $b): int => strcmp($a->pattern, $b->pattern));
  ```

  (with `use Studoo\EduRoute\DeclaredRoute;`).

## `EduRoute::declaredRoutes()` and the cache

`$router->declaredRoutes()` **runs your route definition function again on every call**, on a
fresh collector, without reading or writing the cache. As a result:

- the list always reflects the current code, even when the cache file is stale;
- non-exportable handlers (closures, objects) are accepted, even with the cache enabled;
- definition errors (duplicate route, shadowed route...) are thrown just as they would be when
  dispatching, as a [`BadRouteException`](errors.md).

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\Cache\CannotCacheRoutes;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/hello', static fn (): string => 'Hello');     // closure: not exportable
    },
    __DIR__ . '/var/cache/routes.php',
);

echo count($router->declaredRoutes()), PHP_EOL;                // 1: listing works

try {
    $router->dispatcher();                                      // but the cache rejects the closure
} catch (CannotCacheRoutes $e) {
    echo $e::class, PHP_EOL;                                    // Studoo\EduRoute\Cache\CannotCacheRoutes
}
```

> **Warning**
> Since it rebuilds every route, `declaredRoutes()` is a development tool (CLI command, debug
> page, test), not something to call on every request.

## `RouteCollector` after an error

If `addRoute()` throws a `BadRouteException`, do not keep using the collector: a route declared
for several methods may have been partly registered. For example, with an existing `GET /b`
route, `addRoute(['POST', 'GET'], '/b', ...)` adds `POST /b` to the dispatch data before failing
on `GET`; yet `declaredRoutes()` does not list that route, since it was not declared
successfully. Create a new collector instead.

---

← [Previous](attributes.md) · [Contents](index.md) · [Next](cache.md) →
