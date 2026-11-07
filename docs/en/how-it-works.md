# How it works

[Français](../fr/how-it-works.md)

This page looks under the hood. It explains how EduRoute turns your routes into a handful of
regular expressions, and how it finds the right route with a single call to `preg_match()`.
You don't need any of this to use the router, but it makes a good case study: associative arrays,
PCRE regular expressions and performance measurement.

The algorithm comes from [nikic/FastRoute](https://github.com/nikic/FastRoute). Its author explains
it in depth in the article [Fast request routing using regular expressions](http://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html).

## Overview

Routing goes through three components, each one defined by an interface:

```text
"/user/{id:\d+}[/{name}]"
        |
        v
  RouteParser      splits the pattern into static segments and parameters
        |
        v
  DataGenerator    stores static routes in a hash table
        |          and combines variable routes into large regular expressions
        v
  Dispatcher       finds the route matching an HTTP method and a URI
```

| Role | Interface | Provided implementations |
|---|---|---|
| Parse a pattern | [`RouteParser`](../../src/RouteParser.php) | [`RouteParser\Std`](../../src/RouteParser/Std.php) |
| Build the data | [`DataGenerator`](../../src/DataGenerator.php) | [`MarkBased`](../../src/DataGenerator/MarkBased.php), [`GroupCountBased`](../../src/DataGenerator/GroupCountBased.php), [`GroupPosBased`](../../src/DataGenerator/GroupPosBased.php), [`CharCountBased`](../../src/DataGenerator/CharCountBased.php) |
| Find the route | [`Dispatcher`](../../src/Dispatcher.php) | one class with the same name per strategy in [`src/Dispatcher/`](../../src/Dispatcher/) |

[`EduRoute`](../../src/EduRoute.php) wires these pieces together:

1. it creates a [`RouteCollector`](../../src/RouteCollector.php) with the chosen parser and
   generator, then calls your route definition function;
2. `RouteCollector::processedRoutes()` returns an array `[static routes, variable routes,
   named routes]`; this is the array that gets [cached](cache.md);
3. `dispatcher()` does `new $dispatcher($data)` and `uriGenerator()` does
   `new $uriGenerator($data[2])`.

The data is computed once (or read from the cache); after that, the dispatcher only performs
lookups.

## Step 1: the RouteParser

`RouteParser\Std` reads the pattern syntax (`{name}`, `{name:regex}`, `[...]` for optional
segments, see [Defining routes](routes.md)). It returns **a list of routes**: one version without
the optional segment, one version with it.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\RouteParser\Std;

$parser = new Std();

// A pattern with an optional segment yields two routes
foreach ($parser->parse('/user/{id:\d+}[/{name}]') as $parsedRoute) {
    echo json_encode($parsedRoute, JSON_UNESCAPED_SLASHES), "\n";
}
```

Output:

```text
["/user/",["id","\\d+"]]
["/user/",["id","\\d+"],"/",["name","[^/]+"]]
```

Each route is a sequence of parts:

- a **string** is a static segment (`"/user/"`);
- an **array** `[name, regex]` is a parameter. Without an explicit regex, the parser uses
  `Std::DEFAULT_DISPATCH_REGEX`, i.e. `[^/]+` (anything but a slash).

Invalid patterns are rejected right at this step: unbalanced brackets, an optional segment in the
middle of a route, a parameter declared twice, a regex containing a capturing group. The messages
are listed in [Errors](errors.md).

> **Note**
> Capturing groups are forbidden in parameter regexes (`{id:(\d+)}`) because the next step counts
> and numbers the groups. Use a non-capturing group instead: `{lang:(?:fr|en)}`.

## Step 2: the DataGenerator

The generator receives each parsed route along with its HTTP method and handler
(`DataGenerator::addRoute()`), then produces the dispatcher's data (`getData()`). The four
provided generators share the same base class, [`RegexBasedAbstract`](../../src/DataGenerator/RegexBasedAbstract.php).

### Static routes: a hash table

A route without parameters (`/about`) is stored in a PHP array keyed by method, then by URI:

```text
['GET' => ['/about' => [handler, extraParameters]]]
```

Looking it up is a plain key access: its cost does not depend on the number of routes. That is
why the dispatcher **always checks static routes first**.

### Variable routes: one combined regular expression

Each variable route becomes a regular expression (internal class [`Route`](../../src/Route.php)):
static segments are escaped with `preg_quote()` and each parameter becomes a capturing group.
`/user/{id:\d+}` becomes `/user/(\d+)`.

Testing these expressions one by one would be slow: 100 routes, 100 calls to `preg_match()`.
FastRoute's idea is to merge them into **a single** expression, using `|` alternatives:

```text
~^(?:/user/(\d+)|/user/(\d+)/([^/]+)|/post/([^/]+))$~
```

One call to `preg_match()` is then enough. One question remains: **which alternative matched?**
PCRE does not tell you directly. The four strategies, described [below](#the-four-strategies),
are four different answers to that question.

Here is what the default generator (`MarkBased`) produces for one static route and three variable
routes:

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\DataGenerator\MarkBased;
use Studoo\EduRoute\RouteParser\Std;

$parser = new Std();
$generator = new MarkBased();

$routes = [
    '/about'                => 'about',
    '/user/{id:\d+}'        => 'user.show',
    '/user/{id:\d+}/{name}' => 'user.profile',
    '/post/{slug}'          => 'post.show',
];

foreach ($routes as $pattern => $handler) {
    foreach ($parser->parse($pattern) as $parsedRoute) {
        $generator->addRoute('GET', $parsedRoute, $handler);
    }
}

[$static, $dynamic] = $generator->getData();

// Static routes: method => URI => [handler, extra parameters]
echo json_encode($static, JSON_UNESCAPED_SLASHES), "\n";

// Variable routes: method => list of chunks (regex + lookup table)
echo $dynamic['GET'][0]['regex'], "\n";
echo json_encode($dynamic['GET'][0]['routeMap'], JSON_UNESCAPED_SLASHES), "\n";
```

Output:

```text
{"GET":{"/about":["about",[]]}}
~^(?|/user/(\d+)(*MARK:a)|/user/(\d+)/([^/]+)(*MARK:b)|/post/([^/]+)(*MARK:c))$~
{"a":["user.show",{"id":"id"},[]],"b":["user.profile",{"id":"id","name":"name"},[]],"c":["post.show",{"slug":"slug"},[]]}
```

The `routeMap` maps each route to its handler, the names of its parameters (in group order) and
its extra parameters.

> **Note**
> When called directly, the generator only sees what you pass to it. Through `RouteCollector`, the
> extra parameters also contain the reserved `_route` key (the original pattern), see
> [Defining routes](routes.md).

### Splitting into chunks

A giant expression with hundreds of alternatives becomes slow to compile and run, and may hit PCRE
limits. The variable routes of each method are therefore split into **chunks** whose approximate
size is set by the strategy: about 30 routes for `MarkBased` and `CharCountBased`, about 10 for
`GroupCountBased` and `GroupPosBased`. The actual size is adjusted to balance the chunks:

| Generator | 25 routes | 100 routes |
|---|---|---|
| `MarkBased` | 1 regex (25) | 3 regexes (34 + 34 + 32) |
| `GroupCountBased` | 3 regexes (9 + 9 + 7) | 10 regexes (10 × 10) |

The dispatcher tries the chunks in order and stops at the first one that matches.

### Checks at registration time

The generator catches two mistakes as soon as routes are declared (full messages in
[Errors](errors.md)):

- a route already declared for the same method;
- a static route **shadowed** by a variable route declared before it.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->get('/user/{name}', 'user.show');
    $r->get('/user/me', 'user.me'); // declared AFTER the variable route that covers it
}, 'demo')->disableCache();

try {
    $router->dispatcher();
} catch (BadRouteException $e) {
    echo $e->getMessage(), "\n";
}

$router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->get('/user/me', 'user.me'); // static route first
    $r->get('/user/{name}', 'user.show');
}, 'demo')->disableCache();

$dispatcher = $router->dispatcher();
echo $dispatcher->dispatch('GET', '/user/me')->handler, "\n";
echo $dispatcher->dispatch('GET', '/user/alice')->handler, "\n";
```

Output:

```text
Static route "/user/me" is shadowed by previously defined variable route "/user/([^/]+)" for method "GET"
user.me
user.show
```

In the second router, `/user/me` wins because the static table is checked before the regular
expressions.

Between **variable** routes, however, nothing is checked: the first matching alternative wins, so
declaration order matters.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;

$dispatcher = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->get('/post/{slug}', 'post.bySlug');  // declared first
    $r->get('/post/{id:\d+}', 'post.byId');  // will never be reached
}, 'demo')->disableCache()->dispatcher();

echo $dispatcher->dispatch('GET', '/post/42')->handler, "\n"; // post.bySlug
```

> **Warning**
> Declare variable routes from the most specific to the most general (`{id:\d+}` before `{slug}`).

## Step 3: the Dispatcher

`dispatch($method, $uri)` ([`Dispatcher\RegexBasedAbstract`](../../src/Dispatcher/RegexBasedAbstract.php))
searches in this order and stops at the first hit:

1. the static table of the requested method;
2. the regex chunks of that method;
3. for a `HEAD` request: the static, then variable routes of `GET`;
4. the `'*'` fallback routes (static, then variable).

If nothing matches, it tries the URI against **every other method** to find out whether the route
exists elsewhere: if so it returns `MethodNotAllowed` (with the list of allowed methods),
otherwise `NotMatched`. The results are covered in [Dispatching a request](dispatching.md).

Only step 2 depends on the strategy: each dispatcher implements `dispatchVariableRoute()`, which
reads the result of `preg_match()` to recover the route and its variables.

## The four strategies

All four strategies always give the same result: only the way the matched alternative is
identified differs. The following script builds the same three routes with each of them, prints
the generated expression and dispatches `/user/42/alice`:

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\RouteParser\Std;

// Each strategy pairs a generator with the dispatcher that can read its data
$strategies = [
    'Mark'       => [DataGenerator\MarkBased::class, Dispatcher\MarkBased::class],
    'GroupCount' => [DataGenerator\GroupCountBased::class, Dispatcher\GroupCountBased::class],
    'GroupPos'   => [DataGenerator\GroupPosBased::class, Dispatcher\GroupPosBased::class],
    'CharCount'  => [DataGenerator\CharCountBased::class, Dispatcher\CharCountBased::class],
];

$routes = [
    '/user/{id:\d+}'        => 'user.show',
    '/user/{id:\d+}/{name}' => 'user.profile',
    '/post/{slug}'          => 'post.show',
];

$parser = new Std();

foreach ($strategies as $label => [$generatorClass, $dispatcherClass]) {
    $generator = new $generatorClass();
    foreach ($routes as $pattern => $handler) {
        foreach ($parser->parse($pattern) as $parsedRoute) {
            $generator->addRoute('GET', $parsedRoute, $handler);
        }
    }

    $data = $generator->getData();
    $chunk = $data[1]['GET'][0]; // the only chunk of variable routes

    // Tabs are printed as "\t" to keep the output readable
    $show = static fn (string $text): string => str_replace("\t", '\t', $text);

    echo "== $label\n";
    echo 'regex    : ', $chunk['regex'], "\n";
    if (isset($chunk['suffix'])) {
        echo 'suffix   : ', $show($chunk['suffix']), "\n";
    }
    echo 'routeMap : ', implode(', ', array_map(
        static fn (int|string $key, array $route): string => $show(var_export($key, true)) . ' => ' . $route[0],
        array_keys($chunk['routeMap']),
        $chunk['routeMap'],
    )), "\n";

    $result = (new $dispatcherClass($data))->dispatch('GET', '/user/42/alice');
    echo 'dispatch : ', $result->handler, ' ', json_encode($result->variables), "\n\n";
}
```

Output:

```text
== Mark
regex    : ~^(?|/user/(\d+)(*MARK:a)|/user/(\d+)/([^/]+)(*MARK:b)|/post/([^/]+)(*MARK:c))$~
routeMap : 'a' => user.show, 'b' => user.profile, 'c' => post.show
dispatch : user.profile {"id":"42","name":"alice"}

== GroupCount
regex    : ~^(?|/user/(\d+)|/user/(\d+)/([^/]+)|/post/([^/]+)()())$~
routeMap : 2 => user.show, 3 => user.profile, 4 => post.show
dispatch : user.profile {"id":"42","name":"alice"}

== GroupPos
regex    : ~^(?:/user/(\d+)|/user/(\d+)/([^/]+)|/post/([^/]+))$~
routeMap : 1 => user.show, 3 => user.profile, 4 => post.show
dispatch : user.profile {"id":"42","name":"alice"}

== CharCount
regex    : ~^(?|(?:/user/(\d+)/(\t{1})\t{2})|(?:/user/(\d+)/([^/]+)/(\t{2})\t{1})|(?:/post/([^/]+)/(\t{3})\t{0}))$~
suffix   : /\t\t\t
routeMap : '\t' => user.show, '\t\t' => user.profile, '\t\t\t' => post.show
dispatch : user.profile {"id":"42","name":"alice"}
```

To understand each strategy, let's look at what `preg_match()` returns for the URI `/post/hello`
(the third route) with these expressions:

```php
<?php
declare(strict_types=1);

// The expressions shown above, applied to "/post/hello"
$uri = '/post/hello';

preg_match('~^(?|/user/(\d+)(*MARK:a)|/user/(\d+)/([^/]+)(*MARK:b)|/post/([^/]+)(*MARK:c))$~', $uri, $m);
echo 'Mark       : ', json_encode($m, JSON_UNESCAPED_SLASHES), "\n";

preg_match('~^(?|/user/(\d+)|/user/(\d+)/([^/]+)|/post/([^/]+)()())$~', $uri, $m);
echo 'GroupCount : ', json_encode($m, JSON_UNESCAPED_SLASHES), ' count=', count($m), "\n";

preg_match('~^(?:/user/(\d+)|/user/(\d+)/([^/]+)|/post/([^/]+))$~', $uri, $m);
echo 'GroupPos   : ', json_encode($m, JSON_UNESCAPED_SLASHES), ' last index=', count($m) - 1, "\n";

// CharCount appends the suffix "/" + 3 tabs to the URI
preg_match("~^(?|(?:/user/(\d+)/(\t{1})\t{2})|(?:/user/(\d+)/([^/]+)/(\t{2})\t{1})|(?:/post/([^/]+)/(\t{3})\t{0}))$~", $uri . "/\t\t\t", $m);
echo 'CharCount  : ', json_encode($m, JSON_UNESCAPED_SLASHES), ' last=', strlen(end($m)), " tab(s)\n";
```

Output:

```text
Mark       : {"0":"/post/hello","1":"hello","MARK":"c"}
GroupCount : ["/post/hello","hello","",""] count=4
GroupPos   : ["/post/hello","","","","hello"] last index=4
CharCount  : ["/post/hello/\t\t\t","hello","\t\t\t"] last=3 tab(s)
```

Two PCRE concepts keep coming back:

- `(?|...)` is a **branch reset group**: in each alternative, group numbering restarts at 1. The
  variables of the matched route are therefore always in `$matches[1]`, `$matches[2]`, and so on.
- `(?:...)` is an ordinary non-capturing group: groups are numbered continuously from one
  alternative to the next.

### Mark (default)

Each alternative ends with the PCRE verb `(*MARK:name)`, with a different name per route (`a`,
`b`, `c`… generated with `str_increment()`). When an alternative matches, PHP puts its name in
`$matches['MARK']`. The dispatcher reads `routeMap[$matches['MARK']]`: a direct lookup, no
computation needed.

This is the strategy picked by `EduRoute::recommendedSettings()`. Chunks of about 30 routes.

### GroupCount

Thanks to `(?|...)`, the **number of groups** of the matched alternative can be read from
`count($matches)`. To make that number unique per route, the generator pads with empty groups
`()`: each route has at least one more group than the previous one. Here `/post/([^/]+)` has only
one variable, but gets `()()` to reach 3 groups; `count($matches)` is then 4, which is the key of
`post.show` in the `routeMap`.

This is FastRoute's original strategy. The empty groups make the expression heavier: chunks of
about 10 routes.

### GroupPos

No branch reset: `(?:...)` numbers the groups continuously (`user.show` uses group 1,
`user.profile` groups 2 and 3, `post.show` group 4). PHP leaves out of `$matches` the unused
groups that come **after** the last group used, so the last index of `$matches` is the position of
the matched route's last group. The `routeMap` is keyed by that position, and the variables are
the last `n` groups.

No empty groups are added, but `$matches` contains the groups of the earlier alternatives (as
empty strings): chunks of about 10 routes.

### CharCount

The dispatcher appends a **suffix** to the URI: `/` followed by as many tabs as there are routes
in the chunk (three here). Alternative `i` captures exactly `i` tabs in a final group, then
consumes the rest. The content of that final group, read with `end($matches)`, is the route's key
in the `routeMap`.

The route is identified by a length of text rather than by a group or a name. Chunks of about 30
routes.

### Summary

| `EduRoute` method | Classes | How the route is identified | Chunk size |
|---|---|---|---|
| `useMarkDispatcher()` *(default)* | `DataGenerator\MarkBased` + `Dispatcher\MarkBased` | `$matches['MARK']` | ~30 |
| `useGroupCountDispatcher()` | `DataGenerator\GroupCountBased` + `Dispatcher\GroupCountBased` | `count($matches)` | ~10 |
| `useGroupPosDispatcher()` | `DataGenerator\GroupPosBased` + `Dispatcher\GroupPosBased` | last index of `$matches` | ~10 |
| `useCharCountDispatcher()` | `DataGenerator\CharCountBased` + `Dispatcher\CharCountBased` | length of the captured suffix | ~30 |

```php
$router = EduRoute::recommendedSettings($routes, 'routes')->useGroupCountDispatcher();
```

> **Warning**
> The format of the cached data depends on the strategy. After switching strategies, clear the
> cache or use a different key (see [Cache](cache.md)).

## Comparing the strategies

The repository ships [PHPBench](https://phpbench.readthedocs.io/) benchmarks in
[`benchmark/`](../../benchmark/):

| File | What it measures |
|---|---|
| `RoutingWithRealLifeExampleBench.php` | dispatching against about forty realistic routes (website, shop, admin area) |
| `RoutingWithManyRoutesBench.php` | dispatching against 400 static and 400 variable routes |
| `RouteRegistrationBench.php` | route registration |
| `UriGenerationBench.php` | URL generation |

The first two measure each strategy (the `set` column: `mark`, `group_count`, `group_pos`,
`char_count`) in several situations (the `subject` column): first or last route, static or
variable, wrong method (`staticInvalidMethod`, `dynamicInvalidMethod`: a 405 response), unknown route
(`unknownRoute`: a 404 response).

```bash
make phpbench

# A single file, fewer iterations for a quick run
make phpbench PHPBENCH_OPTIONS="--filter=RoutingWithManyRoutesBench --iterations=2 --revs=50"
```

Read the `mode` column (the most frequent time per call) and `rstdev` (the spread: above a few
percent, run the measurement again). A few observations worth checking for yourself:

- static routes cost about the same with every strategy (a key lookup);
- the gap widens on the **last** variable route and on 404/405 responses, which go through every
  chunk;
- `Mark` is usually the fastest, which is why it is the default.

`make phpbench-compare` compares a new run against a reference stored locally under the
`baseline_upstream` tag. Record it first, for instance before making a change:
`make phpbench PHPBENCH_OPTIONS="--store --tag=baseline_upstream"`.

> **Note**
> Xdebug heavily skews the measurements: the `make phpbench` target turns it off
> (`XDEBUG_MODE=off`).

## Custom implementations

Two `EduRoute` methods let you plug in your own classes. Like the other `use*()` and `with*()`
methods, they return a new instance.

- `useCustomDispatcher(string $dataGenerator, string $dispatcher)`: a class implementing
  `DataGenerator` and a class implementing `Dispatcher`. EduRoute builds the dispatcher with
  `new $dispatcher($data)`, where `$data` is the array produced by the generator, followed by the
  named routes.
- `withUriGenerator(string $uriGenerator)`: a class implementing `GenerateUri`. EduRoute builds it
  with `new $uriGenerator($namedRoutes)`.

Both methods expect **class names**. The following example decorates the provided
implementations: a dispatcher that logs every request, and a generator that prefixes URLs with the
language.

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\GenerateUri;
use Studoo\EduRoute\GenerateUri\FromProcessedConfiguration;
use Studoo\EduRoute\GenerateUri\GeneratedUri;
use Studoo\EduRoute\HttpMethod;

/**
 * Logs every request, then delegates to the Mark dispatcher.
 * The constructor receives the data of the chosen DataGenerator (MarkBased here).
 */
final class LoggingDispatcher implements Dispatcher
{
    private Dispatcher $inner;

    public function __construct(array $data)
    {
        $this->inner = new Dispatcher\MarkBased($data);
    }

    public function dispatch(HttpMethod|string $httpMethod, string $uri): Matched|NotMatched|MethodNotAllowed
    {
        $result = $this->inner->dispatch($httpMethod, $uri);
        $method = $httpMethod instanceof HttpMethod ? $httpMethod->value : $httpMethod;
        echo '[log] ', $method, ' ', $uri, ' -> ', $result::class, "\n";

        return $result;
    }
}

/**
 * Adds a language prefix to generated URLs.
 * The constructor receives the named routes.
 */
final class LocalizedUriGenerator implements GenerateUri
{
    private GenerateUri $inner;

    public function __construct(array $namedRoutes)
    {
        $this->inner = new FromProcessedConfiguration($namedRoutes);
    }

    public function forRoute(string $name, array $substitutions = []): GeneratedUri
    {
        $uri = $this->inner->forRoute($name, $substitutions);

        return new GeneratedUri('/fr' . $uri->path, $uri->unmatchedSubstitutions);
    }
}

$router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
    $r->get('/article/{id:\d+}', 'article.show', [ConfigureRoutes::ROUTE_NAME => 'article']);
}, 'demo')
    ->disableCache()
    ->useCustomDispatcher(DataGenerator\MarkBased::class, LoggingDispatcher::class)
    ->withUriGenerator(LocalizedUriGenerator::class);

$router->dispatcher()->dispatch('GET', '/article/7');
$router->dispatcher()->dispatch('GET', '/nope');

echo $router->uriGenerator()->forRoute('article', ['id' => '7']), "\n";
```

Output:

```text
[log] GET /article/7 -> Studoo\EduRoute\Dispatcher\Result\Matched
[log] GET /nope -> Studoo\EduRoute\Dispatcher\Result\NotMatched
/fr/article/7
```

> **Warning**
> The generator and the dispatcher must speak the same data format: always pair a dispatcher with
> the generator it knows how to read. The `RegexBasedAbstract` classes in `src/DataGenerator/` and
> `src/Dispatcher/` are marked `@internal`: for an entirely new strategy, implement the
> `DataGenerator` and `Dispatcher` interfaces directly.

A custom dispatcher's data goes through the same cache as any other: its handlers must remain
exportable (see [Cache](cache.md)). The custom dispatcher also benefits from the lazy loading of
`lazyDispatcher()`.

## Further reading

- [Fast request routing using regular expressions](http://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html),
  nikic's original article;
- PHP's PCRE documentation on [subpatterns](https://www.php.net/manual/en/regexp.reference.subpatterns.php)
  (including `(?|...)`) and, for `(*MARK)`, the [PCRE2 documentation](https://www.pcre.org/current/doc/html/pcre2pattern.html);
- the code: [`RouteParser\Std`](../../src/RouteParser/Std.php),
  [`DataGenerator\MarkBased`](../../src/DataGenerator/MarkBased.php),
  [`Dispatcher\MarkBased`](../../src/Dispatcher/MarkBased.php); each strategy fits in about fifty
  lines.

---

← [Previous](middleware.md) · [Contents](index.md) · [Next](errors.md) →
