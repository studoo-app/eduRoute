<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test;

use Closure;
use Override;
use PHPUnit\Framework\Attributes as PHPUnit;
use PHPUnit\Framework\TestCase;
use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\DeclaredRoute;
use Studoo\EduRoute\Dispatcher\MarkBased as DispatcherMarkBased;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\HttpMethod;
use Studoo\EduRoute\RouteCollector;
use Studoo\EduRoute\RouteParser\Std;

use function array_map;
use function assert;
use function count;
use function is_string;
use function ksort;

final class RouteCollectorTest extends TestCase
{
    #[PHPUnit\Test]
    public function shortcutsCanBeUsedToRegisterRoutes(): void
    {
        $dataGenerator = $this->dummyDataGenerator();
        $r = new RouteCollector(new Std(), $dataGenerator);

        $r->any('/any', 'any');
        $r->delete('/delete', 'delete');
        $r->get('/get', 'get');
        $r->head('/head', 'head');
        $r->patch('/patch', 'patch');
        $r->post('/post', 'post');
        $r->put('/put', 'put');
        $r->options('/options', 'options');

        $expected = [
            ['*', '/any', 'any', ['_route' => '/any']],
            ['DELETE', '/delete', 'delete', ['_route' => '/delete']],
            ['GET', '/get', 'get', ['_route' => '/get']],
            ['HEAD', '/head', 'head', ['_route' => '/head']],
            ['PATCH', '/patch', 'patch', ['_route' => '/patch']],
            ['POST', '/post', 'post', ['_route' => '/post']],
            ['PUT', '/put', 'put', ['_route' => '/put']],
            ['OPTIONS', '/options', 'options', ['_route' => '/options']],
        ];

        self::assertObjectHasProperty('routes', $dataGenerator);
        self::assertSame($expected, $dataGenerator->routes);
    }

    #[PHPUnit\Test]
    public function routesCanBeGrouped(): void
    {
        $dataGenerator = $this->dummyDataGenerator();
        $r = new RouteCollector(new Std(), $dataGenerator);

        $r->delete('/delete', 'delete');
        $r->get('/get', 'get');
        $r->head('/head', 'head');
        $r->patch('/patch', 'patch');
        $r->post('/post', 'post');
        $r->put('/put', 'put');
        $r->options('/options', 'options');

        $r->addGroup('/group-one', static function (ConfigureRoutes $r): void {
            $r->delete('/delete', 'delete');
            $r->get('/get', 'get');
            $r->head('/head', 'head');
            $r->patch('/patch', 'patch');
            $r->post('/post', 'post');
            $r->put('/put', 'put');
            $r->options('/options', 'options');

            $r->addGroup('/group-two', static function (ConfigureRoutes $r): void {
                $r->delete('/delete', 'delete');
                $r->get('/get', 'get');
                $r->head('/head', 'head');
                $r->patch('/patch', 'patch');
                $r->post('/post', 'post');
                $r->put('/put', 'put');
                $r->options('/options', 'options');
            });
        });

        $r->addGroup('/admin', static function (ConfigureRoutes $r): void {
            $r->get('-some-info', 'admin-some-info');
        });
        $r->addGroup('/admin-', static function (ConfigureRoutes $r): void {
            $r->get('more-info', 'admin-more-info');
        });

        $expected = [
            ['DELETE', '/delete', 'delete', ['_route' => '/delete']],
            ['GET', '/get', 'get', ['_route' => '/get']],
            ['HEAD', '/head', 'head', ['_route' => '/head']],
            ['PATCH', '/patch', 'patch', ['_route' => '/patch']],
            ['POST', '/post', 'post', ['_route' => '/post']],
            ['PUT', '/put', 'put', ['_route' => '/put']],
            ['OPTIONS', '/options', 'options', ['_route' => '/options']],
            ['DELETE', '/group-one/delete', 'delete', ['_route' => '/group-one/delete']],
            ['GET', '/group-one/get', 'get', ['_route' => '/group-one/get']],
            ['HEAD', '/group-one/head', 'head', ['_route' => '/group-one/head']],
            ['PATCH', '/group-one/patch', 'patch', ['_route' => '/group-one/patch']],
            ['POST', '/group-one/post', 'post', ['_route' => '/group-one/post']],
            ['PUT', '/group-one/put', 'put', ['_route' => '/group-one/put']],
            ['OPTIONS', '/group-one/options', 'options', ['_route' => '/group-one/options']],
            ['DELETE', '/group-one/group-two/delete', 'delete', ['_route' => '/group-one/group-two/delete']],
            ['GET', '/group-one/group-two/get', 'get', ['_route' => '/group-one/group-two/get']],
            ['HEAD', '/group-one/group-two/head', 'head', ['_route' => '/group-one/group-two/head']],
            ['PATCH', '/group-one/group-two/patch', 'patch', ['_route' => '/group-one/group-two/patch']],
            ['POST', '/group-one/group-two/post', 'post', ['_route' => '/group-one/group-two/post']],
            ['PUT', '/group-one/group-two/put', 'put', ['_route' => '/group-one/group-two/put']],
            ['OPTIONS', '/group-one/group-two/options', 'options', ['_route' => '/group-one/group-two/options']],
            ['GET', '/admin-some-info', 'admin-some-info', ['_route' => '/admin-some-info']],
            ['GET', '/admin-more-info', 'admin-more-info', ['_route' => '/admin-more-info']],
        ];

        self::assertObjectHasProperty('routes', $dataGenerator);
        self::assertSame($expected, $dataGenerator->routes);
    }

    #[PHPUnit\Test]
    public function namedRoutesShouldBeRegistered(): void
    {
        $dataGenerator = $this->dummyDataGenerator();

        $r = new RouteCollector(new Std(), $dataGenerator);
        $r->get('/', 'index-handler', ['_name' => 'index']);
        $r->get('/users/me', 'fetch-user-handler', ['_name' => 'users.fetch']);

        self::assertSame(['index' => [['/']], 'users.fetch' => [['/users/me']]], $r->processedRoutes()[2]);
    }

    #[PHPUnit\Test]
    public function cannotDefineRouteWithEmptyName(): void
    {
        $r = new RouteCollector(new Std(), $this->dummyDataGenerator());

        $this->expectException(BadRouteException::class);
        $r->get('/', 'index-handler', ['_name' => '']);
    }

    #[PHPUnit\Test]
    public function cannotDefineRouteWithInvalidTypeAsName(): void
    {
        $r = new RouteCollector(new Std(), $this->dummyDataGenerator());

        $this->expectException(BadRouteException::class);
        $r->get('/', 'index-handler', ['_name' => false]);
    }

    #[PHPUnit\Test]
    public function cannotDefineDuplicatedRouteName(): void
    {
        $r = new RouteCollector(new Std(), $this->dummyDataGenerator());

        $this->expectException(BadRouteException::class);
        $r->get('/', 'index-handler', ['_name' => 'index']);
        $r->get('/users/me', 'fetch-user-handler', ['_name' => 'index']);
    }

    #[PHPUnit\Test]
    public function declaredRoutesAreEmptyByDefault(): void
    {
        self::assertSame([], $this->collector()->declaredRoutes());
    }

    #[PHPUnit\Test]
    public function declaredRoutesKeepDeclarationOrder(): void
    {
        $r = $this->collector();
        $r->get('/user/{id:\d+}', 'show');
        $r->get('/users', 'list');
        $r->any('/{page:.*}', 'fallback');
        $r->post('/users', 'create');

        self::assertSame(
            [['GET', '/user/{id:\d+}', 'show'], ['GET', '/users', 'list'], ['*', '/{page:.*}', 'fallback'], ['POST', '/users', 'create']],
            $this->summary($r->declaredRoutes()),
        );
    }

    #[PHPUnit\Test]
    public function oneEntryPerHttpMethod(): void
    {
        $r = $this->collector();
        $r->addRoute([HttpMethod::Put, 'PATCH', 'PURGE'], '/item/{id}', 'update', ['_name' => 'item.update']);

        $routes = $r->declaredRoutes();

        self::assertSame(
            [['PUT', '/item/{id}', 'update'], ['PATCH', '/item/{id}', 'update'], ['PURGE', '/item/{id}', 'update']],
            $this->summary($routes),
        );
        self::assertSame(['item.update', 'item.update', 'item.update'], array_map(static fn (DeclaredRoute $d): ?string => $d->name, $routes));
    }

    #[PHPUnit\Test]
    public function emptyMethodListDeclaresNothing(): void
    {
        $r = $this->collector();
        $r->addRoute([], '/nothing', 'handler');

        self::assertSame([], $r->declaredRoutes());
    }

    #[PHPUnit\Test]
    public function shortcutsAreDeclaredWithTheirMethod(): void
    {
        $r = $this->collector();
        $r->any('/any', 'any');
        $r->head('/head', 'head');
        $r->get('/get', 'get');

        $routes = $r->declaredRoutes();

        self::assertSame([['*', '/any', 'any'], ['HEAD', '/head', 'head'], ['GET', '/get', 'get']], $this->summary($routes));
        self::assertSame([true, false, false], array_map(static fn (DeclaredRoute $d): bool => $d->isFallback(), $routes));
    }

    #[PHPUnit\Test]
    public function optionalSegmentsAreNotExpanded(): void
    {
        $r = $this->collector();
        $r->get('/article/{id:\d+}[/{slug}]', 'article');

        self::assertSame([['GET', '/article/{id:\d+}[/{slug}]', 'article']], $this->summary($r->declaredRoutes()));
    }

    #[PHPUnit\Test]
    public function groupPrefixIsIncluded(): void
    {
        $r = $this->collector();
        $r->addGroup('/admin', static function (ConfigureRoutes $r): void {
            $r->addGroup('/user', static function (ConfigureRoutes $r): void {
                $r->get('/{id}', 'admin-user');
            });
            $r->get('', 'admin');
        });
        $r->get('/after', 'after');

        self::assertSame(
            [['GET', '/admin/user/{id}', 'admin-user'], ['GET', '/admin', 'admin'], ['GET', '/after', 'after']],
            $this->summary($r->declaredRoutes()),
        );
    }

    #[PHPUnit\Test]
    public function nameIsExposedAndReservedKeysAreRemoved(): void
    {
        $r = $this->collector();
        $r->get('/named', 'named', ['_name' => 'home', '_route' => '/forged', 'section' => 'blog', 'public' => true]);
        $r->get('/anonymous', 'anonymous');

        [$named, $anonymous] = $r->declaredRoutes();

        self::assertSame('home', $named->name);
        self::assertSame('/named', $named->pattern);
        self::assertSame(['section' => 'blog', 'public' => true], $named->extraParameters);
        self::assertNull($anonymous->name);
        self::assertSame([], $anonymous->extraParameters);
    }

    #[PHPUnit\Test]
    public function extraParametersMatchThoseOfMatched(): void
    {
        $r = $this->collector();
        $r->get('/user/{id}', 'user', ['_name' => 'user', 'section' => 'members']);

        $result = new DispatcherMarkBased($r->processedRoutes())->dispatch('GET', '/user/7');
        self::assertInstanceOf(Matched::class, $result);

        $declared = $r->declaredRoutes()[0];
        $expected = $declared->extraParameters + ['_route' => $declared->pattern, '_name' => $declared->name];
        $actual = $result->extraParameters;
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    #[PHPUnit\Test]
    public function handlersAreReturnedAsIs(): void
    {
        $handler = static fn (): string => 'ok';
        $r = $this->collector();
        $r->get('/', $handler);

        self::assertSame($handler, $r->declaredRoutes()[0]->handler);
    }

    /** @param Closure(RouteCollector):void $declare */
    #[PHPUnit\Test]
    #[PHPUnit\DataProvider('provideRejectedRoutes')]
    public function rejectedRoutesAreNotListed(Closure $declare): void
    {
        $r = $this->collector();
        $r->get('/user/{name}', 'kept', ['_name' => 'kept']);

        try {
            $declare($r);
            self::fail('A BadRouteException was expected');
        } catch (BadRouteException) {
        }

        self::assertSame([['GET', '/user/{name}', 'kept']], $this->summary($r->declaredRoutes()));
    }

    /** @return iterable<string, array{Closure(RouteCollector):void}> */
    public static function provideRejectedRoutes(): iterable
    {
        yield 'route en double' => [static fn (RouteCollector $r) => $r->get('/user/{name}', 'twice')];
        yield 'route masquée' => [static fn (RouteCollector $r) => $r->get('/user/nikic', 'shadowed')];
        yield 'nom vide' => [static fn (RouteCollector $r) => $r->get('/a', 'a', ['_name' => ''])];
        yield 'nom non textuel' => [static fn (RouteCollector $r) => $r->get('/a', 'a', ['_name' => 12])];
        yield 'nom en double' => [static fn (RouteCollector $r) => $r->get('/a', 'a', ['_name' => 'kept'])];
        yield 'motif invalide' => [static fn (RouteCollector $r) => $r->get('/a/{x}/{x}', 'a')];
    }

    /** Régression : un nom refusé laissait la route dispatchable si l'exception était attrapée. */
    #[PHPUnit\Test]
    #[PHPUnit\DataProvider('provideRefusedNames')]
    public function refusedNameDoesNotRegisterTheRoute(mixed $name): void
    {
        $r = $this->collector();
        $r->get('/home', 'home', ['_name' => 'home']);

        try {
            $r->get('/refused', 'refused', ['_name' => $name]); // @phpstan-ignore argument.type (nom invalide testé)
            self::fail('A BadRouteException was expected');
        } catch (BadRouteException) {
        }

        self::assertInstanceOf(NotMatched::class, new DispatcherMarkBased($r->processedRoutes())->dispatch('GET', '/refused'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideRefusedNames(): iterable
    {
        yield 'nom vide' => [''];
        yield 'nom non textuel' => [false];
        yield 'nom en double' => ['home'];
    }

    /** @param class-string<DataGenerator> $dataGenerator */
    #[PHPUnit\Test]
    #[PHPUnit\DataProvider('provideDataGenerators')]
    public function listIsIdenticalForEveryDataGenerator(string $dataGenerator): void
    {
        $r = new RouteCollector(new Std(), new $dataGenerator());
        $expected = [];

        // Plus de routes dynamiques qu'un chunk n'en contient, pour en forcer plusieurs
        for ($i = 0; $i < 45; ++$i) {
            $r->get('/item' . $i . '/{id:\d+}[/{slug}]', 'item' . $i);
            $r->get('/static' . $i, 'static' . $i);
            $expected[] = ['GET', '/item' . $i . '/{id:\d+}[/{slug}]', 'item' . $i];
            $expected[] = ['GET', '/static' . $i, 'static' . $i];
        }

        self::assertSame($expected, $this->summary($r->declaredRoutes()));
    }

    /** @return iterable<string, array{class-string<DataGenerator>}> */
    public static function provideDataGenerators(): iterable
    {
        yield 'MarkBased' => [DataGenerator\MarkBased::class];
        yield 'GroupCountBased' => [DataGenerator\GroupCountBased::class];
        yield 'GroupPosBased' => [DataGenerator\GroupPosBased::class];
        yield 'CharCountBased' => [DataGenerator\CharCountBased::class];
    }

    private function collector(): RouteCollector
    {
        return new RouteCollector(new Std(), new DataGenerator\MarkBased());
    }

    /**
     * @param list<DeclaredRoute> $routes
     *
     * @return list<array{string, string, mixed}>
     */
    private function summary(array $routes): array
    {
        return array_map(static fn (DeclaredRoute $d): array => [$d->method, $d->pattern, $d->handler], $routes);
    }

    private function dummyDataGenerator(): DataGenerator
    {
        return new class implements DataGenerator
        {
            /** @var list<array{string, string, mixed, array<string, bool|float|int|string>}> */
            public array $routes = [];

            /** @inheritDoc */
            #[Override]
            public function getData(): array
            {
                return [[], []];
            }

            /** @inheritDoc */
            #[Override]
            public function addRoute(string $httpMethod, array $routeData, mixed $handler, array $extraParameters = []): void
            {
                assert(count($routeData) === 1 && is_string($routeData[0]));

                $this->routes[] = [$httpMethod, $routeData[0], $handler, $extraParameters];
            }
        };
    }
}
