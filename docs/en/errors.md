# Errors and exceptions

[Français](../fr/errors.md)

This page lists the exceptions EduRoute throws: when they happen, the exact message the code
produces, and how to fix the underlying problem.

## Overview

Every exception specific to the library implements the
[`Studoo\EduRoute\Exception`](../../src/Exception.php) interface (which extends `Throwable`).

| Exception | Parent class | When | Messages |
|---|---|---|---|
| [`BadRouteException`](#badrouteexception) | `LogicException` | while registering a route | English (French for attribute routes) |
| [`RegexMatchingFailed`](#regexmatchingfailed) | `RuntimeException` | while dispatching, or while checking whether a static route is shadowed | French |
| [`GenerateUri\UriCouldNotBeGenerated`](#uricouldnotbegenerated) | `LogicException` | while generating a URI | English |
| [`Cache\CannotCacheRoutes`](#cannotcacheroutes) | `LogicException` | while writing the file cache | French |

The exceptions extending `LogicException` point to a bug in your code (a malformed route, an
unknown name): fix the code rather than catching them. `RegexMatchingFailed` extends
`RuntimeException` because it also depends on the incoming URI and on PHP's configuration.

Some messages are in French because the library was written for French-speaking students. They
are quoted below exactly as the code produces them, followed by an English translation.

To catch any of them, use the interface:

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\Exception as EduRouteException;

try {
    $dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
        $r->get('/users', 'list_users');
        $r->get('/users', 'list_users_again'); // same route, same method
    }, 'unused')->disableCache()->dispatcher();
} catch (EduRouteException $e) {
    echo $e::class, ': ', $e->getMessage(), PHP_EOL;
}
```

Output:

```text
Studoo\EduRoute\BadRouteException: Cannot register two routes matching "/users" for method "GET"
```

> **Note**
> With `EduRoute`, the route definition function only runs on the first call to `dispatcher()`,
> `uriGenerator()` or `declaredRoutes()` (on the first `dispatch()` with `lazyDispatcher()`), so
> registration errors show up at that point. If a valid [cache](cache.md) file already exists,
> `dispatcher()` and `uriGenerator()` do not run the function at all: a broken route added since
> then is only reported once the cache is rebuilt. Clear the cache during development, or use
> `disableCache()`. `declaredRoutes()`, on the other hand, ignores the cache and always runs the
> function.

## BadRouteException

[`Studoo\EduRoute\BadRouteException`](../../src/BadRouteException.php) — `final` class, extends
`LogicException`.

Thrown by `addRoute()` (and its shortcuts `get()`, `post()`…) when a route cannot be registered.
Checks run in this order:

1. the pattern syntax (optional segments, parameters);
2. the HTTP method(s) (an invalid value throws a `TypeError`, see [Other errors](#other-errors));
3. the route name (`ConfigureRoutes::ROUTE_NAME`);
4. conflicts with routes already registered (duplicate, shadowing).

Since 1.1, a rejected name does not register the route: if you catch the exception, the route
does not become dispatchable anyway.

> **Warning**
> A route declared for several methods (`addRoute(['GET', 'POST'], …)`) is registered one method
> at a time. If the error happens on the second method, the first one stays registered. After a
> `BadRouteException`, do not reuse the collector: create a new one.

### Pattern syntax errors

Thrown by the [`RouteParser\Std`](../../src/RouteParser/Std.php) parser (see
[Defining routes](routes.md)).

| Message | Faulty example | Fix |
|---|---|---|
| `Optional segments can only occur at the end of a route` | `/a[/b]/c` | Move optional segments to the end of the pattern: `/a/c[/b]`, or declare two routes. |
| `Number of opening '[' and closing ']' does not match` | `/a[/b` | Close every `[` with a `]`. |
| `Empty optional part` | `/a[]` | Remove the empty brackets. |
| `Cannot use the same placeholder "id" twice` | `/a/{id}/{id}` | Give each parameter a different name. |
| `Regex "(en\|fr)" for parameter "lang" contains a capturing group` | `/a/{lang:(en\|fr)}` | Use a non-capturing group: `{lang:(?:en\|fr)}`. |

The router wraps each parameter in a capturing group of its own; an extra capturing group in
your pattern would shift the captured values. Hence the restriction, and the `(?:…)` form.

### Invalid route name

| Message | Cause | Fix |
|---|---|---|
| `Route name must be a non-empty string, "''" given` | `[ConfigureRoutes::ROUTE_NAME => '']` | Use a non-empty name. |
| `Route name must be a non-empty string, "42" given` | `[ConfigureRoutes::ROUTE_NAME => 42]` | The name must be a string. The offending value is shown through `var_export()`. |
| `Cannot register two routes under the name "home"` | two routes named `home` | Names must be unique: rename one of the routes. |

See [Generating URLs](uri-generation.md) for what names are used for.

### Route already registered

```text
Cannot register two routes matching "/users" for method "GET"
Cannot register two routes matching "/u/([^/]+)" for method "GET"
```

Two routes share the same pattern for the same HTTP method. For a variable route, the message
shows the generated regular expression, not the original pattern: `/u/{id}` and `/u/{name}` both
become `/u/([^/]+)`, since only the parameter name differs.

Fix: remove the duplicate, or make the patterns differ (for instance `{id:\d+}` on one side).

### Static route shadowed by a variable route

```text
Static route "/user/new" is shadowed by previously defined variable route "/user/([^/]+)" for method "GET"
```

A variable route declared earlier (`/user/{name}`) already matches the URL of the static route
(`/user/new`), so the static route could never be reached. Declare the static route **before**
the variable one:

```php
$r->get('/user/new', 'new_user_form');   // static route first
$r->get('/user/{name}', 'show_user');    // then the variable route
```

In this order no exception is thrown: `/user/new` goes to the form, every other URL to
`show_user`.

### Attribute route errors

[`AttributeRouteLoader::register()`](../../src/Attribute/AttributeRouteLoader.php) also throws
`BadRouteException`, with French messages. See [Attribute routes](attributes.md).

| Message (example) | Meaning | Fix |
|---|---|---|
| `Contrôleur "App\Missing" introuvable` | Controller not found. | Check the class name and that it can be autoloaded. |
| `"App\IC" est une interface, pas un contrôleur instanciable` (also `un trait`, `une enum`) | It is an interface (trait, enum), not an instantiable controller. | Pass a concrete class. |
| `Le contrôleur App\AbsC n'est pas instanciable (classe abstraite ou constructeur non public)` | The controller cannot be instantiated (abstract class or non-public constructor). | Make the class concrete and its constructor public. |
| `#[Route] se place sur une méthode, pas sur la classe App\OnClass : utilisez #[RouteGroup] pour un préfixe commun` | `#[Route]` belongs on a method, not on the class; use `#[RouteGroup]` for a shared prefix. | Move `#[Route]` to a method; use `#[RouteGroup]` for a prefix. |
| `#[RouteGroup] se place sur la classe, pas sur la méthode App\C::m()` | `#[RouteGroup]` belongs on the class, not on a method. | Move `#[RouteGroup]` to the class. |
| `#[Route] sans méthode HTTP sur App\C::m()` | `#[Route]` without any HTTP method. | Remove `methods: []` or list at least one method. |
| `Nom de route vide sur App\C::m()` | Empty route name. | Remove `name: ''` or provide a name. |
| `La méthode App\C::m() porte #[Route] mais doit être publique, non statique, non magique et déclarée dans le contrôleur App\C` | The method carries `#[Route]` but must be public, non-static, non-magic and declared in the controller itself. | Make the method public and non-static, and declare it in the controller itself (not in a parent class). |

When registering the route itself fails (duplicate, syntax error…), the original message is
suffixed with the offending method (« route déclarée sur » means "route declared on"), and the
original exception is available through `getPrevious()`:

```text
Cannot register two routes matching "/admin/a" for method "GET" (route déclarée sur App\Dup::n())
```

## RegexMatchingFailed

[`Studoo\EduRoute\RegexMatchingFailed`](../../src/RegexMatchingFailed.php) — `final` class,
extends `RuntimeException`. Added in 1.0.1.

PHP's regular expression engine (PCRE) failed while testing a route. Without this exception the
failure would look like "no route found", and a valid URL would get a 404 with no explanation
(a bug inherited from FastRoute). The message names the URI or route, then gives the PCRE error
(`preg_last_error_msg()`) in parentheses, then a hint.

### While dispatching

Thrown by `dispatch()`, with all four dispatch strategies. Here is an example with a very
expensive pattern and a deliberately low limit:

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\RegexMatchingFailed;

$dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->get('/{p:(?:a?a?)*}/complicated', 'complicated');
    $r->get('/{p:a+}', 'a');
}, 'unused')->disableCache()->dispatcher();

ini_set('pcre.backtrack_limit', '1000'); // low limit, for the sake of the example

echo $dispatcher->dispatch('GET', '/aaa')->handler, PHP_EOL; // a short URI still works

try {
    $dispatcher->dispatch('GET', '/' . str_repeat('a', 40));
} catch (RegexMatchingFailed $e) {
    echo $e->getMessage(), PHP_EOL;
}
```

Output (the URI is shortened here):

```text
a
Impossible de chercher la route de l'URI "/aaaa…aaaa" : l'expression régulière des routes a échoué (Backtrack limit exhausted). Un paramètre de route utilise sans doute un motif trop coûteux, par exemple {p:(?:a?a?)*} : simplifiez-le, ou augmentez pcre.backtrack_limit / pcre.jit_stacklimit.
```

In English: "Cannot look up the route for URI … : the routes' regular expression failed
(Backtrack limit exhausted). A route parameter probably uses a pattern that is too expensive,
for instance {p:(?:a?a?)*}: simplify it, or raise pcre.backtrack_limit / pcre.jit_stacklimit."

The text in parentheses can also be `JIT stack limit exhausted` (the JIT compiler ran out of
stack). Fixes, best first:

- simplify the parameter regex: avoid nested repetitions such as `(?:a?a?)*` and prefer a
  plain character class (`[a-z]+`, `\d+`, `[^/]+`);
- as a last resort, raise `pcre.backtrack_limit` or `pcre.jit_stacklimit` in `php.ini`.

### Invalid parameter regex

A pattern that does not compile, such as `{p:a)}` (one closing parenthesis too many), is not
detected at registration time. It breaks the combined regular expression of a whole group of
routes, so a `dispatch()` to **another** route in that group fails as well. PHP then emits a
`preg_match(): Compilation failed: …` warning that explains the error, and EduRoute throws:

```text
Impossible de chercher la route de l'URI "/u/1" : l'expression régulière des routes a échoué (Internal error). Le motif d'un paramètre de route est sans doute invalide (le message d'avertissement de preg_match() donne l'erreur de compilation) : corrigez-le.
```

In English: "… (Internal error). A route parameter's pattern is probably invalid (the
preg_match() warning gives the compilation error): fix it."

Fix: read the `preg_match()` warning and correct the parameter regex.

### While checking whether a static route is shadowed

When a static route is registered, EduRoute tests every variable route already declared for the
same method (see [shadowed route](#static-route-shadowed-by-a-variable-route)). If that test
fails, `addRoute()` throws:

```text
Impossible de vérifier si la route statique "/aaaa…aaab/complicated" est masquée par la route variable "/((?:a?a?)*)/complicated" : l'expression régulière a échoué (Backtrack limit exhausted). Simplifiez le motif de la route variable.
```

In English: "Cannot check whether static route … is shadowed by variable route …: the regular
expression failed (Backtrack limit exhausted). Simplify the variable route's pattern."

With an invalid pattern, the message ends with the same hint as above ("A route parameter's
pattern is probably invalid…").

## UriCouldNotBeGenerated

[`Studoo\EduRoute\GenerateUri\UriCouldNotBeGenerated`](../../src/GenerateUri/UriCouldNotBeGenerated.php)
— `final` class, extends `LogicException`.

Thrown by `GenerateUri::forRoute()` (see [Generating URLs](uri-generation.md)). Examples with this
route:

```php
$r->get('/user/{id:\d+}[/{slug}]', 'show_user', [ConfigureRoutes::ROUTE_NAME => 'user.show']);
```

| Call | Message | Fix |
|---|---|---|
| `forRoute('user.edit')` | `There is no route with name "user.edit" defined` | Check the spelling, and that the route is declared with `ROUTE_NAME`. |
| `forRoute('user.show')` | `Route "user.show" expects at least parameter values for [id], but received none` | Pass the required parameters (those outside optional segments). |
| `forRoute('user.show', ['slug' => 'bob'])` | `Route "user.show" expects at least parameter values for [id], but received [slug]` | Same: `id` is missing, `slug` alone is not enough. |
| `forRoute('user.show', ['id' => 'abc'])` | ``Route "user.show" expects the parameter [id] to match the regex `\d+` `` | Pass a value that matches the parameter regex. |

## CannotCacheRoutes

[`Studoo\EduRoute\Cache\CannotCacheRoutes`](../../src/Cache/CannotCacheRoutes.php) — `final`
class, extends `LogicException`.

Thrown by [`FileCache`](../../src/Cache/FileCache.php), the default cache of
`recommendedSettings()`, when the routes contain an object (usually a closure used as a
handler). Such an object cannot be read back from a PHP file: instead of writing a cache that
would cause a fatal error on the next request, `FileCache` refuses to write it and throws on the
very first request. No file is created.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\Cache\CannotCacheRoutes;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    // A closure cannot be written to a cache file
    $r->get('/', static fn (): string => 'accueil');
}, __DIR__ . '/var/cache/routes.php');

try {
    $router->dispatcher();
} catch (CannotCacheRoutes $e) {
    echo $e->getMessage(), PHP_EOL;
}

// Without a cache, the closure works
$handler = $router->disableCache()->dispatcher()->dispatch('GET', '/')->handler;
echo $handler(), PHP_EOL;
```

Output:

```text
Les routes ne peuvent pas être mises en cache dans un fichier : elles contiennent un objet (Closure). Avec un cache, utilisez des handlers exportables (chaîne "App\Controller\UserController::show", tableau, scalaire) ; les closures et objets ne fonctionnent qu'avec ->disableCache().
accueil
```

In English: "Routes cannot be cached in a file: they contain an object (Closure). With a cache,
use exportable handlers (a string such as "App\Controller\UserController::show", an array, a
scalar); closures and objects only work with ->disableCache()."

The type in parentheses (`Closure` here) is the type of the object found. Fixes:

- use exportable handlers: a string such as `'App\Controller\UserController::show'`, an array or
  a scalar ([attribute routes](attributes.md) already produce `Class::method` strings);
- or turn the cache off with `->disableCache()`.

See [Cache](cache.md).

## Other errors

These errors do **not** implement `Studoo\EduRoute\Exception`.

| Error | Message | When |
|---|---|---|
| `TypeError` | `HTTP method must be Studoo\EduRoute\HttpMethod or string, int given` | An array of methods passed to `addRoute()` contains something other than an `HttpMethod` case or a string (here `[405]`). |
| `TypeError` | PHP's own message (`… must be of type Closure, array given …`) | `addGroup()` or `Cache::get()` receives a callable that is not a `Closure`. See [Migration](migration.md). |
| `RuntimeException` | `The cache directory is not writable "<directory>"` | `FileCache` cannot create or write to the cache file's directory. Fix the path or the permissions. |
| `Error` | `Cannot modify readonly property Studoo\EduRoute\Dispatcher\Result\Matched::$handler` | You are changing a property of a dispatch result, which is immutable. |
| `RuntimeException` | `Result cannot be changed` | Writing or calling `unset()` through the deprecated array access (`$result[1] = …`). |
| `OutOfBoundsException` | (empty) | Reading a missing index through the deprecated array access (for instance `$result[3]`). |

Array access on results (`$result[0]`, `$result[1]`…) still works but triggers an
`E_USER_DEPRECATED` notice: see [Dispatching a request](dispatching.md).

---

← [Previous](how-it-works.md) · [Contents](index.md) · [Next](migration.md) →
