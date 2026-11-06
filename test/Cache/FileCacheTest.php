<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Cache;

use PHPUnit\Framework\Attributes as PHPUnit;
use PHPUnit\Framework\TestCase;
use Studoo\EduRoute\Cache\CannotCacheRoutes;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\EduRoute;

use function file_exists;
use function file_put_contents;
use function unlink;
use function var_export;

/** Cache fichier par défaut de EduRoute::recommendedSettings() (FileCache). */
final class FileCacheTest extends TestCase
{
    private const string CACHE_FILE = __DIR__ . '/routing_cache.php';

    #[PHPUnit\After]
    public function cleanUpCache(): void
    {
        if (! file_exists(self::CACHE_FILE)) {
            return;
        }

        unlink(self::CACHE_FILE);
    }

    private function createDispatcher(): Dispatcher
    {
        return EduRoute::recommendedSettings(
            static function (ConfigureRoutes $collector): void {
                $collector->get('/testing', ['test']);
                $collector->get('/admin/{page}', ['admin-page']);
            },
            self::CACHE_FILE,
        )->dispatcher();
    }

    #[PHPUnit\Test]
    public function cacheFileIsWrittenOnFirstBuild(): void
    {
        self::assertFileDoesNotExist(self::CACHE_FILE);

        $this->createDispatcher();

        self::assertFileExists(self::CACHE_FILE);
    }

    #[PHPUnit\Test]
    public function routesAreDispatchedFromTheCache(): void
    {
        $this->createDispatcher(); // écrit le cache
        $dispatcher = $this->createDispatcher(); // relit le cache

        $dynamic = $dispatcher->dispatch('GET', '/admin/1234');
        self::assertInstanceOf(Matched::class, $dynamic);
        self::assertSame(['admin-page'], $dynamic->handler);
        self::assertSame(['page' => '1234'], $dynamic->variables);

        $static = $dispatcher->dispatch('GET', '/testing');
        self::assertInstanceOf(Matched::class, $static);
        self::assertSame(['test'], $static->handler);

        self::assertInstanceOf(NotMatched::class, $dispatcher->dispatch('GET', '/testing2'));
    }

    #[PHPUnit\Test]
    public function existingCacheFileTakesPrecedenceOverRouteDefinitions(): void
    {
        file_put_contents(
            self::CACHE_FILE,
            '<?php return ' . var_export([['GET' => ['/testing' => ['from-cache', []]]], [], []], true) . ';',
        );

        $result = $this->createDispatcher()->dispatch('GET', '/testing');

        self::assertInstanceOf(Matched::class, $result);
        self::assertSame('from-cache', $result->handler);
    }

    #[PHPUnit\Test]
    public function cacheFileFromAnotherFormatIsRegenerated(): void
    {
        // Fichier laissé par FastRoute 1.x : [routes statiques, routes variables], sans routes nommées
        file_put_contents(
            self::CACHE_FILE,
            '<?php return ' . var_export([['GET' => ['/testing' => 'old-handler']], []], true) . ';',
        );

        $result = $this->createDispatcher()->dispatch('GET', '/testing');

        self::assertInstanceOf(Matched::class, $result);
        self::assertSame(['test'], $result->handler);
    }

    #[PHPUnit\Test]
    public function routesContainingObjectsAreRefusedWithAClearMessage(): void
    {
        $router = EduRoute::recommendedSettings(
            static function (ConfigureRoutes $collector): void {
                $collector->get('/hello', static fn (): string => 'hi');
            },
            self::CACHE_FILE,
        );

        $this->expectException(CannotCacheRoutes::class);
        $this->expectExceptionMessageIsOrContains('Closure');

        try {
            $router->dispatcher();
        } finally {
            self::assertFileDoesNotExist(self::CACHE_FILE);
        }
    }
}
