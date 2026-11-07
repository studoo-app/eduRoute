<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Dispatcher;

use Closure;
use Error;
use PHPUnit\Framework\Attributes as PHPUnit;
use PHPUnit\Framework\TestCase;
use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\RegexMatchingFailed;

use function ini_get;
use function ini_set;
use function str_repeat;

/** @phpstan-import-type ExtraParameters from DataGenerator */
abstract class DispatcherTestCase extends TestCase
{
    /**
     * Delegate dispatcher selection to child test classes
     *
     * @return class-string<Dispatcher>
     */
    abstract protected function getDispatcherClass(): string;

    /**
     * Delegate dataGenerator selection to child test classes
     *
     * @return class-string<DataGenerator>
     */
    abstract protected function getDataGeneratorClass(): string;

    /**
     * Construit le dispatcher testé via l'API recommandée (sans cache)
     *
     * @param Closure(ConfigureRoutes):void $callback
     */
    private function createDispatcher(Closure $callback): Dispatcher
    {
        return EduRoute::recommendedSettings($callback, 'unused')
            ->disableCache()
            ->useCustomDispatcher($this->getDataGeneratorClass(), $this->getDispatcherClass())
            ->dispatcher();
    }

    /**
     * @param array<string, string> $argDict
     * @param ExtraParameters       $extraParameters
     */
    #[PHPUnit\Test]
    #[PHPUnit\DataProvider('provideFoundDispatchCases')]
    #[PHPUnit\IgnoreDeprecations('Result\\\\(Matched|MethodNotAllowed|NotMatched)::offset(Get|Exists)\\(\\) is deprecated')]
    public function foundDispatches(
        string $method,
        string $uri,
        Closure $callback,
        string $handler,
        array $argDict = [],
        array $extraParameters = [],
    ): void {
        $dispatcher = $this->createDispatcher($callback);
        $info = $dispatcher->dispatch($method, $uri);

        self::assertInstanceOf(Matched::class, $info);
        self::assertSame($handler, $info->handler);
        self::assertSame($argDict, $info->variables);
        self::assertSame($extraParameters, $info->extraParameters);

        // Accès tableau historique (déprécié, encore supporté)
        self::assertSame($dispatcher::FOUND, $info[0]); // @phpstan-ignore classConstant.deprecated (accès historique testé)
        self::assertSame($handler, $info[1]);
        self::assertSame($argDict, $info[2]);
    }

    #[PHPUnit\Test]
    #[PHPUnit\DataProvider('provideNotFoundDispatchCases')]
    #[PHPUnit\IgnoreDeprecations('Result\\\\(Matched|MethodNotAllowed|NotMatched)::offset(Get|Exists)\\(\\) is deprecated')]
    public function notFoundDispatches(string $method, string $uri, Closure $callback): void
    {
        $dispatcher = $this->createDispatcher($callback);
        $routeInfo = $dispatcher->dispatch($method, $uri);

        self::assertInstanceOf(NotMatched::class, $routeInfo);

        // Accès tableau historique (déprécié, encore supporté)
        self::assertArrayNotHasKey(
            1,
            $routeInfo,
            'NOT_FOUND result must only contain a single element in the returned info array',
        );
        self::assertSame($dispatcher::NOT_FOUND, $routeInfo[0]); // @phpstan-ignore classConstant.deprecated (accès historique testé)
    }

    /** @param string[] $availableMethods */
    #[PHPUnit\Test]
    #[PHPUnit\DataProvider('provideMethodNotAllowedDispatchCases')]
    #[PHPUnit\IgnoreDeprecations('Result\\\\(Matched|MethodNotAllowed|NotMatched)::offset(Get|Exists)\\(\\) is deprecated')]
    public function methodNotAllowedDispatches(
        string $method,
        string $uri,
        Closure $callback,
        array $availableMethods,
    ): void {
        $dispatcher = $this->createDispatcher($callback);
        $routeInfo = $dispatcher->dispatch($method, $uri);

        self::assertInstanceOf(MethodNotAllowed::class, $routeInfo);
        self::assertSame($availableMethods, $routeInfo->allowedMethods);

        // Accès tableau historique (déprécié, encore supporté)
        self::assertArrayHasKey(
            1,
            $routeInfo,
            'METHOD_NOT_ALLOWED result must return an array of allowed methods at index 1',
        );

        [$routedStatus, $methodArray] = $dispatcher->dispatch($method, $uri);
        self::assertSame($dispatcher::METHOD_NOT_ALLOWED, $routedStatus); // @phpstan-ignore classConstant.deprecated (accès historique testé)
        self::assertSame($availableMethods, $methodArray);
    }

    #[PHPUnit\Test]
    public function duplicateVariableNameError(): void
    {
        $this->expectException(BadRouteException::class);
        $this->expectExceptionMessageIsOrContains('Cannot use the same placeholder "test" twice');

        $this->createDispatcher(static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/foo/{test}/{test:\d+}', 'handler0');
        });
    }

    #[PHPUnit\Test]
    public function duplicateVariableRoute(): void
    {
        $this->expectException(BadRouteException::class);
        $this->expectExceptionMessageIsOrContains('Cannot register two routes matching "/user/([^/]+)" for method "GET"');

        $this->createDispatcher(static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user/{id}', 'handler0'); // oops, forgot \d+ restriction ;)
            $r->addRoute('GET', '/user/{name}', 'handler1');
        });
    }

    #[PHPUnit\Test]
    public function duplicateStaticRoute(): void
    {
        $this->expectException(BadRouteException::class);
        $this->expectExceptionMessageIsOrContains('Cannot register two routes matching "/user" for method "GET"');

        $this->createDispatcher(static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user', 'handler0');
            $r->addRoute('GET', '/user', 'handler1');
        });
    }

    #[PHPUnit\Test]
    public function shadowedStaticRoute(): void
    {
        $this->expectException(BadRouteException::class);
        $this->expectExceptionMessageIsOrContains('Static route "/user/nikic" is shadowed by previously defined variable route "/user/([^/]+)" for method "GET"');

        $this->createDispatcher(static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user/{name}', 'handler0');
            $r->addRoute('GET', '/user/nikic', 'handler1');
        });
    }

    /**
     * Un motif trop coûteux épuise la limite de PCRE : l'échec doit être signalé, et non
     * renvoyer NotMatched (404 sans explication sur une URL valide, nikic/FastRoute#167).
     */
    #[PHPUnit\Test]
    public function regexFailureDuringDispatchIsReported(): void
    {
        $dispatcher = $this->createDispatcher(static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/{p:(?:a?a?)*}/complicated', 'complicated');
            $r->addRoute('GET', '/{p:a+}', 'a');
        });

        // Une URI courte reste servie normalement
        $short = $dispatcher->dispatch('GET', '/aaa');
        self::assertInstanceOf(Matched::class, $short);
        self::assertSame('a', $short->handler);

        $this->expectException(RegexMatchingFailed::class);
        $this->expectExceptionMessageIsOrContains('Impossible de chercher la route de l\'URI "/' . str_repeat('a', 40) . '"');

        $this->withBacktrackLimit(1000, static fn (): Matched|NotMatched|MethodNotAllowed => $dispatcher->dispatch('GET', '/' . str_repeat('a', 40)));
    }

    /**
     * La vérification « route statique masquée par une route variable » ne doit pas conclure
     * à tort qu'il n'y a pas de masquage quand PCRE échoue.
     */
    #[PHPUnit\Test]
    public function regexFailureWhileCheckingShadowingIsReported(): void
    {
        $this->expectException(RegexMatchingFailed::class);
        $this->expectExceptionMessageIsOrContains('Impossible de vérifier si la route statique "/' . str_repeat('a', 40) . 'b/complicated"');

        $this->withBacktrackLimit(1000, fn (): Dispatcher => $this->createDispatcher(static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/{p:(?:a?a?)*}/complicated', 'complicated');
            $r->addRoute('GET', '/' . str_repeat('a', 40) . 'b/complicated', 'static');
        }));
    }

    /**
     * Exécute $callback avec une limite de retour arrière PCRE réduite (et le JIT désactivé,
     * pour que la limite s'applique quelle que soit la configuration de PHP).
     *
     * @param Closure():mixed $callback
     */
    private function withBacktrackLimit(int $limit, Closure $callback): void
    {
        $backtrackLimit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', (string) $limit);
        ini_set('pcre.jit', '0');

        try {
            $callback();
        } finally {
            ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
            ini_set('pcre.jit', (string) $jit);
        }
    }

    #[PHPUnit\Test]
    public function capturing(): void
    {
        $this->expectException(BadRouteException::class);
        $this->expectExceptionMessageIsOrContains('Regex "(en|de)" for parameter "lang" contains a capturing group');

        $this->createDispatcher(static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/{lang:(en|de)}', 'handler0');
        });
    }

    /** @return iterable<string, array{0: string, 1: string, 2: Closure(ConfigureRoutes):void, 3: string, 4?: array<string, string>}> */
    public static function provideFoundDispatchCases(): iterable
    {
        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/resource/123/456', 'handler0');
        };

        yield 'single static route' => ['GET', '/resource/123/456', $callback, 'handler0', [], ['_route' => '/resource/123/456']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/handler0', 'handler0');
            $r->addRoute('GET', '/handler1', 'handler1');
            $r->addRoute('GET', '/handler2', 'handler2');
        };

        yield 'multiple static routes' => ['GET', '/handler2', $callback, 'handler2', [], ['_route' => '/handler2']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user/{name}/{id:[0-9]+}', 'handler0');
            $r->addRoute('GET', '/user/{id:[0-9]+}', 'handler1');
            $r->addRoute('GET', '/user/{name}', 'handler2');
        };

        yield 'parameter matching precedence {/user/rdlowrey/12345}' => ['GET', '/user/rdlowrey/12345', $callback, 'handler0', ['name' => 'rdlowrey', 'id' => '12345'], ['_route' => '/user/{name}/{id:[0-9]+}']];
        yield 'parameter matching precedence {/user/12345}' => ['GET', '/user/12345', $callback, 'handler1', ['id' => '12345'], ['_route' => '/user/{id:[0-9]+}']];
        yield 'parameter matching precedence {/user/rdlowrey}' => ['GET', '/user/rdlowrey', $callback, 'handler2', ['name' => 'rdlowrey'], ['_route' => '/user/{name}']];
        yield 'parameter matching precedence {/user/NaN}' => ['GET', '/user/NaN', $callback, 'handler2', ['name' => 'NaN'], ['_route' => '/user/{name}']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user/{id:[0-9]+}', 'handler0');
            $r->addRoute('GET', '/user/12345/extension', 'handler1');
            $r->addRoute('GET', '/user/{id:[0-9]+}.{extension}', 'handler2');
        };

        yield 'dynamic file extensions' => ['GET', '/user/12345.svg', $callback, 'handler2', ['id' => '12345', 'extension' => 'svg'], ['_route' => '/user/{id:[0-9]+}.{extension}']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user/{name}', 'handler0');
            $r->addRoute('GET', '/user/{name}/{id:[0-9]+}', 'handler1');
            $r->addRoute('GET', '/static0', 'handler2');
            $r->addRoute('GET', '/static1', 'handler3');
            $r->addRoute('HEAD', '/static1', 'handler4');
        };

        yield 'fallback to GET on HEAD route miss {/user/rdlowrey}' => ['HEAD', '/user/rdlowrey', $callback, 'handler0', ['name' => 'rdlowrey'], ['_route' => '/user/{name}']];
        yield 'fallback to GET on HEAD route miss {/user/rdlowrey/1234}' => ['HEAD', '/user/rdlowrey/1234', $callback, 'handler1', ['name' => 'rdlowrey', 'id' => '1234'], ['_route' => '/user/{name}/{id:[0-9]+}']];
        yield 'fallback to GET on HEAD route miss {/static0}' => ['HEAD', '/static0', $callback, 'handler2', [], ['_route' => '/static0']];
        yield 'registered HEAD route is used' => ['HEAD', '/static1', $callback, 'handler4', [], ['_route' => '/static1']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user/{name}', 'handler0');
            $r->addRoute('POST', '/user/{name:[a-z]+}', 'handler1');
        };

        yield 'more specific routes are not shadowed by less specific of another method' => ['POST', '/user/rdlowrey', $callback, 'handler1', ['name' => 'rdlowrey'], ['_route' => '/user/{name:[a-z]+}']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user/{name}', 'handler0');
            $r->addRoute('POST', '/user/{name:[a-z]+}', 'handler1');
            $r->addRoute('POST', '/user/{name}', 'handler2');
        };

        yield 'more specific routes are used, according to the registration order {/user/rdlowrey}' => ['POST', '/user/rdlowrey', $callback, 'handler1', ['name' => 'rdlowrey'], ['_route' => '/user/{name:[a-z]+}']];
        yield 'more specific routes are used, according to the registration order {/user/rdlowrey1}' => ['POST', '/user/rdlowrey1', $callback, 'handler2', ['name' => 'rdlowrey1'], ['_route' => '/user/{name}']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user/{name}', 'handler0');
            $r->addRoute('GET', '/user/{name}/edit', 'handler1');
        };

        yield 'route with constant suffix' => ['GET', '/user/rdlowrey/edit', $callback, 'handler1', ['name' => 'rdlowrey'], ['_route' => '/user/{name}/edit']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute(['GET', 'POST'], '/user', 'handlerGetPost');
            $r->addRoute(['DELETE'], '/user', 'handlerDelete');
            $r->addRoute([], '/user', 'handlerNone');
        };

        foreach (['GET' => 'handlerGetPost', 'POST' => 'handlerGetPost', 'DELETE' => 'handlerDelete'] as $method => $handler) {
            yield 'multiple methods with the same handler {' . $method . '}' => [$method, '/user', $callback, $handler, [], ['_route' => '/user']];
        }

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('POST', '/user.json', 'handler0');
            $r->addRoute('GET', '/{entity}.json', 'handler1');
        };

        yield 'fallback to dynamic routes when method does not match' => ['GET', '/user.json', $callback, 'handler1', ['entity' => 'user'], ['_route' => '/{entity}.json']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '', 'handler0');
        };

        yield 'match empty route' => ['GET', '', $callback, 'handler0', [], ['_route' => '']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('HEAD', '/a/{foo}', 'handler0');
            $r->addRoute('GET', '/b/{foo}', 'handler1');
        };

        yield 'fallback to GET route on HEAD miss {dynamic routes}' => ['HEAD', '/b/bar', $callback, 'handler1', ['foo' => 'bar'], ['_route' => '/b/{foo}']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('HEAD', '/a', 'handler0');
            $r->addRoute('GET', '/b', 'handler1');
        };

        yield 'fallback to GET route on HEAD miss {static routes}' =>  ['HEAD', '/b', $callback, 'handler1', [], ['_route' => '/b']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/foo', 'handler0');
            $r->addRoute('HEAD', '/{bar}', 'handler1');
        };

        yield 'fallback to GET route on HEAD miss {dynamic/static routes}' => ['HEAD', '/foo', $callback, 'handler1', ['bar' => 'foo'], ['_route' => '/{bar}']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('*', '/user', 'handler0');
            $r->addRoute('*', '/{user}', 'handler1');
            $r->addRoute('GET', '/user', 'handler2');
        };

        yield 'fallback method is used when needed {GET,static}' => ['GET', '/user', $callback, 'handler2', [], ['_route' => '/user']];
        yield 'fallback method is used when needed {HEAD,static}' => ['HEAD', '/user', $callback, 'handler2', [], ['_route' => '/user']];

        yield 'fallback method is used when needed {GET,dynamic}' => ['GET', '/foo', $callback, 'handler1', ['user' => 'foo'], ['_route' => '/{user}']];
        yield 'fallback method is used when needed {HEAD,dynamic}' => ['HEAD', '/foo', $callback, 'handler1', ['user' => 'foo'], ['_route' => '/{user}']];

        foreach (['POST', 'DELETE', 'OPTIONS', 'PUT', 'PATCH'] as $method) {
            yield 'fallback method is used when needed {' . $method . ',static}' => [$method, '/user', $callback, 'handler0', [], ['_route' => '/user']];
            yield 'fallback method is used when needed {' . $method . ',dynamic}' => [$method, '/foo', $callback, 'handler1', ['user' => 'foo'], ['_route' => '/{user}']];
        }

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/{bar}', 'handler0');
            $r->addRoute('*', '/foo', 'handler1');
        };

        yield 'fallback method is used as last resource' => ['GET', '/foo', $callback, 'handler0', ['bar' => 'foo'], ['_route' => '/{bar}']];

        // Paramètre pouvant capturer une chaîne vide : la position du groupe ne doit pas être
        // confondue avec un groupe non utilisé (bug historique de GroupPosBased)
        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/page/{slug:[a-z]*}', 'page');
            $r->addRoute('GET', '/x/{a}/{b}', 'x');
        };

        yield 'empty capture in first route of a chunk' => ['GET', '/page/', $callback, 'page', ['slug' => ''], ['_route' => '/page/{slug:[a-z]*}']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/x/{a}/{b}', 'x');
            $r->addRoute('GET', '/page/{slug:[a-z]*}', 'page');
        };

        yield 'empty capture in later route of a chunk' => ['GET', '/page/', $callback, 'page', ['slug' => ''], ['_route' => '/page/{slug:[a-z]*}']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user', 'handler0');
            $r->addRoute('*', '/{foo:.*}', 'handler1');
        };

        yield 'fallback method can capture arguments' => ['POST', '/bar', $callback, 'handler1', ['foo' => 'bar'], ['_route' => '/{foo:.*}']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('OPTIONS', '/about', 'handler0');
        };

        yield 'options method is supported' => ['OPTIONS', '/about', $callback, 'handler0', [], ['_route' => '/about']];
    }

    /** @return iterable<string, array{string, string, Closure(ConfigureRoutes):void}> */
    public static function provideNotFoundDispatchCases(): iterable
    {
        $methods = ['GET', 'POST', 'DELETE', 'PUT', 'HEAD', 'OPTIONS'];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/resource/123/456', 'handler0');
        };

        foreach ($methods as $method) {
            yield 'single static route {' . $method . '}' => [$method, '/not-found', $callback];
        }

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/handler0', 'handler0');
            $r->addRoute('GET', '/handler1', 'handler1');
            $r->addRoute('GET', '/handler2', 'handler2');
        };

        foreach ($methods as $method) {
            yield 'multiple static routes {' . $method . '}' => [$method, '/not-found', $callback];
        }

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user/{name}/{id:[0-9]+}', 'handler0');
            $r->addRoute('GET', '/user/{id:[0-9]+}', 'handler1');
            $r->addRoute('GET', '/user/{name}', 'handler2');
        };

        foreach ($methods as $method) {
            foreach (['/not-found', '/user/rdlowrey/12345/not-found'] as $uri) {
                yield 'multiple dynamic routes {' . $method . ', ' . $uri . '}' => [$method, $uri, $callback];
            }
        }
    }

    /** @return iterable<string, array{string, string, Closure(ConfigureRoutes):void, list<string>}> */
    public static function provideMethodNotAllowedDispatchCases(): iterable
    {
        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/resource/123/456', 'handler0');
        };

        yield 'match static routes' => ['POST', '/resource/123/456', $callback, ['GET']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/resource/123/456', 'handler0');
            $r->addRoute('POST', '/resource/123/456', 'handler1');
            $r->addRoute('PUT', '/resource/123/456', 'handler2');
            $r->addRoute('*', '/', 'handler3');
        };

        yield 'ignore fallbacks for unmatched routes ' => ['DELETE', '/resource/123/456', $callback, ['GET', 'POST', 'PUT']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/user/{name}/{id:[0-9]+}', 'handler0');
            $r->addRoute('POST', '/user/{name}/{id:[0-9]+}', 'handler1');
            $r->addRoute('PUT', '/user/{name}/{id:[0-9]+}', 'handler2');
            $r->addRoute('PATCH', '/user/{name}/{id:[0-9]+}', 'handler3');
        };

        yield 'match dynamic routes' => ['DELETE', '/user/rdlowrey/42', $callback, ['GET', 'POST', 'PUT', 'PATCH']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('POST', '/user/{name}', 'handler1');
            $r->addRoute('PUT', '/user/{name:[a-z]+}', 'handler2');
            $r->addRoute('PATCH', '/user/{name:[a-z]+}', 'handler3');
        };

        yield 'match with and without validations' => ['GET', '/user/rdlowrey', $callback, ['POST', 'PUT', 'PATCH']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('POST', '/user/{name}', 'handler1');
            $r->addRoute('PUT', '/user/{name:[a-z]+}', 'handler2');
            $r->addRoute('PATCH', '/user/{name:[a-z]+}', 'handler3');
            $r->addRoute('DELETE', '/user/{name:[a-z0-9]+}', 'handler3');
        };

        yield 'match respects validations' => ['GET', '/user/rdlowrey42', $callback, ['POST', 'DELETE']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute(['GET', 'POST'], '/user', 'handlerGetPost');
            $r->addRoute(['DELETE'], '/user', 'handlerDelete');
            $r->addRoute([], '/user', 'handlerNone');
        };

        yield 'match all valid methods' => ['PUT', '/user', $callback, ['GET', 'POST', 'DELETE']];

        $callback = static function (ConfigureRoutes $r): void {
            $r->addRoute('POST', '/user.json', 'handler0');
            $r->addRoute('GET', '/{entity}.json', 'handler1');
        };

        yield 'match static and dynamic routes' => ['PUT', '/user.json', $callback, ['POST', 'GET']];
    }

    #[PHPUnit\Test]
    public function resultsAreImmutable(): void
    {
        $dispatcher = $this->createDispatcher(static function (ConfigureRoutes $r): void {
            $r->addRoute('GET', '/static', 'static');
            $r->addRoute('GET', '/user/{id}', 'dynamic');
            $r->addRoute('*', '/any', 'any');
            $r->addRoute('*', '/any/{x}', 'any-dynamic');
        });

        $cases = [
            ['GET', '/static'],
            ['GET', '/user/1'],
            ['HEAD', '/static'],
            ['HEAD', '/user/1'],
            ['DELETE', '/any'],
            ['DELETE', '/any/1'],
        ];

        foreach ($cases as [$method, $uri]) {
            $result = $dispatcher->dispatch($method, $uri);
            self::assertInstanceOf(Matched::class, $result);

            foreach (['handler' => 'x', 'variables' => [], 'extraParameters' => []] as $property => $value) {
                try {
                    $result->{$property} = $value; // @phpstan-ignore-line (3 propriétés testées dynamiquement : on vérifie justement l'immuabilité)
                    self::fail('Property ' . $property . ' must be readonly');
                } catch (Error $e) {
                    self::assertStringContainsString('readonly', $e->getMessage());
                }
            }
        }

        $notAllowed = $dispatcher->dispatch('POST', '/static');
        self::assertInstanceOf(MethodNotAllowed::class, $notAllowed);
        $this->expectException(Error::class);
        $notAllowed->allowedMethods = ['PUT']; // @phpstan-ignore property.readOnlyAssignOutOfClass (on vérifie justement l'immuabilité)
    }

    #[PHPUnit\Test]
    #[PHPUnit\IgnoreDeprecations('Result\\\\Matched::offsetGet\\(\\) is deprecated')]
    public function arrayAccessOnResultsIsDeprecated(): void
    {
        $dispatcher = $this->createDispatcher(static function (ConfigureRoutes $r): void {
            $r->get('/user/{id}', 'handler');
        });

        $this->expectUserDeprecationMessage(
            'Method Studoo\\EduRoute\\Dispatcher\\Result\\Matched::offsetGet() is deprecated since 1.0, use $result instanceof Matched and its properties instead',
        );

        self::assertSame('handler', $dispatcher->dispatch('GET', '/user/1')[1]);
    }
}
