<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes as PHPUnit;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\Http\RoutingMiddleware;

final class RoutingMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    private RoutingMiddleware $middleware;

    /** Handler final de test : mémorise la requête reçue */
    private RequestHandlerInterface&RequestSpy $handler;

    #[PHPUnit\Before]
    public function setUpMiddleware(): void
    {
        $this->factory = new Psr17Factory();
        $dispatcher = EduRoute::recommendedSettings(
            static function (ConfigureRoutes $r): void {
                $r->get('/user/{id:\d+}', 'App\Controller\UserController::show');
                $r->post('/user/{id:\d+}', 'App\Controller\UserController::update');
                $r->get('/café', 'App\Controller\CafeController::index');
            },
            'unused',
        )->disableCache()->dispatcher();

        $this->middleware = new RoutingMiddleware($dispatcher, $this->factory);
        $this->handler = new RequestSpy($this->factory);
    }

    #[PHPUnit\Test]
    public function matchedRouteIsExposedAsRequestAttributes(): void
    {
        $response = $this->middleware->process(
            $this->factory->createServerRequest('GET', 'https://example.org/user/42?tab=profile'),
            $this->handler,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->handler->request);

        $result = $this->handler->request->getAttribute(RoutingMiddleware::ROUTE_ATTRIBUTE);
        self::assertInstanceOf(Matched::class, $result);
        self::assertSame('App\Controller\UserController::show', $result->handler);
        self::assertSame('42', $this->handler->request->getAttribute('id'));
    }

    #[PHPUnit\Test]
    public function encodedPathIsDecodedBeforeDispatch(): void
    {
        $this->middleware->process($this->factory->createServerRequest('GET', '/caf%C3%A9'), $this->handler);

        self::assertNotNull($this->handler->request);
        $result = $this->handler->request->getAttribute(RoutingMiddleware::ROUTE_ATTRIBUTE);
        self::assertInstanceOf(Matched::class, $result);
        self::assertSame('App\Controller\CafeController::index', $result->handler);
    }

    #[PHPUnit\Test]
    public function unknownRouteGivesA404WithoutCallingTheHandler(): void
    {
        $response = $this->middleware->process($this->factory->createServerRequest('GET', '/nowhere'), $this->handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($this->handler->request);
    }

    #[PHPUnit\Test]
    public function wrongMethodGivesA405WithAllowHeader(): void
    {
        $response = $this->middleware->process($this->factory->createServerRequest('DELETE', '/user/42'), $this->handler);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST, HEAD', $response->getHeaderLine('Allow'));
        self::assertNull($this->handler->request);
    }

    #[PHPUnit\Test]
    public function emptyPathIsTreatedAsRoot(): void
    {
        $dispatcher = EduRoute::recommendedSettings(
            static function (ConfigureRoutes $r): void {
                $r->get('/', 'App\Controller\HomeController::index');
            },
            'unused',
        )->disableCache()->dispatcher();

        $response = new RoutingMiddleware($dispatcher, $this->factory)
            ->process($this->factory->createServerRequest('GET', 'https://example.org'), $this->handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->handler->request);
        $result = $this->handler->request->getAttribute(RoutingMiddleware::ROUTE_ATTRIBUTE);
        self::assertInstanceOf(Matched::class, $result);
        self::assertSame('App\Controller\HomeController::index', $result->handler);
    }

    #[PHPUnit\Test]
    public function headRequestIsServedByTheGetRoute(): void
    {
        $response = $this->middleware->process($this->factory->createServerRequest('HEAD', '/user/42'), $this->handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->handler->request);
        self::assertSame('42', $this->handler->request->getAttribute('id'));
    }
}
