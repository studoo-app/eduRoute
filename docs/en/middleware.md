# PSR-15 middleware

[Français](../fr/middleware.md)

In a PSR-7/PSR-15 application, routing usually happens in a middleware: one link in the chain
that processes the request. EduRoute ships
[`RoutingMiddleware`](../../src/Http/RoutingMiddleware.php), which:

- finds the route for the request using a [dispatcher](dispatching.md);
- responds with `404` or `405` straight away when there is no route;
- otherwise, adds the routing result to the request and hands over to the next handler, which
  runs the controller.

## Installation

`RoutingMiddleware` relies on the PSR-15 (middleware) and PSR-17 (factory) interfaces, which are
not installed with EduRoute (only the PSR-7 interfaces are). Add them:

```sh
composer require psr/http-server-middleware psr/http-factory
```

You also need a PSR-7 and PSR-17 implementation (requests, responses and factories), such as
[`nyholm/psr7`](https://github.com/Nyholm/psr7):

```sh
composer require nyholm/psr7
```

## Creating the middleware

The constructor takes two arguments:

```php
public function __construct(
    Dispatcher $dispatcher,                       // the EduRoute dispatcher
    ResponseFactoryInterface $responseFactory,    // PSR-17 factory, for the 404 and 405 responses
)
```

```php
use Studoo\EduRoute\Http\RoutingMiddleware;

$middleware = new RoutingMiddleware($router->dispatcher(), $psr17ResponseFactory);
```

> **Note**
> You can pass `$router->lazyDispatcher()` instead of `$router->dispatcher()`: the routes are then
> only loaded on the first request that actually gets routed (see
> [lazy loading](cache.md#lazy-loading)).

## What the middleware does

| Dispatch result | What `RoutingMiddleware` does |
|---|---|
| [`Matched`](dispatching.md#matched) | adds attributes to the request, then calls `$handler->handle($request)` |
| [`MethodNotAllowed`](dispatching.md#methodnotallowed) | returns a `405` response with an `Allow` header; the next handler is not called |
| [`NotMatched`](dispatching.md#notmatched) | returns a `404` response; the next handler is not called |

The `404` and `405` responses are created by the PSR-17 factory, **with an empty body**.

A complete example: a final handler that "runs" the matched controller, and four requests.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\Http\RoutingMiddleware;

// Final handler: runs the controller chosen by the router
final readonly class ControllerHandler implements RequestHandlerInterface
{
    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = $request->getAttribute(RoutingMiddleware::ROUTE_ATTRIBUTE);
        assert($route instanceof Matched);

        $response = $this->responses->createResponse(200);
        $response->getBody()->write(sprintf(
            '%s, id=%s',
            $route->handler,
            $request->getAttribute('id'), // each route variable becomes an attribute
        ));

        return $response;
    }
}

$factory = new Psr17Factory(); // implements the PSR-17 factory interfaces

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/user/{id:\d+}', 'UserController::show');
        $r->post('/user/{id:\d+}', 'UserController::update');
    },
    'unused',
)->disableCache();

$middleware = new RoutingMiddleware($router->dispatcher(), $factory);
$next = new ControllerHandler($factory);

foreach ([['GET', '/user/42?tab=profile'], ['HEAD', '/user/42'], ['DELETE', '/user/42'], ['GET', '/nowhere']] as [$method, $uri]) {
    $response = $middleware->process($factory->createServerRequest($method, $uri), $next);

    echo $method, ' ', $uri, ' -> ', $response->getStatusCode();
    echo $response->hasHeader('Allow') ? ' Allow: ' . $response->getHeaderLine('Allow') : '';
    echo $response->getBody()->getSize() > 0 ? ' | ' . $response->getBody() : '', PHP_EOL;
}
```

Output:

```text
GET /user/42?tab=profile -> 200 | UserController::show, id=42
HEAD /user/42 -> 200 | UserController::show, id=42
DELETE /user/42 -> 405 Allow: GET, POST, HEAD
GET /nowhere -> 404
```

In a real application, `$middleware` goes into your framework's middleware stack, right before
the handler that calls the controllers.

## Request attributes

When a route matches, the request passed to the next handler gets:

| Attribute | Value |
|---|---|
| `RoutingMiddleware::ROUTE_ATTRIBUTE` | the full [`Matched`](dispatching.md#matched) object: `handler`, `variables`, `extraParameters` |
| one attribute per route variable | `{id}` gives `$request->getAttribute('id')`, always a string |

The `RoutingMiddleware::ROUTE_ATTRIBUTE` constant is
`'Studoo\EduRoute\Dispatcher\Result\Matched'` (the class name). Always use the constant rather
than the string.

The route's [extra parameters](routes.md#extra-parameters) do **not** become attributes: read them from
`$route->extraParameters`.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\Http\RoutingMiddleware;

// Final handler: shows what the middleware added to the request
final readonly class DumpHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = $request->getAttribute(RoutingMiddleware::ROUTE_ATTRIBUTE);

        echo 'attributes: ', implode(', ', array_keys($request->getAttributes())), PHP_EOL;
        echo 'name: ', $request->getAttribute('name'), PHP_EOL;
        echo 'layout (attribute): ', var_export($request->getAttribute('layout'), true), PHP_EOL;
        echo 'layout (extraParameters): ', $route->extraParameters['layout'], PHP_EOL;

        return new Psr17Factory()->createResponse(204);
    }
}

$factory = new Psr17Factory();

$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/café/{name}', 'CafeController::show', ['layout' => 'cafe']);
    },
    'unused',
)->disableCache();

// The middleware gets a lazy dispatcher: nothing is loaded before the first request
$middleware = new RoutingMiddleware($router->lazyDispatcher(), $factory);

// The path arrives encoded (%C3%A9 = é, %20 = space): the middleware decodes it before dispatching
$request = $factory->createServerRequest('GET', '/caf%C3%A9/Jos%C3%A9%20Martin');

echo $middleware->process($request, new DumpHandler())->getStatusCode(), PHP_EOL;
```

Output:

```text
attributes: Studoo\EduRoute\Dispatcher\Result\Matched, name
name: José Martin
layout (attribute): NULL
layout (extraParameters): cafe
204
```

> **Warning**
> A route variable overwrites any attribute with the same name already set on the request (by
> an earlier middleware). Avoid naming your variables after attributes used elsewhere in your
> application.

## How the URI is prepared

The middleware takes care of the [URI preparation](dispatching.md#preparing-the-uri) described for
`dispatch()`:

- only the URI **path** is used: the query string (`?tab=profile`) and the host name are
  ignored;
- the path is decoded with `rawurldecode()`: `/caf%C3%A9` becomes `/café`;
- an empty path becomes `/`.

The HTTP method is passed as is (`$request->getMethod()`).

## `HEAD` and the `Allow` header

The dispatcher serves a `HEAD` request with the matching `GET` route (see the
[`HEAD` to `GET` fallback](dispatching.md#head-falls-back-to-get)). The middleware takes this into account in the `405`
response: when `GET` is among the allowed methods, it appends `HEAD` to the `Allow` header. That
is why the example above shows `Allow: GET, POST, HEAD` even though no `HEAD` route is declared.

> **Note**
> PSR-7 does not strip the body from a response to a `HEAD` request: in the example, the
> response still contains the controller's text. It is usually the web server (or your
> framework's response emitter) that does not send it.

## Customizing the 404 and 405 pages

The middleware's `404` and `405` responses have an empty body. To show a proper error page, put
a middleware **before** `RoutingMiddleware` that inspects the response status:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\Http\RoutingMiddleware;

// Adds a body to empty 404 and 405 responses
final readonly class ErrorPages implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->getBody()->getSize() === 0 && in_array($response->getStatusCode(), [404, 405], true)) {
            $response->getBody()->write(match ($response->getStatusCode()) {
                404 => 'Page not found',
                405 => 'Method not allowed (allowed: ' . $response->getHeaderLine('Allow') . ')',
            });
        }

        return $response;
    }
}

// Chains a middleware with the next handler
final readonly class Next implements RequestHandlerInterface
{
    public function __construct(private MiddlewareInterface $middleware, private RequestHandlerInterface $next)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}

// Final handler: the "controller"
final readonly class Hello implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = new Psr17Factory()->createResponse(200);
        $response->getBody()->write('Hello!');

        return $response;
    }
}

$factory = new Psr17Factory();
$router = EduRoute::recommendedSettings(
    static function (ConfigureRoutes $r): void {
        $r->get('/', 'HomeController::index');
    },
    'unused',
)->disableCache();

// Stack: ErrorPages -> RoutingMiddleware -> Hello
$app = new Next(new ErrorPages(), new Next(new RoutingMiddleware($router->dispatcher(), $factory), new Hello()));

foreach ([['GET', '/'], ['GET', '/missing'], ['POST', '/']] as [$method, $uri]) {
    $response = $app->handle($factory->createServerRequest($method, $uri));
    echo $method, ' ', $uri, ' -> ', $response->getStatusCode(), ' ', $response->getBody(), PHP_EOL;
}
```

Output:

```text
GET / -> 200 Hello!
GET /missing -> 404 Page not found
POST / -> 405 Method not allowed (allowed: GET, HEAD)
```

The `Next` class is only there for the example: your framework already provides the middleware
stack.

## Possible errors

`RoutingMiddleware` does not catch any exception: those thrown by the dispatcher propagate
unchanged. With a lazy dispatcher, this includes route loading errors
([`BadRouteException`](errors.md#badrouteexception),
[`CannotCacheRoutes`](errors.md#cannotcacheroutes)), thrown on the first request. See the
[error reference](errors.md).

---

← [Previous](cache.md) · [Contents](index.md) · [Next](how-it-works.md) →
