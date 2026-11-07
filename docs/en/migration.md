# Migration

[Français](../fr/migration.md)

This page explains how to move from [`nikic/fast-route`](https://github.com/nikic/FastRoute) to
`studoo/edu-route` 1.1. EduRoute is a fork of FastRoute's `master` branch (the upcoming 2.0): if
you are coming from FastRoute 1.x, as edu-framework is, most of the changes come from that
branch; a few are specific to EduRoute.

## Before you start

- EduRoute requires **PHP 8.4 or 8.5** (`~8.4.0 || ~8.5.0`).
- The migration is the same for 1.0 and 1.1, with one exception described in
  [Behaviour changes](#behaviour-changes-in-10x-and-11).
- You don't have to migrate everything at once: the old array access on results still works
  (with a deprecation notice). Get your code running first, then clean up the deprecations.

## From `nikic/fast-route` 1.x

This is the edu-framework case. Follow the steps in order.

### 1. Dependency and namespace

```diff
- "nikic/fast-route": "^1.3"
+ "studoo/edu-route": "^1.1"
```

Replace **every** `FastRoute\` prefix with `Studoo\EduRoute\`: `use` statements, fully qualified
names in the code (`\FastRoute\RouteCollector`), property and parameter types, and PHPDoc
(`@var \FastRoute\RouteCollector`).

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
> Once you are done, searching the whole project (code, configuration, PHPDoc) for `FastRoute\`
> should find nothing, except `composer.lock` before you run `composer update`. Search for
> `FastRoute\` with the backslash: your own class names may contain `FastRoute`, like
> `FastRouteCore` in edu-framework.

### 2. Building the dispatcher

The `FastRoute\simpleDispatcher()` and `FastRoute\cachedDispatcher()` functions are gone, and
so is `RouteCollector::getData()`. Use the [`EduRoute`](../../src/EduRoute.php) builder instead:

```diff
- $dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r) {
-     $r->addRoute('GET', '/users', 'handler');
- });
+ $dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
+     $r->addRoute('GET', '/users', 'handler');
+ }, 'unused')->disableCache()->dispatcher();
```

The second argument of `recommendedSettings()` is the cache key (for `FileCache`, the file
path). It is required even if you then disable the cache, in which case any value such as
`'unused'` will do.

| Before (function or option) | After |
|---|---|
| `simpleDispatcher($cb)` | `EduRoute::recommendedSettings($cb, 'unused')->disableCache()->dispatcher()` |
| `cachedDispatcher($cb, ['cacheFile' => $f])` | `EduRoute::recommendedSettings($cb, $f)->dispatcher()` (see the warning below) |
| `cacheDisabled => true` option | `->disableCache()` |
| `dataGenerator` + `dispatcher` options | `->useMarkDispatcher()`, `->useGroupCountDispatcher()`, `->useGroupPosDispatcher()`, `->useCharCountDispatcher()` or `->useCustomDispatcher($generator, $dispatcher)` |
| `routeParser` / `routeCollector` options | no equivalent on `EduRoute`: build the collector yourself (see below) |
| `$collector->getData()` | `$collector->processedRoutes()` |

A few differences worth knowing:

- the route definition function must be a `Closure` (an anonymous function, or
  `$object->method(...)`), not a string or an array callable;
- type-hint its parameter with the `ConfigureRoutes` interface. `RouteCollector` also works
  (it is the class actually passed in), but the interface is the stable contract;
- the default strategy is now `MarkBased` (it used to be `GroupCountBased`). All four strategies
  return the same results; see [How it works](how-it-works.md#the-four-strategies);
- every `use*()`, `with*()` and `disableCache()` method returns a **new** instance, so keep the
  returned value.

> **Warning**
> **The cache format has changed.** Delete the old `cacheFile` (or use a different path) when
> you deploy. A file in FastRoute 1.x's old format (2 entries) is detected and rebuilt, but a
> cache written with a different dispatch strategy is not. When caching is on, handlers must be
> exportable (strings, arrays, scalars): a closure throws
> [`CannotCacheRoutes`](errors.md#cannotcacheroutes). See [Cache](cache.md#invalidating-the-cache).

#### Building the collector yourself

This is what edu-framework (`FastRouteCore`) does, and it is the only way to plug in your own
`RouteParser` or `ConfigureRoutes`:

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

`GroupCountBased` is still available; `MarkBased` is simply the fastest. The data generator and
the dispatcher must always come from **the same family**. A complete example:

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

// processedRoutes() replaces getData()
$dispatcher = new MarkBasedDispatcher($routeCollector->processedRoutes());
$result = $dispatcher->dispatch('GET', '/hello/alice');

echo $result->handler, ' ', $result->variables['name'], PHP_EOL;
```

Output:

```text
hello alice
```

### 3. Reading the dispatch result

`dispatch()` now returns an object: `Matched`, `MethodNotAllowed` or `NotMatched` (see
[Dispatching a request](dispatching.md)). Old code that reads an array still works, but every
read triggers an `E_USER_DEPRECATED` notice:

```text
Method Studoo\EduRoute\Dispatcher\Result\Matched::offsetGet() is deprecated since 1.0, use $result instanceof Matched and its properties instead
```

Replace the `switch` on `$routeInfo[0]` with `instanceof` checks and properties:

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

| Before (array) | After (object) |
|---|---|
| `$routeInfo[0] === Dispatcher::NOT_FOUND` | `$result instanceof NotMatched` |
| `$routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED` | `$result instanceof MethodNotAllowed` |
| `$routeInfo[1]` (allowed methods) | `$result->allowedMethods` |
| `$routeInfo[0] === Dispatcher::FOUND` | `$result instanceof Matched` |
| `$routeInfo[1]` (handler) | `$result->handler` |
| `$routeInfo[2]` (variables) | `$result->variables` |
| *(did not exist)* | `$result->extraParameters` |

A complete example:

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

Output:

```text
show_user 42
405, Allow: GET
404
```

The `Dispatcher::FOUND`, `NOT_FOUND` and `METHOD_NOT_ALLOWED` constants only make sense with
array access, so they are deprecated too (in PHPDoc: PHPStan and your IDE flag them, PHP itself
emits nothing at runtime). Array access and these constants will be removed in 2.0.

> **Note**
> Results are immutable. If your code used to modify the returned array (to add a variable, for
> instance), copy the values into your own variables instead: a write throws an `Error`
> (properties) or a `RuntimeException` (`Result cannot be changed`, array access). See
> [Errors](errors.md#other-errors).

### 4. Groups and cache: `Closure` required

`addGroup()` and `Cache::get()` now only accept a `Closure`, no more string or array callables.
An array throws a `TypeError`:

```text
Studoo\EduRoute\RouteCollector::addGroup(): Argument #2 ($callback) must be of type Closure, array given, called in … on line …
```

The first-class callable syntax `method(...)` turns a method into a `Closure`:

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
        // Before: $r->addGroup('/admin', [$this, 'adminRoutes']);
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

Output:

```text
admin_users
```

### 5. Extensions

This step only matters for code that extends or reimplements the router.

- Concrete classes are `final` (`RouteCollector`, `RouteParser\Std`, the dispatchers and data
  generators, `BadRouteException`): use composition, or implement the `ConfigureRoutes`,
  `RouteParser`, `DataGenerator` and `Dispatcher` interfaces.
- Public constants are typed (`Dispatcher::FOUND` is an `int`, `ConfigureRoutes::ROUTE_NAME` a
  `string`): a redeclaration must use the same type.
- Result objects are `readonly` classes with write-once properties (`public(set) readonly`): a
  custom dispatcher creates them with `new Matched()`, then writes each of the `handler`,
  `variables` (which no longer defaults to `[]`) and `extraParameters` properties **exactly
  once**. Any later change throws an `Error`.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\Dispatcher\Result\Matched;

// In a custom dispatcher: all three properties, once each
$result = new Matched();
$result->handler = 'show_user';
$result->variables = ['id' => '42'];
$result->extraParameters = [];

try {
    $result->handler = 'other'; // second write: rejected
} catch (Error $e) {
    echo $e->getMessage(), PHP_EOL;
}
```

Output:

```text
Cannot modify readonly property Studoo\EduRoute\Dispatcher\Result\Matched::$handler
```

Forgetting a property (say `variables`) causes an `Error` the first time it is read:
`Typed property Studoo\EduRoute\Dispatcher\Result\Matched::$variables must not be accessed before initialization`.

## From `nikic/fast-route` 2.0-dev (`master` branch)

Everything above applies (the functions removed in step 2 were already deprecated there), plus:

- the `FastRoute\FastRoute` builder becomes `Studoo\EduRoute\EduRoute`, with the same methods;
- the `cacheDriver` / `cacheKey` options become `->withCache($driver, $key)`.

## Behaviour changes in 1.0.x and 1.1

These changes don't require any code change, but they may change what you observe.

| Version | Before | Now |
|---|---|---|
| 1.0.0 | With `GroupPosBased`, a parameter capturing an empty string (`{slug:[a-z]*}` on `/page/`) sent the request to the wrong route. | The right route is found. The cache format of this strategy changes. |
| 1.0.0 | Routes containing a closure were written to an unreadable cache file (fatal error on the next request). | [`CannotCacheRoutes`](errors.md#cannotcacheroutes) is thrown on the very first request; no file is written. |
| 1.0.1 | When the regex engine failed (`pcre.backtrack_limit`, expensive pattern), the request silently got a 404. | [`RegexMatchingFailed`](errors.md#regexmatchingfailed) is thrown, with the URI and the PCRE error. |
| 1.1.0 | A rejected route name (empty, not a string, already taken) left the route registered if the exception was caught. | The name is checked **before** registration, so the route is not added. A name error is therefore reported before a duplicate or shadowed route error. |

For example, in 1.1:

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
    // Name already taken
    $r->get('/accueil', 'home2', [ConfigureRoutes::ROUTE_NAME => 'home']);
} catch (BadRouteException $e) {
    echo $e->getMessage(), PHP_EOL;
}

// The rejected route was not registered
$dispatcher = new MarkBasedDispatcher($r->processedRoutes());
echo $dispatcher->dispatch('GET', '/accueil')::class, PHP_EOL;
```

Output:

```text
Cannot register two routes under the name "home"
Studoo\EduRoute\Dispatcher\Result\NotMatched
```

## What you get

Once migrated, these features are available (none of them is required):

| Feature | Page |
|---|---|
| `HttpMethod` enum (`HttpMethod::Get`…), accepted alongside strings | [Defining routes](routes.md) |
| Named routes and URI generation | [Generating URLs](uri-generation.md) |
| Attribute routes `#[Route]` / `#[RouteGroup]` | [Attribute routes](attributes.md) |
| Listing declared routes (`declaredRoutes()`, 1.1) | [Listing routes](listing-routes.md) |
| PSR-16 cache and lazy loading (`lazyDispatcher()`) | [Cache](cache.md) |
| PSR-15 `RoutingMiddleware` (404, 405 with `Allow`) | [PSR-15 middleware](middleware.md) |

## Checklist

1. `composer.json`: `nikic/fast-route` replaced with `studoo/edu-route`, PHP 8.4 or 8.5.
2. No `FastRoute\` left anywhere in the code or the PHPDoc.
3. `simpleDispatcher()` / `cachedDispatcher()` replaced with `EduRoute::recommendedSettings()`,
   `getData()` with `processedRoutes()`.
4. Old cache file deleted on deployment; exportable handlers if caching is on.
5. `addGroup()` receives `Closure`s (`$this->method(...)`).
6. Results read with `instanceof` and properties: no more `E_USER_DEPRECATED` in the logs or
   the tests.

---

← [Previous](errors.md) · [Contents](index.md)
