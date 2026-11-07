# Generating URLs

[Français](../fr/uri-generation.md)

The dispatcher goes from a URL to a route. **URL generation** goes the other way: from a route's
name and its parameters, it builds the path. Your templates and redirects no longer hard-code
`/articles/2026/11`: if the route pattern changes, the links follow.

## Naming a route

Only **named** routes can be generated. Give the name with the `ConfigureRoutes::ROUTE_NAME` key
(that is, `'_name'`) in the route's extra parameters:

```php
$r->get('/users/{id:\d+}', 'show_user', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
```

A few rules, checked when the route is registered:

- the name is a **non-empty string**; otherwise you get a `BadRouteException`;
- a name is **unique**: two routes cannot share a name, even for different methods. A route
  declared for several methods (`addRoute(['GET', 'POST'], ...)`) has a single name;
- inside a group, the name **does not** pick up the group prefix: name it however you like (a
  convention such as `admin.user.edit` helps keep things tidy). The generated path, on the other
  hand, does include the prefix.

With [attribute routes](attributes.md), the name is given with `#[Route(..., name: '...')]`, and
`#[RouteGroup]` can prepend a prefix to it (`namePrefix`).

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$routeSets = [
    static function (ConfigureRoutes $r): void {
        $r->get('/login', 'login', [ConfigureRoutes::ROUTE_NAME => 'login']);
        $r->post('/login', 'check_login', [ConfigureRoutes::ROUTE_NAME => 'login']);
    },
    static function (ConfigureRoutes $r): void {
        $r->get('/login', 'login', [ConfigureRoutes::ROUTE_NAME => '']);
    },
];

foreach ($routeSets as $routes) {
    try {
        EduRoute::recommendedSettings($routes, 'unused')->disableCache()->uriGenerator();
    } catch (BadRouteException $e) {
        echo $e->getMessage(), "\n";
    }
}
```

```text
Cannot register two routes under the name "login"
Route name must be a non-empty string, "''" given
```

The name also shows up in the dispatch result: `$result->extraParameters['_name']` (see
[Dispatching a request](dispatching.md#matched)).

## Getting the generator

```php
$uris = $router->uriGenerator();     // routes are loaded right away
$uris = $router->lazyUriGenerator(); // routes are loaded on the first forRoute()
```

`$router` is the object built by `EduRoute::recommendedSettings()`. Both methods return an object
implementing the [`GenerateUri`](../../src/GenerateUri.php) interface, which has a single method:

```php
public function forRoute(string $name, array $substitutions = []): GeneratedUri;
```

- `$name`: the route name;
- `$substitutions`: an array of `parameter name => value`. Values are **strings**.

The default implementation is
[`FromProcessedConfiguration`](../../src/GenerateUri/FromProcessedConfiguration.php). It reads the
same data as the dispatcher, and therefore the same [cache](cache.md).

## Generating a path

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/', 'home', [ConfigureRoutes::ROUTE_NAME => 'home']);
        $r->get('/articles/{year:\d{4}}[/{month:\d{2}}]', 'archive', [
            ConfigureRoutes::ROUTE_NAME => 'blog.archive',
        ]);
        $r->addGroup('/admin', static function (ConfigureRoutes $r): void {
            // The name does not include the prefix: pick it freely
            $r->addRoute(['GET', 'POST'], '/users/{id:\d+}', 'edit_user', [
                ConfigureRoutes::ROUTE_NAME => 'admin.user.edit',
            ]);
        });
    },
    'unused',
)->disableCache();

$uris = $router->uriGenerator();

echo $uris->forRoute('home'), "\n";
echo $uris->forRoute('blog.archive', ['year' => '2026']), "\n";
echo $uris->forRoute('blog.archive', ['year' => '2026', 'month' => '11']), "\n";
echo $uris->forRoute('admin.user.edit', ['id' => '42']), "\n";
```

```text
/
/articles/2026
/articles/2026/11
/admin/users/42
```

### Optional segments

A route with optional segments (`[/{month:\d{2}}]`) matches several path shapes. The generator
tries them from **longest to shortest**, and keeps the first one for which it has **all** the
parameters:

- with `year` and `month`, it produces `/articles/2026/11`;
- with `year` alone, the long shape is out (`month` is missing), so it produces `/articles/2026`.

The order of the keys in `$substitutions` does not matter.

> **Warning**
> The shape is chosen based on which parameters are **present**, not on their values. If you pass
> `month`, the generator picks the long shape; if the `month` value is invalid, it throws instead
> of falling back to the short shape. Do not pass an optional parameter you do not want in the
> URL.

## The result: `GeneratedUri`

`forRoute()` returns a [`GeneratedUri`](../../src/GenerateUri/GeneratedUri.php) object, which is
immutable (`readonly`):

| Member | Type | Content |
|---|---|---|
| `path` | `string` | the generated path, for example `/articles/2026` |
| `unmatchedSubstitutions` | `array<string, string>` | the values you passed that do not match any parameter of the path |
| `__toString()` | `string` | the path alone (`path`), without a query string |
| `asUri(UriInterface $baseUri)` | `UriInterface` | a PSR-7 URI: `$baseUri` with this path and `unmatchedSubstitutions` as the query string |

Leftover values are not an error: they end up in `unmatchedSubstitutions`. `echo` and `(string)`
ignore them; `asUri()` turns them into a query string with `http_build_query()`:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Nyholm\Psr7\Uri;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$uris = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/articles/{year:\d{4}}[/{month:\d{2}}]', 'archive', [
            ConfigureRoutes::ROUTE_NAME => 'blog.archive',
        ]);
    },
    'unused',
)->disableCache()->uriGenerator();

$generated = $uris->forRoute('blog.archive', ['year' => '2026', 'page' => '2', 'sort' => 'date']);

var_dump($generated->path);                   // the path part
var_dump($generated->unmatchedSubstitutions); // the values not used in the path
echo $generated, "\n";                        // __toString(): the path alone

// asUri(): path + query string, on top of a base PSR-7 URI
echo $generated->asUri(new Uri('https://example.com')), "\n";
```

```text
string(14) "/articles/2026"
array(2) {
  ["page"]=>
  string(1) "2"
  ["sort"]=>
  string(4) "date"
}
/articles/2026
https://example.com/articles/2026?page=2&sort=date
```

`asUri()` expects an implementation of `Psr\Http\Message\UriInterface`. EduRoute does not ship
one: use your framework's, or a library such as `nyholm/psr7` (used here:
`composer require nyholm/psr7`).

> **Note**
> `asUri()` **replaces** both the path and the query string of `$baseUri`: any query string already
> present on the base URI is lost, even when `unmatchedSubstitutions` is empty. The scheme, host,
> port and fragment (`#...`) are kept.

## Encoding values

The generator copies values into the path **as is**, without encoding them. For a parameter that
may contain spaces, accented letters or other special characters, encode the value with
`rawurlencode()`. It is the counterpart of the `rawurldecode()` you apply before dispatching (see
[Preparing the URI](dispatching.md#preparing-the-uri)):

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/hello/{name}', 'hello', [ConfigureRoutes::ROUTE_NAME => 'hello']);
    },
    'unused',
)->disableCache();

$uris = $router->uriGenerator();

// The value is copied as is, without any encoding
echo $uris->forRoute('hello', ['name' => 'Jean Dupont']), "\n";

// Encode it yourself to get a valid URL
$uri = (string) $uris->forRoute('hello', ['name' => rawurlencode('Jean Dupont')]);
echo $uri, "\n";

// Round trip: the dispatcher finds the route again after rawurldecode()
$result = $router->dispatcher()->dispatch('GET', rawurldecode($uri));
echo $result->handler, ' ', $result->variables['name'], "\n";
```

```text
/hello/Jean Dupont
/hello/Jean%20Dupont
hello Jean Dupont
```

Values must be strings: an integer (`['id' => 7]`) causes a `TypeError`. Write `['id' => '7']` or
`['id' => (string) $user->id]`.

## Errors: `UriCouldNotBeGenerated`

When the path cannot be built, `forRoute()` throws
[`UriCouldNotBeGenerated`](../../src/GenerateUri/UriCouldNotBeGenerated.php) (a `LogicException`
implementing the `Studoo\EduRoute\Exception` interface). It is a programming error: the code that
generates the link needs fixing.

| Case | Message |
|---|---|
| no route has this name | `There is no route with name "<name>" defined` |
| a required parameter is missing | `Route "<name>" expects at least parameter values for [<missing>], but received <received>` |
| a value does not match the parameter's pattern | ``Route "<name>" expects the parameter [<parameter>] to match the regex `<pattern>` `` |

In the second message, `<received>` is `none` if you passed no value at all, and otherwise the list
of names received, between square brackets. When the route has optional segments, the missing
parameters are those of the **shortest** shape.

The pattern is checked against the whole value: `\d{4}` rejects `'26'` as well as `'20261'`. A
parameter without a pattern (`{name}`, i.e. `[^/]+`) rejects the empty string and any value
containing `/`.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\GenerateUri\UriCouldNotBeGenerated;

$uris = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/articles/{year:\d{4}}[/{month:\d{2}}]', 'archive', [
            ConfigureRoutes::ROUTE_NAME => 'blog.archive',
        ]);
    },
    'unused',
)->disableCache()->uriGenerator();

$attempts = [
    ['blog.achive', ['year' => '2026']],                    // typo in the name
    ['blog.archive', []],                                   // required parameter missing
    ['blog.archive', ['page' => '2']],                      // same, with an extra parameter
    ['blog.archive', ['year' => '26']],                     // does not match \d{4}
    ['blog.archive', ['year' => '2026', 'month' => 'nov']], // does not match \d{2}
];

foreach ($attempts as [$name, $substitutions]) {
    try {
        $uris->forRoute($name, $substitutions);
    } catch (UriCouldNotBeGenerated $e) {
        echo $e->getMessage(), "\n";
    }
}
```

```text
There is no route with name "blog.achive" defined
Route "blog.archive" expects at least parameter values for [year], but received none
Route "blog.archive" expects at least parameter values for [year], but received [page]
Route "blog.archive" expects the parameter [year] to match the regex `\d{4}`
Route "blog.archive" expects the parameter [month] to match the regex `\d{2}`
```

See also [Errors](errors.md#uricouldnotbegenerated).

## Lazy loading

`lazyUriGenerator()` returns a generator whose routes are only loaded (from the cache, or
computed) on the first call to `forRoute()`. With a file cache, the file is therefore only written
at that point:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$cacheFile = __DIR__ . '/routes.cache.php';
@unlink($cacheFile); // start from an empty cache for the example

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/users/{id:\d+}', 'show_user', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
    },
    $cacheFile,
);

$uris = $router->lazyUriGenerator();
var_dump(file_exists($cacheFile)); // nothing has been loaded yet

echo $uris->forRoute('user.show', ['id' => '7']), "\n";
var_dump(file_exists($cacheFile)); // the first forRoute() built and cached the routes
```

```text
bool(false)
/users/7
bool(true)
```

See [Cache and lazy loading](cache.md#lazy-loading).

## Without `EduRoute`

If you build the `RouteCollector` yourself, `processedRoutes()` returns a three-element array:
static routes, variable routes, and **named routes** (index `2`). Pass the latter to the
`FromProcessedConfiguration` constructor:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator\MarkBased;
use Studoo\EduRoute\GenerateUri\FromProcessedConfiguration;
use Studoo\EduRoute\RouteCollector;
use Studoo\EduRoute\RouteParser\Std;

$collector = new RouteCollector(new Std(), new MarkBased());
$collector->get('/users/{id:\d+}', 'show_user', [ConfigureRoutes::ROUTE_NAME => 'user.show']);

// processedRoutes() returns [static routes, variable routes, named routes]
$uris = new FromProcessedConfiguration($collector->processedRoutes()[2]);

echo $uris->forRoute('user.show', ['id' => '7']), "\n";
```

```text
/users/7
```

To plug in your own generator (a class implementing `GenerateUri`), use `withUriGenerator()`: see
[How it works](how-it-works.md#custom-implementations).

---

← [Previous](dispatching.md) · [Contents](index.md) · [Next](attributes.md) →
