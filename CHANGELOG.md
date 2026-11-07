# Changelog

All notable changes to `studoo/edu-route` are recorded here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/).

`studoo/edu-route` is a modernized fork of [nikic/FastRoute](https://github.com/nikic/FastRoute)
(`master` branch, commit `1c96139`, April 2024). To migrate, see the migration guide
([English](docs/en/migration.md), [français](docs/fr/migration.md)).

## [Unreleased]

### Documentation

- Full documentation in English and French in `docs/en/` and `docs/fr/` (11 pages each: routes,
  dispatching, URL generation, attributes, route listing, cache, middleware, internals, errors,
  migration). The README is now in English (`README.fr.md` for French), `UPGRADE.md` points to
  the migration pages, and this changelog is now written in English.

## [1.1.0] — 2026-11-07

### Added

- **List of declared routes**, to write a `routes:list` command
  ([nikic/FastRoute#247](https://github.com/nikic/FastRoute/issues/247),
  [#285](https://github.com/nikic/FastRoute/issues/285)): `RouteCollector::declaredRoutes()` and
  `EduRoute::declaredRoutes()` return `DeclaredRoute` objects (method, original pattern,
  handler, name, extra parameters), in declaration order, one entry per HTTP method.
  `DeclaredRoute::describeHandler()` gives a printable description of the handler and
  `isFallback()` identifies `any()` routes. The format of the processed data and of the cache
  is unchanged.

### Fixed

- An invalid or already used route name is rejected **before** the route is registered:
  if the exception was caught, the route used to remain dispatchable. As a consequence, an
  error on the name (empty, not a string, already used) is now reported before route
  registration errors (duplicate route, static route shadowed by a variable route); pattern
  syntax errors and HTTP method errors are still reported first.

## [1.0.1] — 2026-11-07

### Fixed

- When the regular expression engine failed while looking for a route (`pcre.backtrack_limit`
  or JIT stack exhausted by a costly pattern such as `{p:(?:a?a?)*}`), the failure was taken
  for "no route": a valid URL got an unexplained 404. The four dispatchers now throw
  `RegexMatchingFailed`, with the URI and the PCRE error. The check for static routes shadowed
  by a variable route also reports the failure instead of concluding that nothing is shadowed.
  A parameter pattern that does not compile (for example `{p:a)}`) is reported as invalid.
  Bug inherited from FastRoute ([nikic/FastRoute#167](https://github.com/nikic/FastRoute/issues/167)).

## [1.0.0] — 2026-11-06

First release. Compared with FastRoute:

### Breaking changes

- PHP **8.4** minimum (`~8.4.0 || ~8.5.0`).
- Namespace **`Studoo\EduRoute\`** (instead of `FastRoute\`); the `FastRoute\FastRoute` builder
  becomes `Studoo\EduRoute\EduRoute`.
- Removed: the `FastRoute\simpleDispatcher()` and `FastRoute\cachedDispatcher()` functions
  (replaced by `EduRoute::recommendedSettings()`), and `RouteCollector::getData()`
  (replaced by `processedRoutes()`).
- **Immutable** result objects (`Matched`, `MethodNotAllowed`, `NotMatched`): `readonly` classes
  with write-once properties (`public(set) readonly`). Properties no longer have default values;
  any change after creation throws an `Error`.
- Concrete classes are natively `final` (previously `@final` in PHPDoc): `RouteCollector`,
  `RouteParser\Std`, dispatchers, data generators, `BadRouteException`.
- Typed public constants (`Dispatcher::FOUND`: `int`, `ConfigureRoutes::ROUTE_NAME`: `string`…).
- `ConfigureRoutes::addGroup()` and `Cache::get()` expect a `Closure` (no longer a `callable`).

### Fixed

- `GroupPosBased` sent the request to the wrong route (with warnings and `null` variables) when
  a parameter captured an empty string, for example `{slug:[a-z]*}` on `/page/`. Bug inherited
  from FastRoute. The route is now found from the position of its *last* group (no more loop,
  up to −13 % on dynamic routes); the cached data format of this strategy changes.
- A cache file in another format (for example written by FastRoute 1.x) is detected and
  regenerated, instead of causing errors at dispatch time (`FileCache` and `Psr16Cache`).
- Routes containing an object (closure…) are no longer written to an unreadable cache file
  (fatal error on the next request): `CannotCacheRoutes` is thrown on the first request.

### Added

- **`HttpMethod`** enum (`Get`, `Post`, `Put`, `Patch`, `Delete`, `Head`, `Options`, `Any`),
  accepted alongside strings by `addRoute()` and `dispatch()`:
  `addRoute([HttpMethod::Put, 'PATCH'], …)`, `dispatch(HttpMethod::Get, $uri)`. A value that is
  neither a string nor an enum case throws a `TypeError`.
- **Attribute routes**: `#[Route]` (repeatable) on controller methods and `#[RouteGroup]` (path
  and name prefix) on the class, registered by `AttributeRouteLoader::register()`. The handler
  is the string `Class::method`, which works with the cache. Errors (non-instantiable controller,
  inherited, static or magic method, misplaced attribute, empty name, no HTTP method, duplicate
  route) are reported with the controller and method at fault.
- **Lazy loading**: `EduRoute::lazyDispatcher()` and `lazyUriGenerator()` (PHP 8.4 "ghost"
  *lazy objects*) only load the routes on the first call, with no overhead afterwards (a *proxy*
  cost +30 to +76 % per dispatch with the JIT).
- **PSR-15 middleware** `Http\RoutingMiddleware`: request attributes for the route and its
  variables, 404 and 405 responses (`Allow` header, `HEAD` included with `GET`).
  `psr/http-server-middleware` and `psr/http-factory` are suggested, not required.
- `#[\Override]` on every overriding method (enforced by PHPStan).
- Documentation in French: README, UPGRADE.

### Deprecated

- Array access to results (`$result[0]`, `[1]`, `[2]`, `isset($result[1])`) triggers an
  `E_USER_DEPRECATED` (`#[\Deprecated]`): use `instanceof` and the properties. The
  `Dispatcher::FOUND`, `NOT_FOUND` and `METHOD_NOT_ALLOWED` constants, only meaningful with that
  access, are deprecated. Removal planned for 2.0.

### Changed

- The `EduRoute` builder returns a new instance from every `with*()` / `use*()` method by
  cloning; routes already computed *in memory* are never reused by another configuration (a
  cache file, however, must be deleted when changing strategy).
- `readonly` classes for `GeneratedUri`, `FromProcessedConfiguration`, `Psr16Cache` and `Route`.
- Dispatch: a single lookup in the route tables instead of `isset()` followed by two reads,
  which offsets most of the cost of immutability. Measured against the previous version
  (2 runs, 75 measurements): median +1.0 to +1.4 %, within measurement noise; static routes
  −1 to −9 %, first dynamic route +2 to +8 %.
- `str_increment()` instead of `++` on a string (deprecated since PHP 8.3).

### Rejected after measurement

- Result objects built through a constructor: +10 % per dynamic route found, +25 % cold
  (one function call per dispatch). Write-once properties give the same guarantee without that cost.
- Memoizing static results: −34 % on a persistent worker, but +36 % cold (PHP-FPM) and more
  memory.
- `array_find()` to detect a shadowed static route: +9 % on route registration.

### Tooling

- PHPUnit 13.4, PHPStan 2.3 max level + strict rules, lcobucci/coding-standard 12.1,
  Rector 2.8 (blocking), phpbench 1.7; strict test suite (fails on any unexpected deprecation).
- GitHub Actions CI: PHP 8.4 / 8.5 × lowest / locked / highest dependencies, PHPStan, PHPCS,
  Rector, performance comparison on every PR (15 % threshold), Roave backward-compatibility check.
