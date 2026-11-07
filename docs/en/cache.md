# Cache and lazy loading

[Français](../fr/cache.md)

Before it can route a request, EduRoute has to parse every URL pattern and build the combined
regular expressions (see [How it works](how-it-works.md)). That work has a cost, and it always
produces the same result as long as your routes do not change. The cache does it once, then
reuses the result on every request.

This page covers:

- how the default cache (`FileCache`) works;
- how to disable it, or replace it with a PSR-16 cache or a cache of your own;
- when and how to invalidate it;
- why handlers must be exportable;
- how to defer loading the routes with `lazyDispatcher()` and `lazyUriGenerator()`.

## The default cache: `FileCache`

`EduRoute::recommendedSettings($routes, $cacheKey)` enables the
[`FileCache`](../../src/Cache/FileCache.php) cache. The second argument, `$cacheKey`, is **the
path of the cache file**.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\EduRoute;

$cacheFile = __DIR__ . '/var/cache/routes.php';

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users', 'App\Controller\UserController::list');
        $r->get('/user/{id:\d+}', 'App\Controller\UserController::show', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
    },
    $cacheFile,
);

echo file_exists($cacheFile) ? 'cache present' : 'no cache', PHP_EOL;

$result = $router->dispatcher()->dispatch('GET', '/user/42');

echo file_exists($cacheFile) ? 'cache present' : 'no cache', PHP_EOL;

if ($result instanceof Matched) {
    echo $result->handler, ' ', $result->variables['id'], PHP_EOL;
}

echo $router->uriGenerator()->forRoute('user.show', ['id' => '7'])->path, PHP_EOL;
```

First run:

```text
no cache
cache present
App\Controller\UserController::show 42
/user/7
```

On later runs the first line becomes `cache present`: the routes are read from the file and the
route definition callback is no longer called.

What you need to know about `FileCache`:

- **When is the file read or written?** On the first call to `dispatcher()` or
  `uriGenerator()` (or on the first method call on a lazy object, see
  [below](#lazy-loading)), not when the `EduRoute` instance is created.
- **Format.** The file is a PHP script containing `<?php return array (...);`, written with
  `var_export()`. It is loaded with a plain `include`: with OPcache it stays in compiled memory
  and loading it is almost free.
- **Contents.** An array with three entries: static routes, dynamic routes (regular
  expressions) and named routes. The named routes are the ones used by the
  [URI generator](uri-generation.md).
- **Safe writes.** The file is first written to `<file>.tmp` (with a lock), then renamed, so a
  concurrent request never reads a half-written file. The file gets `0664` permissions.
- **Directory.** The directory is created if it does not exist (`0775` permissions). If it
  cannot be created, or is not writable, a `RuntimeException` is thrown:
  `The cache directory is not writable "/path/to/directory"`.
- **File in another format.** A file that does not hold a three-entry array (for example a
  FastRoute 1.x cache, which has two entries) is ignored and regenerated.

> **Note**
> A given `EduRoute` instance computes (or reads) the routes only once: `dispatcher()` and
> `uriGenerator()` called on the same instance share the result. Every `with*()`, `use*()` and
> `disableCache()` method returns a **new** instance, which starts from scratch.

## Disabling the cache

During development, or if your handlers are closures, turn the cache off with
`disableCache()`. The key passed to `recommendedSettings()` is then not used, so any value will
do.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$builds = 0;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r) use (&$builds): void {
        $builds++;
        $r->get('/user/{id:\d+}', 'UserController::show', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
    },
    'unused',
)->disableCache();

$router->dispatcher();
$router->dispatcher();
$router->uriGenerator();
echo $builds, PHP_EOL; // 1: the routes are computed only once per instance

$router->useGroupCountDispatcher()->dispatcher(); // new instance: computed again
echo $builds, PHP_EOL; // 2
```

Without a cache, the routes are recomputed on every PHP request (once per `EduRoute`
instance). You always see your latest changes, at the price of a small overhead.

## Invalidating the cache

**The cache is never invalidated automatically.** As long as the file exists, it is used, even
if your routes have changed:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$cacheFile = __DIR__ . '/var/cache/routes.php';
@unlink($cacheFile); // start from an empty cache for this example

$v1 = static function (ConfigureRoutes $r): void {
    $r->get('/hello', 'HelloController::v1');
};
$v2 = static function (ConfigureRoutes $r): void {
    $r->get('/hello', 'HelloController::v2'); // the handler has changed
    $r->get('/new', 'NewController::index');  // new route
};

// First deployment: the cache is written with the v1 routes
EduRoute::recommendedSettings($v1, $cacheFile)->dispatcher();

// The routes change, but the cache file already exists
$dispatcher = EduRoute::recommendedSettings($v2, $cacheFile)->dispatcher();
echo $dispatcher->dispatch('GET', '/hello')->handler, PHP_EOL; // HelloController::v1 (stale!)
echo $dispatcher->dispatch('GET', '/new')::class, PHP_EOL;     // Studoo\EduRoute\Dispatcher\Result\NotMatched

// Invalidation: delete the file, it is regenerated on the next build
unlink($cacheFile);
$dispatcher = EduRoute::recommendedSettings($v2, $cacheFile)->dispatcher();
echo $dispatcher->dispatch('GET', '/hello')->handler, PHP_EOL; // HelloController::v2
```

So delete the cache file:

- **when your routes change** (on every deployment, for instance);
- **when you switch dispatch strategy** (`useGroupCountDispatcher()`, etc.). The data format
  depends on the strategy: a cache written by the Mark strategy and read by the GroupCount
  strategy triggers PHP warnings (`Undefined array key`, etc.), wrong results, or even a fatal
  error (`TypeError`). Alternatively,
  use a different key (file name) per strategy, such as `var/cache/routes-groupcount.php`.

> **Warning**
> If OPcache runs with `opcache.validate_timestamps=0` (common in production), PHP no longer
> checks whether files have changed. After deleting the cache file, also reset OPcache (restart
> PHP-FPM or call `opcache_reset()`), otherwise the old compiled version may still be served.

> **Note**
> `declaredRoutes()`, used to [list routes](listing-routes.md), neither reads nor writes the
> cache: the list always reflects the current code.

## Exportable handlers and `CannotCacheRoutes`

`FileCache` writes the routes with `var_export()`. Everything you store in a route (the handler
and the [extra parameters](routes.md#extra-parameters)) must therefore be **exportable**:

| Value | With `FileCache` |
|---|---|
| string: `'App\Controller\UserController::show'` | yes |
| array: `['App\Controller\UserController', 'show']` | yes |
| scalar (`int`, `float`, `bool`), `null` | yes |
| closure: `static fn () => ...` | no |
| object (service, controller instance, enum case such as `HttpMethod::Get`, etc.) | no |

`var_export()` turns an object into `Class::__set_state(...)`, which PHP cannot always load back:
the next request would end in a fatal error. `FileCache` therefore checks the data **before**
writing and throws [`CannotCacheRoutes`](../../src/Cache/CannotCacheRoutes.php) as soon as it
finds an object, at any depth. No file is written.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\Cache\CannotCacheRoutes;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$routes = static function (ConfigureRoutes $r): void {
    $r->get('/hello', static fn (): string => 'Hello!'); // closure: not exportable
};

try {
    EduRoute::recommendedSettings($routes, __DIR__ . '/var/cache/routes.php')->dispatcher();
} catch (CannotCacheRoutes $e) {
    echo $e->getMessage(), PHP_EOL;
}

// Without a cache, the closure works
$result = EduRoute::recommendedSettings($routes, 'unused')
    ->disableCache()
    ->dispatcher()
    ->dispatch('GET', '/hello');

echo ($result->handler)(), PHP_EOL; // Hello!
```

Output (the exception message is in French, exactly as the library produces it):

```text
Les routes ne peuvent pas être mises en cache dans un fichier : elles contiennent un objet (Closure). Avec un cache, utilisez des handlers exportables (chaîne "App\Controller\UserController::show", tableau, scalaire) ; les closures et objets ne fonctionnent qu'avec ->disableCache().
Hello!
```

In English: "The routes cannot be cached in a file: they contain an object (Closure). With a
cache, use exportable handlers (a string such as `"App\Controller\UserController::show"`, an
array, a scalar); closures and objects only work with `->disableCache()`."

The type of the offending object is shown in parentheses (`Closure`,
`Studoo\EduRoute\HttpMethod`, etc.). There are two ways to fix it:

- replace the object with an exportable value (a `"Class::method"` string, an array,
  `HttpMethod::Get->value` instead of `HttpMethod::Get`, etc.);
- or disable the cache with `->disableCache()`.

See also the [error reference](errors.md#cannotcacheroutes).

## Another storage: `Psr16Cache`

To store the routes somewhere other than a file (Redis, APCu, Memcached, etc.), use
[`Psr16Cache`](../../src/Cache/Psr16Cache.php), which wraps any
[PSR-16](https://www.php-fig.org/psr/psr-16/) cache (`Psr\SimpleCache\CacheInterface`; the
`psr/simple-cache` package is already an EduRoute dependency). Pass it to
`withCache($driver, $cacheKey)`, which replaces the cache and key chosen by
`recommendedSettings()`:

```php
use Studoo\EduRoute\Cache\Psr16Cache;

// $psr16Cache: your PSR-16 cache (Redis, APCu, etc.)
$router = EduRoute::recommendedSettings($routes, 'routes')
    ->withCache(new Psr16Cache($psr16Cache), 'routes.v1');
```

A complete example, with a tiny in-memory PSR-16 cache written just for this example:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Psr\SimpleCache\CacheInterface;
use Studoo\EduRoute\Cache\Psr16Cache;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

// Minimal in-memory PSR-16 cache, for the example (in real life: Redis, APCu, etc.)
final class ArrayPsr16 implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $items = [];

    public function get(string $key, mixed $default = null): mixed { return $this->items[$key] ?? $default; }
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool { $this->items[$key] = $value; return true; }
    public function delete(string $key): bool { unset($this->items[$key]); return true; }
    public function clear(): bool { $this->items = []; return true; }
    public function getMultiple(iterable $keys, mixed $default = null): iterable { foreach ($keys as $k) { yield $k => $this->get($k, $default); } }
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool { foreach ($values as $k => $v) { $this->set($k, $v); } return true; }
    public function deleteMultiple(iterable $keys): bool { foreach ($keys as $k) { $this->delete($k); } return true; }
    public function has(string $key): bool { return isset($this->items[$key]); }
}

$psr16Cache = new ArrayPsr16();

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/user/{id:\d+}', 'App\Controller\UserController::show');
    },
    'routes', // replaced by the key given to withCache()
)->withCache(new Psr16Cache($psr16Cache), 'routes.v1');

echo $router->dispatcher()->dispatch('GET', '/user/42')->handler, PHP_EOL; // App\Controller\UserController::show
echo implode(', ', array_keys($psr16Cache->items)), PHP_EOL;              // routes.v1

$psr16Cache->delete('routes.v1'); // invalidation: the entry is recomputed on the next build
```

With `Psr16Cache`:

- the key is a **PSR-16 key**, not a path: avoid the characters PSR-16 reserves (`{}()/\@:`).
  Putting a version number or the strategy name in the key (`routes.v1`,
  `routes.groupcount`) is a simple way to invalidate;
- the entry is written without a time-to-live (TTL): it stays until you delete it
  (`delete()`, `clear()`) or the cache evicts it;
- a missing entry, or one that is not a three-entry array, is recomputed and written again;
- `Psr16Cache` does not check for objects the way `FileCache` does: what happens with a closure
  depends on your PSR-16 implementation (most serialize values, and a closure cannot be
  serialized). Keep your handlers exportable.

`withCache()` also accepts a class name, instantiated without arguments: for example
`->withCache(FileCache::class, __DIR__ . '/var/cache/routes.php')`. `withCache()` also turns the
cache back on after a `disableCache()`.

## Writing your own cache

A cache is a class implementing the [`Studoo\EduRoute\Cache`](../../src/Cache.php) interface,
which has a single method:

```php
public function get(string $key, Closure $loader): array;
```

It receives the key and a `$loader` function that computes the routes. It returns the cached
data if there is any; otherwise it calls `$loader()`, stores the result and returns it.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\Cache;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

// A home-made cache: keeps the routes in memory and reports what it does
final class LoggingMemoryCache implements Cache
{
    /** @var array<string, array<mixed>> */
    private array $entries = [];

    public function get(string $key, Closure $loader): array
    {
        if (isset($this->entries[$key])) {
            echo "reading \"$key\"", PHP_EOL;

            return $this->entries[$key];
        }

        echo "computing \"$key\"", PHP_EOL;

        return $this->entries[$key] = $loader();
    }
}

$cache = new LoggingMemoryCache();
$routes = static function (ConfigureRoutes $r): void {
    $r->get('/', 'HomeController::index');
};

EduRoute::recommendedSettings($routes, 'unused')->withCache($cache, 'routes')->dispatcher(); // computing "routes"
EduRoute::recommendedSettings($routes, 'unused')->withCache($cache, 'routes')->dispatcher(); // reading "routes"
```

## Lazy loading

`dispatcher()` and `uriGenerator()` load the routes right away. Yet some requests never route
anything: a CLI command, a file served by an upstream middleware, an HTTP-cached response...
For those, loading the routes is wasted work.

`lazyDispatcher()` and `lazyUriGenerator()` return **lazy objects** (new in PHP 8.4): the object
is created immediately, but the routes are only read from the cache, or computed, on the
**first method call**.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$builds = 0;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r) use (&$builds): void {
        $builds++;
        $r->get('/user/{id:\d+}', 'UserController::show');
    },
    'unused',
)->disableCache();

$dispatcher = $router->lazyDispatcher(); // nothing is loaded here
echo $builds, PHP_EOL; // 0

$result = $dispatcher->dispatch('GET', '/user/42'); // the routes are loaded here
echo $builds, PHP_EOL; // 1

$dispatcher->dispatch('GET', '/user/43'); // already loaded: no new computation
echo $builds, PHP_EOL; // 1
echo $result->handler, PHP_EOL; // UserController::show
```

Key points:

- the return type does not change: `lazyDispatcher()` returns a `Dispatcher`,
  `lazyUriGenerator()` a `GenerateUri`. You can pass them anywhere a regular object is expected
  (for example to the [PSR-15 middleware](middleware.md));
- once initialized, the object is a regular dispatcher (or generator), with no overhead;
- errors are **deferred** too: a `CannotCacheRoutes` or a
  [`BadRouteException`](errors.md) is only thrown on the first `dispatch()` or `forRoute()`
  call, not when `lazyDispatcher()` is called;
- one limitation: a custom dispatcher or generator (see
  [custom implementations](how-it-works.md#custom-implementations)) that extends an internal PHP class cannot be lazy
  (an engine limitation). It is then built immediately, without any error.

---

← [Previous](listing-routes.md) · [Contents](index.md) · [Next](middleware.md) →
