# EduRoute — documentation

[Français](../fr/index.md)

`studoo/edu-route` is an **HTTP router** for PHP 8.4 and 8.5. It maps an HTTP method (`GET`,
`POST`…) and a URL (`/user/42`) to a *handler*: a controller name, an array, a closure, any
value you like. The router tells you **which** handler matches the request and **which**
variables it carries (`['id' => '42']`); running the handler is up to your application.

It is the routing layer of [edu-framework](https://github.com/studoo-app/edu-framework), a
framework built for teaching. The library is a modernized fork of
[nikic/FastRoute](https://github.com/nikic/FastRoute): it keeps FastRoute's combined
regular-expression algorithm (see [How it works](how-it-works.md)) and rewrites the code for
PHP 8.4 with enums, immutable result objects, `#[\Override]` attributes, lazy objects and
static analysis at the strictest level.

## What it does

- declare routes with parameters (`/user/{id:\d+}`), optional segments and groups
  ([Defining routes](routes.md));
- find the route for a request and tell apart "found", "method not allowed" (405) and
  "not found" (404) ([Dispatching a request](dispatching.md));
- build the URL of a named route instead of hard-coding it ([Generating URLs](uri-generation.md));
- declare routes with PHP attributes on your controllers ([Attribute routes](attributes.md));
- list the declared routes, for example in a `routes:list` command ([Listing routes](listing-routes.md));
- cache the routes so they are not rebuilt on every request ([Cache](cache.md));
- route requests in a PSR-7/PSR-15 application ([PSR-15 middleware](middleware.md)).

## Installation

```sh
composer require studoo/edu-route
```

Requirements: PHP 8.4 or 8.5. The `psr/http-message` and `psr/simple-cache` dependencies are
installed automatically. For the [PSR-15 middleware](middleware.md), also install
`psr/http-server-middleware` and `psr/http-factory`.

## Quick start

A complete front controller (`public/index.php`):

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\HttpMethod;

// 1. Declare the routes
$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users', 'list_users');
        // {id} must be a number (\d+)
        $r->get('/user/{id:\d+}', 'show_user');
        // The /{title} suffix is optional
        $r->addRoute(HttpMethod::Get, '/articles/{id:\d+}[/{title}]', 'show_article');
    },
    dirname(__DIR__) . '/var/cache/routes.php', // cache file, created on first use
);

$dispatcher = $router->dispatcher();

// 2. Read the request method and URI: strip the query string (?page=2), then decode
$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}
$uri = rawurldecode($uri);

// 3. Find the matching route
$result = $dispatcher->dispatch($httpMethod, $uri);

// 4. Act on the result
echo match (true) {
    $result instanceof Matched => $result->handler . ' ' . json_encode($result->variables),
    $result instanceof MethodNotAllowed => '405 – allowed methods: ' . implode(', ', $result->allowedMethods),
    $result instanceof NotMatched => '404 – page not found',
}, "\n";
```

Depending on the request, the script prints:

| Request | Output |
|---|---|
| `GET /user/42` | `show_user {"id":"42"}` |
| `GET /articles/7` | `show_article {"id":"7"}` |
| `GET /articles/7/hello%20world?ref=home` | `show_article {"id":"7","title":"hello world"}` |
| `POST /users` | `405 – allowed methods: GET` |
| `GET /user/abc` | `404 – page not found` |

In a real application, the `Matched` branch calls the controller named by `$result->handler`
with `$result->variables`, and the 405 and 404 branches send the matching HTTP status code
(`http_response_code()`, plus an `Allow` header for 405).

> **Note**
> `recommendedSettings()` turns on a file cache. During development, or if your handlers are
> closures, add `->disableCache()`:
> `EduRoute::recommendedSettings($routes, 'unused')->disableCache()`. Remember to delete the
> cache file whenever your routes change. See [Cache](cache.md) for the details.

## Vocabulary

| Term | Meaning |
|---|---|
| **route** | an HTTP method + a URL pattern + a handler |
| **pattern** | the expected URL, with its parameters: `/user/{id:\d+}` |
| **handler** | the value attached to the route, returned as is when the route matches |
| **variables** | the values extracted from the URL: `['id' => '42']` |
| **dispatch** | looking up the route that matches a method and a URI |
| **dispatcher** | the object that performs the lookup (`$router->dispatcher()`) |

## Contents

1. [Introduction](index.md) — this page: installation and quick start
2. [Defining routes](routes.md) — HTTP methods, URL patterns, optional segments, groups, extra parameters
3. [Dispatching a request](dispatching.md) — `dispatch()` and its three results, preparing the URI, `HEAD` and fallback routes
4. [Generating URLs](uri-generation.md) — named routes and the URI generator
5. [Attribute routes](attributes.md) — `#[Route]`, `#[RouteGroup]` and `AttributeRouteLoader`
6. [Listing routes](listing-routes.md) — `declaredRoutes()` and a `routes:list` command
7. [Cache and lazy loading](cache.md) — `FileCache`, PSR-16 cache, invalidation, lazy loading
8. [PSR-15 middleware](middleware.md) — `RoutingMiddleware`, 404 and 405 responses
9. [How it works](how-it-works.md) — parser, data generator, dispatcher and the four strategies
10. [Errors and exceptions](errors.md) — exception reference and how to fix each one
11. [Migration](migration.md) — from `nikic/fast-route` 1.x and 2.0-dev

## Contributing

```sh
make ci        # coding standard, PHPStan (max level), Rector, PHPUnit
make phpbench  # benchmarks of the dispatch strategies
```

The release history is in [CHANGELOG.md](../../CHANGELOG.md).

## Credits and license

Original code © Nikita Popov and the FastRoute contributors; modernization © Studoo.
Released under the BSD-3-Clause license ([LICENSE](../../LICENSE)).

---

[Contents](index.md) · [Next](routes.md) →
