<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test;

use ArrayObject;
use Closure;
use Nyholm\Psr7\Uri;
use Override;
use PHPUnit\Framework\Attributes as PHPUnit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Studoo\EduRoute\Cache;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\DeclaredRoute;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\GenerateUri;
use Studoo\EduRoute\Test\Dispatcher\InternalParentDispatcher;

use function array_map;
use function bin2hex;
use function file_exists;
use function md5_file;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

final class EduRouteTest extends TestCase
{
    #[PHPUnit\Test]
    public function markShouldBeTheDefaultDispatcher(): void
    {
        $dispatcher = EduRoute::recommendedSettings($this->routes(...), 'test')
            ->disableCache()
            ->dispatcher();

        self::assertInstanceOf(Dispatcher\MarkBased::class, $dispatcher);
    }

    #[PHPUnit\Test]
    public function canBeConfiguredToUseCharCountDispatcher(): void
    {
        $dispatcher = EduRoute::recommendedSettings($this->routes(...), 'test')
            ->disableCache()
            ->useCharCountDispatcher()
            ->dispatcher();

        self::assertInstanceOf(Dispatcher\CharCountBased::class, $dispatcher);
    }

    #[PHPUnit\Test]
    public function canBeConfiguredToUseGroupPosDispatcher(): void
    {
        $dispatcher = EduRoute::recommendedSettings($this->routes(...), 'test')
            ->disableCache()
            ->useGroupPosDispatcher()
            ->dispatcher();

        self::assertInstanceOf(Dispatcher\GroupPosBased::class, $dispatcher);
    }

    #[PHPUnit\Test]
    public function canBeConfiguredToUseGroupCountDispatcher(): void
    {
        $dispatcher = EduRoute::recommendedSettings($this->routes(...), 'test')
            ->disableCache()
            ->useGroupCountDispatcher()
            ->dispatcher();

        self::assertInstanceOf(Dispatcher\GroupCountBased::class, $dispatcher);
    }

    #[PHPUnit\Test]
    public function canBeConfiguredToUseMarkDispatcher(): void
    {
        $dispatcher = EduRoute::recommendedSettings($this->routes(...), 'test')
            ->disableCache()
            ->useCharCountDispatcher()
            ->useMarkDispatcher()
            ->dispatcher();

        self::assertInstanceOf(Dispatcher\MarkBased::class, $dispatcher);
    }

    #[PHPUnit\Test]
    public function canBeConfiguredToUseCustomCache(): void
    {
        $cache = new class () implements Cache {
            /** @inheritDoc */
            #[Override]
            public function get(string $key, Closure $loader): array
            {
                if ($key === 'test') {
                    return [['GET' => ['/' => ['test2', ['test' => true]]]], [], []];
                }

                throw new RuntimeException('This dummy implementation is not meant for other cases');
            }
        };

        $dispatcher = EduRoute::recommendedSettings($this->routes(...), 'test2')
            ->withCache($cache, 'test')
            ->dispatcher();

        $result = $dispatcher->dispatch('GET', '/');

        self::assertInstanceOf(Dispatcher\Result\Matched::class, $result);
        self::assertSame('test2', $result->handler); // should use data from cache, not from loader
        self::assertSame(['test' => true], $result->extraParameters); // should use data from cache, not from loader
    }

    private function routes(ConfigureRoutes $collector): void
    {
        $collector->get('/', 'test');
    }

    #[PHPUnit\Test]
    public function defaultUriGeneratorMustBeProvided(): void
    {
        $uriGenerator = EduRoute::recommendedSettings($this->routes(...), 'test')
            ->disableCache()
            ->uriGenerator();

        self::assertInstanceOf(GenerateUri\FromProcessedConfiguration::class, $uriGenerator);
    }

    #[PHPUnit\Test]
    public function uriGeneratorCanBeOverridden(): void
    {
        $generator = new class () implements GenerateUri {
            /** @inheritDoc */
            #[Override]
            public function forRoute(string $name, array $substitutions = []): GenerateUri\GeneratedUri
            {
                return new GenerateUri\GeneratedUri('/', $substitutions);
            }
        };

        $uriGenerator = EduRoute::recommendedSettings($this->routes(...), 'test')
            ->disableCache()
            ->withUriGenerator($generator::class)
            ->uriGenerator();

        self::assertInstanceOf($generator::class, $uriGenerator);
    }

    #[PHPUnit\Test]
    public function processedDataShouldOnlyBeBuiltOnce(): void
    {
        $loader = static function (ConfigureRoutes $routes): void {
            $routes->addRoute(
                ['GET', 'POST'],
                '/users/{name}',
                'do-stuff',
                [ConfigureRoutes::ROUTE_NAME => 'users'],
            );

            $routes->get('/posts/{id}', 'fetchPosts', [ConfigureRoutes::ROUTE_NAME => 'posts.fetch']);

            $routes->get(
                '/articles/{year}[/{month}[/{day}]]',
                'fetchArticle',
                [ConfigureRoutes::ROUTE_NAME => 'articles.fetch'],
            );
        };

        $fastRoute = EduRoute::recommendedSettings($loader, 'test')
            ->disableCache();

        $dispatcher   = $fastRoute->dispatcher();
        $uriGenerator = $fastRoute->uriGenerator();

        self::assertInstanceOf(Dispatcher\Result\Matched::class, $dispatcher->dispatch('GET', '/users/lcobucci'));
        self::assertInstanceOf(Dispatcher\Result\Matched::class, $dispatcher->dispatch('POST', '/users/lcobucci'));
        self::assertInstanceOf(Dispatcher\Result\Matched::class, $dispatcher->dispatch('GET', '/posts/1234'));

        self::assertEquals('/users/lcobucci', $uriGenerator->forRoute('users', ['name' => 'lcobucci']));
        self::assertEquals('/posts/1234', $uriGenerator->forRoute('posts.fetch', ['id' => '1234']));
        self::assertEquals('/articles/2024', $uriGenerator->forRoute('articles.fetch', ['year' => '2024']));
        self::assertEquals('/articles/2024/02', $uriGenerator->forRoute('articles.fetch', ['year' => '2024', 'month' => '02']));
        self::assertEquals('/articles/2024/02/15', $uriGenerator->forRoute('articles.fetch', ['year' => '2024', 'month' => '02', 'day' => '15']));
        self::assertSame('/articles/2024/02/15?extra=value', (string) $uriGenerator->forRoute('articles.fetch', ['year' => '2024', 'month' => '02', 'day' => '15', 'extra' => 'value'])->asUri(new Uri()));
    }

    #[PHPUnit\Test]
    public function configurationMethodsReturnNewInstancesWithoutSharingProcessedRoutes(): void
    {
        $calls = 0;
        $markBased = EduRoute::recommendedSettings(
            static function (ConfigureRoutes $r) use (&$calls): void {
                ++$calls;
                $r->get('/user/{id:\\d+}', 'user');
            },
            'unused',
        )->disableCache();

        self::assertInstanceOf(Dispatcher\MarkBased::class, $markBased->dispatcher());

        $groupCount = $markBased->useGroupCountDispatcher();

        self::assertNotSame($markBased, $groupCount);
        self::assertInstanceOf(Dispatcher\MarkBased::class, $markBased->dispatcher(), 'the original is unchanged');

        // Les routes doivent être regénérées pour le nouveau dispatcher (format de données différent)
        $result = $groupCount->dispatcher()->dispatch('GET', '/user/42');
        self::assertInstanceOf(Dispatcher\Result\Matched::class, $result);
        self::assertSame(['id' => '42'], $result->variables);
        self::assertSame(2, $calls);
    }

    #[PHPUnit\Test]
    public function lazyDispatcherLoadsRoutesOnlyOnFirstUse(): void
    {
        $loads = new ArrayObject();
        $router = EduRoute::recommendedSettings(
            static function (ConfigureRoutes $r) use ($loads): void {
                $loads->append(true);
                $r->get('/user/{id:\\d+}', 'user', [ConfigureRoutes::ROUTE_NAME => 'user']);
            },
            'unused',
        )->disableCache();

        $dispatcher = $router->lazyDispatcher();
        $uris = $router->lazyUriGenerator();

        self::assertInstanceOf(Dispatcher\MarkBased::class, $dispatcher);
        self::assertTrue(new ReflectionClass($dispatcher)->isUninitializedLazyObject($dispatcher));
        self::assertCount(0, $loads, 'routes must not be loaded before the first dispatch');

        $result = $dispatcher->dispatch('GET', '/user/42');
        self::assertInstanceOf(Dispatcher\Result\Matched::class, $result);
        self::assertSame(['id' => '42'], $result->variables);
        self::assertCount(1, $loads);

        $dispatcher->dispatch('GET', '/user/43');
        self::assertSame('/user/7', (string) $uris->forRoute('user', ['id' => '7']));
        self::assertCount(1, $loads, 'routes are built once and shared by the lazy objects of a router');
    }

    #[PHPUnit\Test]
    public function lazyDispatcherFallsBackToEagerForClassesExtendingAnInternalClass(): void
    {
        $dispatcher = EduRoute::recommendedSettings($this->routes(...), 'unused')
            ->disableCache()
            ->useCustomDispatcher(DataGenerator\MarkBased::class, InternalParentDispatcher::class)
            ->lazyDispatcher();

        self::assertInstanceOf(InternalParentDispatcher::class, $dispatcher);
        self::assertFalse(new ReflectionClass($dispatcher)->isUninitializedLazyObject($dispatcher));
        self::assertInstanceOf(Dispatcher\Result\Matched::class, $dispatcher->dispatch('GET', '/'));
    }

    #[PHPUnit\Test]
    public function lazyDispatcherReadsTheFileCacheOnFirstUse(): void
    {
        $cacheFile = sys_get_temp_dir() . '/edu-route-lazy-' . bin2hex(random_bytes(4)) . '.php';

        try {
            $router = EduRoute::recommendedSettings($this->routes(...), $cacheFile);
            $dispatcher = $router->lazyDispatcher();

            self::assertFileDoesNotExist($cacheFile, 'nothing is built before the first dispatch');
            self::assertInstanceOf(Dispatcher\Result\Matched::class, $dispatcher->dispatch('GET', '/'));
            self::assertFileExists($cacheFile);
        } finally {
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    #[PHPUnit\Test]
    public function declaredRoutesWithoutCache(): void
    {
        $router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
            $r->get('/', 'home', ['_name' => 'home']);
            $r->addGroup('/blog', static function (ConfigureRoutes $r): void {
                $r->get('/{slug}', 'post');
            });
        }, 'unused')->disableCache();

        self::assertSame(
            [['GET', '/', 'home', 'home'], ['GET', '/blog/{slug}', 'post', null]],
            array_map(static fn (DeclaredRoute $d): array => [$d->method, $d->pattern, $d->handler, $d->name], $router->declaredRoutes()),
        );
    }

    #[PHPUnit\Test]
    public function declaredRoutesNeverTouchTheCache(): void
    {
        $cacheFile = sys_get_temp_dir() . '/edu-route-declared-' . bin2hex(random_bytes(4)) . '.php';

        try {
            $router = EduRoute::recommendedSettings($this->routes(...), $cacheFile);

            self::assertCount(1, $router->declaredRoutes());
            self::assertFileDoesNotExist($cacheFile, 'listing routes must not write the cache');

            $router->dispatcher();
            $hash = md5_file($cacheFile);
            self::assertSame('/', $router->declaredRoutes()[0]->pattern);
            self::assertSame($hash, md5_file($cacheFile));
        } finally {
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    #[PHPUnit\Test]
    public function declaredRoutesNeverCallTheCacheDriver(): void
    {
        $cache = new class () implements Cache {
            /** @inheritDoc */
            #[Override]
            public function get(string $key, Closure $loader): array
            {
                throw new RuntimeException('The cache must not be used to list routes');
            }
        };

        $routes = EduRoute::recommendedSettings($this->routes(...), 'key')->withCache($cache, 'key')->declaredRoutes();

        self::assertCount(1, $routes);
    }

    #[PHPUnit\Test]
    public function declaredRoutesReflectCurrentCodeEvenWithStaleCache(): void
    {
        $cache = new class () implements Cache {
            /** @inheritDoc */
            #[Override]
            public function get(string $key, Closure $loader): array
            {
                return [['GET' => ['/old' => ['old', []]]], [], []];
            }
        };

        $router = EduRoute::recommendedSettings($this->routes(...), 'key')->withCache($cache, 'key');

        self::assertInstanceOf(Dispatcher\Result\Matched::class, $router->dispatcher()->dispatch('GET', '/old'));
        self::assertSame('/', $router->declaredRoutes()[0]->pattern);
    }

    #[PHPUnit\Test]
    public function declaredRoutesAcceptClosureHandlersWithCacheEnabled(): void
    {
        $handler = static fn (): string => 'ok';
        $cacheFile = sys_get_temp_dir() . '/edu-route-closure-' . bin2hex(random_bytes(4)) . '.php';
        $routes = EduRoute::recommendedSettings(static function (ConfigureRoutes $r) use ($handler): void {
            $r->get('/', $handler);
        }, $cacheFile)->declaredRoutes();

        self::assertSame($handler, $routes[0]->handler);
        self::assertFileDoesNotExist($cacheFile);
    }

    #[PHPUnit\Test]
    public function declaredRoutesAreIdenticalForEveryDispatcherStrategy(): void
    {
        $router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
            $r->get('/user/{id:\d+}[/{slug}]', 'user');
            $r->post('/user', 'create');
            $r->any('/{page:.*}', 'fallback');
        }, 'unused')->disableCache();

        $expected = $router->declaredRoutes();
        self::assertCount(3, $expected);

        foreach (
            [
                $router->useCharCountDispatcher(),
                $router->useGroupCountDispatcher(),
                $router->useGroupPosDispatcher(),
                $router->useMarkDispatcher(),
            ] as $configured
        ) {
            self::assertEquals($expected, $configured->declaredRoutes());
        }
    }

    #[PHPUnit\Test]
    public function declaredRoutesDoNotAffectTheDispatcher(): void
    {
        $calls = 0;
        $router = EduRoute::recommendedSettings(static function (ConfigureRoutes $r) use (&$calls): void {
            ++$calls;
            $r->get('/', 'test');
        }, 'unused')->disableCache();

        $dispatcher = $router->dispatcher();
        $router->declaredRoutes();
        $router->declaredRoutes();

        self::assertSame(3, $calls, 'the definition callback is replayed for each listing');
        self::assertInstanceOf(Dispatcher\Result\Matched::class, $dispatcher->dispatch('GET', '/'));
        self::assertSame($dispatcher::class, $router->dispatcher()::class);
    }
}
