<?php
declare(strict_types=1);

namespace FastRoute\Test;

use FastRoute\ConfigureRoutes;
use FastRoute\DataGenerator;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser;
use LogicException;
use PHPUnit\Framework\Attributes as PHPUnit;
use PHPUnit\Framework\TestCase;

use function FastRoute\cachedDispatcher;
use function FastRoute\simpleDispatcher;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/** Vérifie que l'API historique fonctionne toujours et signale sa dépréciation (#[\Deprecated], PHP 8.4). */
final class LegacyFunctionsTest extends TestCase
{
    #[PHPUnit\Test]
    #[PHPUnit\IgnoreDeprecations('simpleDispatcher\\(\\) is deprecated')]
    public function simpleDispatcherStillWorksAndIsDeprecated(): void
    {
        $this->expectUserDeprecationMessage(
            'Function FastRoute\simpleDispatcher() is deprecated since 1.0, use FastRoute::recommendedSettings()->disableCache() instead',
        );

        $dispatcher = simpleDispatcher(static function (ConfigureRoutes $r): void {
            $r->get('/user/{id:\d+}', 'handler');
        });

        $result = $dispatcher->dispatch('GET', '/user/42');

        self::assertSame(Dispatcher::FOUND, $result[0]);
        self::assertSame('handler', $result[1]);
        self::assertSame(['id' => '42'], $result[2]);
    }

    #[PHPUnit\Test]
    #[PHPUnit\IgnoreDeprecations('cachedDispatcher\\(\\) is deprecated')]
    public function cachedDispatcherStillWorksAndIsDeprecated(): void
    {
        $this->expectUserDeprecationMessage(
            'Function FastRoute\cachedDispatcher() is deprecated since 1.0, use FastRoute::recommendedSettings() instead',
        );

        $cacheFile = tempnam(sys_get_temp_dir(), 'edu-route');
        self::assertIsString($cacheFile);

        try {
            $dispatcher = cachedDispatcher(
                static function (ConfigureRoutes $r): void {
                    $r->get('/', 'home');
                },
                ['cacheKey' => $cacheFile],
            );

            self::assertSame(Dispatcher::FOUND, $dispatcher->dispatch('GET', '/')[0]);
        } finally {
            unlink($cacheFile);
        }
    }

    #[PHPUnit\Test]
    #[PHPUnit\IgnoreDeprecations('getData\\(\\) is deprecated')]
    public function routeCollectorGetDataStillWorksAndIsDeprecated(): void
    {
        $this->expectUserDeprecationMessage(
            'Method FastRoute\RouteCollector::getData() is deprecated since 1.0, use processedRoutes() instead',
        );

        $collector = new RouteCollector(new RouteParser\Std(), new DataGenerator\MarkBased());
        $collector->get('/', 'home');

        // @phpstan-ignore method.deprecated (on vérifie justement la dépréciation)
        self::assertSame($collector->processedRoutes(), $collector->getData());
    }

    /** Comme dans FastRoute d'origine : seul `true` désactive le cache (pas 1, '1', 'false'...). */
    #[PHPUnit\Test]
    #[PHPUnit\IgnoreDeprecations('cachedDispatcher\\(\\) is deprecated')]
    #[PHPUnit\TestWith([1])]
    #[PHPUnit\TestWith(['1'])]
    #[PHPUnit\TestWith(['false'])]
    public function cacheIsOnlyDisabledByStrictTrue(mixed $cacheDisabled): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Must specify "cacheKey" option');

        cachedDispatcher(
            static fn (): null => null,
            ['cacheDisabled' => $cacheDisabled], // @phpstan-ignore argument.type (valeur non booléenne volontaire)
        );
    }
}
