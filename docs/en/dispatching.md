# Dispatching a request

[Français](../fr/dispatching.md)

Once your routes are declared (see [Defining routes](routes.md)), the **dispatcher** answers a
single question: which route matches this HTTP method and this URI? It does not run the route. It
hands you back an object describing what it found, and it is up to your application to call the
controller, or to respond with a 404 or a 405.

## Getting a dispatcher

```php
$dispatcher = $router->dispatcher();     // routes are loaded right away
$dispatcher = $router->lazyDispatcher(); // routes are loaded on the first dispatch()
```

`$router` is the object built by `EduRoute::recommendedSettings()`. Both methods return a
[`Dispatcher`](../../src/Dispatcher.php); the lazy variant is covered in [Cache](cache.md). If you
build the `RouteCollector` yourself, create the dispatcher from the same family as the data
generator:

```php
$dispatcher = new \Studoo\EduRoute\Dispatcher\MarkBased($collector->processedRoutes());
```

## Calling `dispatch()`

```php
public function dispatch(HttpMethod|string $httpMethod, string $uri): Matched|NotMatched|MethodNotAllowed;
```

- `$httpMethod`: a case of the `HttpMethod` enum (`HttpMethod::Get`) or a string (`'GET'`,
  `'PURGE'`...). The comparison is **case-sensitive**: `'get'` does not find a route declared for
  `GET`. `$_SERVER['REQUEST_METHOD']` is already uppercase.
- `$uri`: the **path** of the URL, without the query string, already decoded (see
  [Preparing the URI](#preparing-the-uri)).

The result is one of these three objects, all in the `Studoo\EduRoute\Dispatcher\Result`
namespace:

| Object | Meaning | Properties |
|---|---|---|
| [`Matched`](../../src/Dispatcher/Result/Matched.php) | a route matches | `handler`, `variables`, `extraParameters` |
| [`MethodNotAllowed`](../../src/Dispatcher/Result/MethodNotAllowed.php) | the URI exists, but not for this method: respond with **405** | `allowedMethods` |
| [`NotMatched`](../../src/Dispatcher/Result/NotMatched.php) | no route at all: respond with **404** | none |

Check the result with `instanceof`, for instance inside a `match (true)`:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users', 'list_users');
        $r->post('/users', 'create_user');
        $r->get('/users/{id:\d+}', 'show_user', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
    },
    'unused',
)->disableCache()->dispatcher();

$requests = [
    ['GET', '/users/42'],
    ['DELETE', '/users'],
    ['GET', '/contact'],
];

foreach ($requests as [$method, $uri]) {
    $result = $dispatcher->dispatch($method, $uri);

    echo "$method $uri → ", match (true) {
        $result instanceof Matched => sprintf(
            '%s %s %s',
            $result->handler,
            json_encode($result->variables),
            json_encode($result->extraParameters, JSON_UNESCAPED_SLASHES),
        ),
        $result instanceof MethodNotAllowed => '405, Allow: ' . implode(', ', $result->allowedMethods),
        $result instanceof NotMatched => '404',
    }, "\n";
}
```

```text
GET /users/42 → show_user {"id":"42"} {"_route":"/users/{id:\\d+}","_name":"user.show"}
DELETE /users → 405, Allow: GET, POST
GET /contact → 404
```

### `Matched`

| Property | Type | Content |
|---|---|---|
| `handler` | `mixed` | the *handler* declared with the route, as is |
| `variables` | `array<string, string>` | the URL parameters: `/users/{id:\d+}` on `/users/42` gives `['id' => '42']` |
| `extraParameters` | `array<string, string\|int\|bool\|float>` | the route's extra parameters (see [Defining routes](routes.md)) |

The values in `variables` are **always strings**: `'42'`, not `42`. Convert them yourself
(`(int) $result->variables['id']`). An optional segment missing from the URL does not appear in
`variables` at all: check it with `isset()` or `??`.

`extraParameters` always contains the `'_route'` key (constant `ConfigureRoutes::ROUTE_REGEX`):
the original pattern of the route, group prefix included. A named route also adds `'_name'`
(`ConfigureRoutes::ROUTE_NAME`). Both keys are reserved.

### `MethodNotAllowed`

`allowedMethods` is a non-empty list of the methods that do have a route for this URI. Send it
back in the `Allow` header of the 405 response (the [PSR-15 middleware](middleware.md) does it for
you). See [Computing the allowed methods](#computing-the-allowed-methods) for its quirks.

### `NotMatched`

No properties: no route matches the URI, whatever the method.

### Immutable results

All three classes are `readonly`. Once the dispatcher has created the result, any change is
rejected:

```php
$result->handler = 'other';  // Error: Cannot modify readonly property Studoo\EduRoute\Dispatcher\Result\Matched::$handler
$result[0] = 'other';        // RuntimeException: Result cannot be changed
```

## Preparing the URI

The dispatcher compares the URI **exactly** against the route patterns. It does no cleaning up
whatsoever: it is your job to pass it the bare, decoded path.

1. **Strip the query string**: `/users?page=2` does not match the `/users` route (the result is
   `NotMatched`). Query string parameters are still available in `$_GET`.
2. **Decode the URI** with `rawurldecode()`: otherwise `/hello/Jean%20Dupont` yields the variable
   `'Jean%20Dupont'` instead of `'Jean Dupont'`.
3. **Replace an empty path with `/`**: `dispatch('GET', '')` never finds the `/` route.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/hello/{name}', 'hello');
    },
    'unused',
)->disableCache()->dispatcher();

// What PHP gives you for the request GET /hello/Jean%20Dupont?lang=fr
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/hello/Jean%20Dupont?lang=fr';

// 1. Strip the query string; 2. decode escaped characters (%20...)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rawurldecode(is_string($path) && $path !== '' ? $path : '/');

$result = $dispatcher->dispatch($_SERVER['REQUEST_METHOD'], $uri);

var_dump($result->variables['name']); // the query string is still readable from $_GET
```

```text
string(11) "Jean Dupont"
```

> **Note**
> The trailing slash matters: `/users/` does not match the `/users` route. Pick one convention
> and, if needed, redirect the other form in your application.

## Matching order

For a given method and URI, the dispatcher tries the following, in this order, and stops at the
first hit:

1. the **static** routes (no parameters) of the requested method: a plain array lookup;
2. the **variable** routes of the requested method, **in declaration order**: the first one that
   matches wins;
3. for a `HEAD` request only: the static, then variable, routes of `GET`;
4. the **fallback** routes `'*'` (declared with `any()` or `HttpMethod::Any`), static then
   variable;
5. if nothing matched: the other methods are tried in order to build `MethodNotAllowed`; if none
   of them matches either, the result is `NotMatched`.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\HttpMethod;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/articles/new', 'new_article_form');
        $r->get('/articles/{id:\d+}', 'show_article_by_id');
        $r->get('/articles/{slug}', 'show_article');
        $r->any('/maintenance', 'maintenance_page');
        $r->any('/{page}', 'cms_page');
    },
    'unused',
)->disableCache()->dispatcher();

$requests = [
    [HttpMethod::Get, '/articles/new'],
    [HttpMethod::Get, '/articles/42'],
    [HttpMethod::Head, '/articles/hello'],
    [HttpMethod::Post, '/maintenance'],
    [HttpMethod::Delete, '/about'],
    ['get', '/articles/hello'],
];

foreach ($requests as [$method, $uri]) {
    $result = $dispatcher->dispatch($method, $uri);
    $label = $method instanceof HttpMethod ? $method->value : $method;

    echo str_pad("$label $uri", 22), '→ ',
        $result instanceof Matched ? $result->handler : substr(strrchr($result::class, '\\'), 1), "\n";
}
```

```text
GET /articles/new     → new_article_form
GET /articles/42      → show_article_by_id
HEAD /articles/hello  → show_article
POST /maintenance     → maintenance_page
DELETE /about         → cms_page
get /articles/hello   → MethodNotAllowed
```

Key takeaways:

- `/articles/42` matches both variable routes; `{id:\d+}` wins because it is declared **before**
  `{slug}`. Declare the most specific patterns first.
- The static route `/articles/new` must be declared before `/articles/{slug}`: the other way
  round, it would be shadowed, and registering it throws a `BadRouteException`
  (`Static route "/articles/new" is shadowed by previously defined variable route "/articles/([^/]+)" for method "GET"`).
  See [Errors](errors.md).
- Lowercase `'get'` is not `GET`: the dispatcher only finds the `GET` route during step 5, hence
  `MethodNotAllowed`.

### `HEAD` falls back to `GET`

A `HEAD` request asks for a page's headers without its body. If no `HEAD` route matches, the
dispatcher tries the `GET` routes (step 3), so you do not have to declare `HEAD` next to every
`GET`. An explicitly declared `HEAD` route still takes precedence.

The result is a regular `Matched`, carrying the `GET` route's *handler*. It is up to your
application (or your web server) not to send a response body for `HEAD`.

### Fallback routes `'*'`

A route declared with `any()` (method `HttpMethod::Any`, i.e. `'*'`) matches **any method that did
not find a dedicated route**. It comes after the routes of the requested method and after the
`HEAD` → `GET` fallback: in the example, `HEAD /articles/hello` finds the `GET` route before the
`'*'` route `/{page}`.

Since a fallback route matches every method, a URI covered by a `'*'` route never yields
`MethodNotAllowed`.

### Computing the allowed methods

When no route matches, the dispatcher tests the URI against the routes of **every other method**
declared, static first, then variable. Two quirks, inherited from FastRoute:

- `allowedMethods` does not include `HEAD` when only `GET` exists, even though `HEAD` is served
  through the fallback. The [PSR-15 middleware](middleware.md) adds it to the `Allow` header; do
  the same if you write the 405 response yourself.
- A method can show up **twice** if the URI matches both a static and a variable route of that
  method. Remove duplicates before writing the header:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/files/readme', 'readme');
        $r->get('/files/{name}', 'download');
        $r->delete('/files/{name}', 'remove');
    },
    'unused',
)->disableCache()->dispatcher();

$result = $dispatcher->dispatch('POST', '/files/readme');

var_dump($result->allowedMethods);
var_dump(array_values(array_unique($result->allowedMethods)));
```

```text
array(3) {
  [0]=>
  string(3) "GET"
  [1]=>
  string(3) "GET"
  [2]=>
  string(6) "DELETE"
}
array(2) {
  [0]=>
  string(3) "GET"
  [1]=>
  string(6) "DELETE"
}
```

## When the regex engine fails

Variable routes are tested with regular expressions (PCRE). A very expensive parameter regex,
such as `{p:(?:a?a?)*}`, can exhaust the `pcre.backtrack_limit` or the JIT stack. Instead of
answering "no route" (and therefore a 404), `dispatch()` then throws
[`RegexMatchingFailed`](../../src/RegexMatchingFailed.php), with the URI and the PCRE error. The
message is in French:

```text
Impossible de chercher la route de l'URI "/p/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaab" : l'expression régulière des routes a échoué (Backtrack limit exhausted). Un paramètre de route utilise sans doute un motif trop coûteux, par exemple {p:(?:a?a?)*} : simplifiez-le, ou augmentez pcre.backtrack_limit / pcre.jit_stacklimit.
```

In English: the route for this URI could not be looked up because the routes' regular expression
failed (here, backtrack limit exhausted); a route parameter probably uses a pattern that is too
expensive, such as `{p:(?:a?a?)*}`: simplify it, or raise `pcre.backtrack_limit` /
`pcre.jit_stacklimit`.

The fix is almost always to simplify the pattern. See [Errors](errors.md).

## Array access (deprecated)

In FastRoute, `dispatch()` returned an array. To ease migration, the three result objects still
support reading by index, compared against the `Dispatcher` constants:

| Result | `[0]` | `[1]` | `[2]` |
|---|---|---|---|
| `Matched` | `Dispatcher::FOUND` (`1`) | `handler` | `variables` |
| `MethodNotAllowed` | `Dispatcher::METHOD_NOT_ALLOWED` (`2`) | `allowedMethods` | |
| `NotMatched` | `Dispatcher::NOT_FOUND` (`0`) | | |

Any other index throws an `OutOfBoundsException`. `extraParameters` is not reachable by index.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users/{id:\d+}', 'show_user');
    },
    'unused',
)->disableCache()->dispatcher();

$routeInfo = $dispatcher->dispatch('GET', '/users/42');

// Old FastRoute style: it works, but every read triggers a deprecation
if ($routeInfo[0] === Dispatcher::FOUND) {
    echo $routeInfo[1], ' ', json_encode($routeInfo[2]), "\n";
}
```

The script prints `show_user {"id":"42"}`, and every read by index triggers a deprecation
(`E_USER_DEPRECATED`):

```text
Deprecated: Method Studoo\EduRoute\Dispatcher\Result\Matched::offsetGet() is deprecated since 1.0, use $result instanceof Matched and its properties instead
```

> **Warning**
> Array access and the `Dispatcher::FOUND`, `NOT_FOUND` and `METHOD_NOT_ALLOWED` constants have
> been deprecated since 1.0. Replace them with `instanceof` and the properties: see
> [Migration](migration.md).

---

← [Previous](routes.md) · [Contents](index.md) · [Next](uri-generation.md) →
