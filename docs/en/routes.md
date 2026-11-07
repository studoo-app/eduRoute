# Defining routes

[Français](../fr/routes.md)

A **route** maps one or more HTTP methods and a URL pattern to a *handler*. This page covers
everything you need to declare routes: `addRoute()` and its shortcuts, HTTP methods, URL
parameters, optional segments, groups and extra parameters.

## Where routes are declared

Routes are declared in a closure that receives a
[`ConfigureRoutes`](../../src/ConfigureRoutes.php) object. You pass that closure to
`EduRoute::recommendedSettings()`:

```php
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users', 'list_users');
        // … your other routes
    },
    __DIR__ . '/var/cache/routes.php', // cache key (a file here)
);
```

The closure is not called right away. It runs when the router needs the routes: on the first
call to `dispatcher()` or `uriGenerator()` when the cache is empty or disabled (and on every call to
`declaredRoutes()`, see [Listing routes](listing-routes.md)). An invalid route therefore throws
at that point, not when you write the closure.

> **Note**
> The examples on this page call `->disableCache()` so that every run starts again from your
> routes. Keep the cache on in production (see [Cache](cache.md)).

## `addRoute()`

Every route goes through this method:

```php
public function addRoute(
    HttpMethod|string|array $httpMethod,
    string $route,
    mixed $handler,
    array $extraParameters = [],
): void;
```

| Parameter | Purpose |
|---|---|
| `$httpMethod` | the HTTP method(s): `HttpMethod::Get`, `'GET'` or an array (see [HTTP methods](#http-methods)) |
| `$route` | the URL pattern, for example `/user/{id:\d+}` (see [URL patterns and parameters](#url-patterns-and-parameters)) |
| `$handler` | the value returned when the route matches (see [The handler](#the-handler)) |
| `$extraParameters` | data attached to the route, including its name (see [Extra parameters](#extra-parameters)) |

## HTTP methods

`$httpMethod` accepts:

- a case of the [`HttpMethod`](../../src/HttpMethod.php) enum: `Get`, `Post`, `Put`, `Patch`,
  `Delete`, `Head`, `Options`, and `Any` (`'*'`, the fallback route);
- a string: `'GET'`, `'POST'`… including a non-standard method such as `'PURGE'`;
- an array mixing both, to declare the same route for several methods.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\HttpMethod;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->addRoute(HttpMethod::Get, '/articles', 'article.list');
        $r->addRoute('POST', '/articles', 'article.create');
        $r->addRoute([HttpMethod::Put, 'PATCH'], '/articles/{id:\d+}', 'article.update');
        $r->addRoute('PURGE', '/cache', 'cache.purge'); // non-standard method
    },
    'unused',
)->disableCache()->dispatcher();

foreach ([['GET', '/articles'], ['PATCH', '/articles/3'], ['PURGE', '/cache'], ['get', '/articles']] as [$method, $uri]) {
    $result = $dispatcher->dispatch($method, $uri);

    echo str_pad("$method $uri", 18), match (true) {
        $result instanceof Matched => $result->handler,
        $result instanceof MethodNotAllowed => '405, allowed: ' . implode(', ', $result->allowedMethods),
        default => '404',
    }, "\n";
}
```

Output:

```text
GET /articles     article.list
PATCH /articles/3 article.update
PURGE /cache      cache.purge
get /articles     405, allowed: GET, POST
```

> **Warning**
> Methods are compared **case-sensitively**: `'get'` does not match a `GET` route. Write methods
> in upper case, or use the enum, which also protects you from typos.

An array element that is neither an `HttpMethod` nor a string throws a `TypeError`:
`HTTP method must be Studoo\EduRoute\HttpMethod or string, int given`.

### Shortcuts

Each common method has a shortcut. They take the same arguments as `addRoute()`, minus the
first one:

| Shortcut | Equivalent |
|---|---|
| `$r->get($route, $handler)` | `$r->addRoute(HttpMethod::Get, $route, $handler)` |
| `$r->post(…)` | `HttpMethod::Post` |
| `$r->put(…)` | `HttpMethod::Put` |
| `$r->patch(…)` | `HttpMethod::Patch` |
| `$r->delete(…)` | `HttpMethod::Delete` |
| `$r->head(…)` | `HttpMethod::Head` |
| `$r->options(…)` | `HttpMethod::Options` |
| `$r->any(…)` | `HttpMethod::Any`, i.e. `'*'` |

`any()` declares a **fallback** route: it is used for any method that has no dedicated route on
that URL. In addition, a `HEAD` request with no `HEAD` route falls back to the `GET` route:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/page', 'page.show');
        $r->any('/page', 'page.fallback'); // any other method
    },
    'unused',
)->disableCache()->dispatcher();

echo $dispatcher->dispatch('GET', '/page')->handler, "\n";    // page.show
echo $dispatcher->dispatch('HEAD', '/page')->handler, "\n";   // page.show (HEAD falls back to GET)
echo $dispatcher->dispatch('DELETE', '/page')->handler, "\n"; // page.fallback
```

The exact order of these fallbacks is described in [Dispatching a request](dispatching.md).

## URL patterns and parameters

A pattern is a URL in which some parts are **parameters** wrapped in curly braces:

| Syntax | Accepts | Example |
|---|---|---|
| `{name}` | anything except `/` (regex `[^/]+`) | `/user/{name}`: `/user/alice` |
| `{name:regex}` | whatever the regular expression describes | `/user/{id:\d+}`: `/user/42` |

The rest of the pattern is **literal** text: in `/feed.json`, the dot is a real dot, not
"any character".

A few rules:

- a parameter name starts with a letter or `_`, followed by letters, digits, `_` or `-`; spaces
  around the name and the regex are ignored (`{ id : \d+ }` is the same as `{id:\d+}`);
- the same name cannot appear twice in a pattern;
- the regex may contain curly braces (`{year:\d{4}}`) and alternation (`{lang:en|fr}`);
- the regex must **not** contain a capturing group: `{lang:(en|fr)}` is rejected, write
  `{lang:en|fr}` or `{lang:(?:en|fr)}` instead;
- the whole URL must match the pattern: `/user/42/`, with a trailing slash, does not match
  `/user/{id:\d+}`;
- extracted values are always **strings** (`'42'`, not `42`).

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/user/{id:\d+}', 'user.byId');          // digits only
        $r->get('/user/{name}', 'user.byName');          // default pattern: [^/]+
        $r->get('/files/{path:.+}', 'file.show');        // .+ also accepts slashes
        $r->get('/archive/{year:\d{4}}', 'archive');     // curly braces are allowed in the regex
        $r->get('/{lang:en|fr}/about', 'about');         // alternation without a capturing group
    },
    'unused',
)->disableCache()->dispatcher();

$uris = ['/user/42', '/user/alice', '/user/alice/posts', '/files/docs/a.pdf',
         '/archive/2026', '/archive/26', '/fr/about', '/de/about', '/user/42/'];

foreach ($uris as $uri) {
    $result = $dispatcher->dispatch('GET', $uri);

    echo str_pad($uri, 20), $result instanceof Matched
        ? $result->handler . ' ' . json_encode($result->variables, JSON_UNESCAPED_SLASHES)
        : '404', "\n";
}
```

Output:

```text
/user/42            user.byId {"id":"42"}
/user/alice         user.byName {"name":"alice"}
/user/alice/posts   404
/files/docs/a.pdf   file.show {"path":"docs/a.pdf"}
/archive/2026       archive {"year":"2026"}
/archive/26         404
/fr/about           about {"lang":"fr"}
/de/about           404
/user/42/           404
```

`/user/42` also matches `/user/{name}`, but the `{id:\d+}` route is declared first, so it wins
(see [Order and conflicts](#order-and-conflicts)).

## Optional segments

A part wrapped in square brackets `[...]` is **optional**. Brackets can be nested, but only
**at the end of the pattern**:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        // /blog, /blog/2026 and /blog/2026/11
        $r->get('/blog[/{year:\d{4}}[/{month:\d{2}}]]', 'blog.archive');
    },
    'unused',
)->disableCache()->dispatcher();

foreach (['/blog', '/blog/2026', '/blog/2026/11'] as $uri) {
    echo str_pad($uri, 15), json_encode($dispatcher->dispatch('GET', $uri)->variables), "\n";
}
```

Output:

```text
/blog          []
/blog/2026     {"year":"2026"}
/blog/2026/11  {"year":"2026","month":"11"}
```

A parameter missing from the URL is missing from `variables` too, so provide a default, for
example `$result->variables['month'] ?? null`.

Internally, this pattern is split into three routes: `/blog`, `/blog/{year}` and
`/blog/{year}/{month}`. Syntax errors are rejected:

| Pattern | Problem |
|---|---|
| `/user[/{id}]/edit` | optional part in the middle of the pattern |
| `/user[/{id}` | unclosed bracket |
| `/user[]` | empty optional part |

## Groups

`addGroup()` adds a common **prefix** to every route declared in its closure. Groups can be
nested, and their prefixes add up:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->addGroup('/admin', static function (ConfigureRoutes $r): void {
            $r->get('', 'admin.dashboard');                    // /admin
            $r->addGroup('/users', static function (ConfigureRoutes $r): void {
                $r->get('', 'admin.users.list');               // /admin/users
                $r->get('/{id:\d+}', 'admin.users.show');      // /admin/users/{id:\d+}
            });
        });
        $r->get('/users', 'users.list');                       // outside the group: no prefix
    },
    'unused',
)->disableCache()->dispatcher();

foreach (['/admin', '/admin/users', '/admin/users/7', '/users'] as $uri) {
    echo str_pad($uri, 16), $dispatcher->dispatch('GET', $uri)->handler, "\n";
}
```

Output:

```text
/admin          admin.dashboard
/admin/users    admin.users.list
/admin/users/7  admin.users.show
/users          users.list
```

> **Warning**
> The prefix is prepended as is, without adding a `/`. With `addGroup('/admin', …)`, write
> `'/users'`, not `'users'` (which would give `/adminusers`).

To prefix route **names** as well, use attributes and `#[RouteGroup]` with `namePrefix` (see
[Attribute routes](attributes.md)).

## Extra parameters

The fourth argument, `$extraParameters`, attaches data to the route: an
`array<string, string|int|bool|float>`. The dispatcher returns it in
`Matched::$extraParameters`, which lets a middleware check a role, for instance. Two keys are
reserved:

| Key | Constant | Purpose |
|---|---|---|
| `'_name'` | `ConfigureRoutes::ROUTE_NAME` | the route name, used to [generate its URL](uri-generation.md); a unique, non-empty string |
| `'_route'` | `ConfigureRoutes::ROUTE_REGEX` | the original pattern, group prefix included; **always** set by the router, any value you pass is ignored |

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->addGroup('/admin', static function (ConfigureRoutes $r): void {
            $r->get('/users/{id:\d+}', 'admin.users.show', [
                ConfigureRoutes::ROUTE_NAME => 'admin.user', // route name ('_name')
                'auth' => true,                               // your own data
                'role' => 'ROLE_ADMIN',
            ]);
        });
    },
    'unused',
)->disableCache()->dispatcher();

$result = $dispatcher->dispatch('GET', '/admin/users/7');

var_export($result->extraParameters);
echo "\n";
```

Output:

```text
array (
  '_route' => '/admin/users/{id:\\d+}',
  '_name' => 'admin.user',
  'auth' => true,
  'role' => 'ROLE_ADMIN',
)
```

An invalid name (`''`, an integer…) or one that is already taken throws a `BadRouteException`
before the route is registered.

## The handler

The *handler* can be any value: a controller name (`'App\Controller\UserController::show'`), an
array (`[UserController::class, 'show']`), a closure… The router never runs it: it hands it back
unchanged in `Matched::$handler`.

> **Warning**
> With the file cache (on by default with `recommendedSettings()`), handlers and extra parameters
> must be **exportable** with `var_export()`: strings, numbers, booleans, and arrays of those. A
> closure or an object throws `CannotCacheRoutes`; use `->disableCache()` for those. See
> [Cache](cache.md) for the details.

## Order and conflicts

The router looks at **static** routes (no parameters) first, then at **variable** routes in
**declaration order**: the first one that matches wins.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users/me', 'user.me');               // static route
        $r->get('/users/{name}', 'user.byName');       // declared first...
        $r->get('/users/{id:\d+}', 'user.byId');       // ...so it beats this one
    },
    'unused',
)->disableCache()->dispatcher();

foreach (['/users/me', '/users/alice', '/users/42'] as $uri) {
    echo str_pad($uri, 14), $dispatcher->dispatch('GET', $uri)->handler, "\n";
}
```

Output:

```text
/users/me     user.me
/users/alice  user.byName
/users/42     user.byName
```

`user.byId` is never reached: declare the most specific routes **first**.

Two situations are rejected for a given HTTP method:

- two identical routes, including `/users/{a}` and `/users/{b}`, which share the same regex;
- a static route declared **after** a variable route that already captures it (it could never
  be reached). The other way round is fine, as with `/users/me` above.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$tryRoutes = static function (Closure $routes): void {
    try {
        EduRoute::recommendedSettings($routes, 'unused')->disableCache()->dispatcher();
        echo "OK\n";
    } catch (BadRouteException $e) {
        echo $e->getMessage(), "\n";
    }
};

// The same route twice
$tryRoutes(static function (ConfigureRoutes $r): void {
    $r->get('/users', 'a');
    $r->get('/users', 'b');
});

// Static route declared AFTER a variable route that captures it
$tryRoutes(static function (ConfigureRoutes $r): void {
    $r->get('/users/{name}', 'user.show');
    $r->get('/users/me', 'user.me');
});

// Same routes in the opposite order: accepted
$tryRoutes(static function (ConfigureRoutes $r): void {
    $r->get('/users/me', 'user.me');
    $r->get('/users/{name}', 'user.show');
});
```

Output:

```text
Cannot register two routes matching "/users" for method "GET"
Static route "/users/me" is shadowed by previously defined variable route "/users/([^/]+)" for method "GET"
OK
```

## Declaration errors

An invalid route throws a [`BadRouteException`](../../src/BadRouteException.php):

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$patterns = [
    '/lang/{lang:(en|fr)}',      // capturing group
    '/lang/{lang:(?:en|fr)}',    // non-capturing group: accepted
    '/copy/{id}/to/{id}',        // same parameter twice
    '/user[/{id}]/edit',         // optional segment in the middle
    '/user[/{id}',               // unclosed bracket
    '/user[]',                   // empty optional part
];

foreach ($patterns as $pattern) {
    try {
        EduRoute::recommendedSettings(
            static fn (ConfigureRoutes $r) => $r->get($pattern, 'handler'),
            'unused',
        )->disableCache()->dispatcher();
        echo str_pad($pattern, 26), "OK\n";
    } catch (BadRouteException $e) {
        echo str_pad($pattern, 26), $e->getMessage(), "\n";
    }
}
```

Output:

```text
/lang/{lang:(en|fr)}      Regex "(en|fr)" for parameter "lang" contains a capturing group
/lang/{lang:(?:en|fr)}    OK
/copy/{id}/to/{id}        Cannot use the same placeholder "id" twice
/user[/{id}]/edit         Optional segments can only occur at the end of a route
/user[/{id}               Number of opening '[' and closing ']' does not match
/user[]                   Empty optional part
```

| Message | Cause |
|---|---|
| `Cannot register two routes matching "…" for method "…"` | route declared twice |
| `Static route "…" is shadowed by previously defined variable route "…" for method "…"` | static route hidden by a variable route declared earlier |
| `Cannot use the same placeholder "…" twice` | parameter repeated in the pattern |
| `Regex "…" for parameter "…" contains a capturing group` | capturing group `(…)` in the regex |
| `Optional segments can only occur at the end of a route` | `[...]` in the middle of the pattern |
| `Number of opening '[' and closing ']' does not match` | unbalanced brackets |
| `Empty optional part` | empty `[]` |
| `Cannot register two routes under the name "…"` | route name already taken |
| `Route name must be a non-empty string, "…" given` | route name is empty or not a string |

Each message and its fix are covered in [Errors](errors.md).

## Without `EduRoute`: the `RouteCollector`

`EduRoute` wires a pattern parser, a data generator and a dispatcher together for you. You can
also use the [`RouteCollector`](../../src/RouteCollector.php) directly. It implements
`ConfigureRoutes` (the same methods as above) and has no cache:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\RouteCollector;
use Studoo\EduRoute\RouteParser\Std;

$collector = new RouteCollector(new Std(), new DataGenerator\MarkBased());

$collector->get('/users/{id:\d+}', 'user.show');
$collector->addGroup('/admin', static function (ConfigureRoutes $r): void {
    $r->post('/users', 'admin.users.create');
});

// The data generator and the dispatcher must use the same strategy (Mark here)
$dispatcher = new Dispatcher\MarkBased($collector->processedRoutes());

echo $dispatcher->dispatch('GET', '/users/5')->handler, "\n";      // user.show
echo $dispatcher->dispatch('POST', '/admin/users')->handler, "\n"; // admin.users.create
```

Here, errors are thrown as soon as you call `get()` or `addGroup()`. The strategies are
explained in [How it works](how-it-works.md).

---

← [Previous](index.md) · [Contents](index.md) · [Next](dispatching.md) →
