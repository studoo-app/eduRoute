<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test;

use PHPUnit\Framework\Attributes as PHPUnit;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studoo\EduRoute\DeclaredRoute;
use Studoo\EduRoute\Test\Attribute\Fixture\HomeController;

use function strlen;

final class DeclaredRouteTest extends TestCase
{
    #[PHPUnit\Test]
    #[PHPUnit\DataProvider('provideHandlers')]
    public function describeHandlerGivesReadableText(mixed $handler, string $expected): void
    {
        self::assertSame($expected, new DeclaredRoute('GET', '/', $handler, null, [])->describeHandler());
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function provideHandlers(): iterable
    {
        $invokable = new class {
            public function __invoke(): void
            {
            }
        };

        yield 'classe' => ['Controller\HomeController', 'Controller\HomeController'];
        yield 'classe::méthode' => ['Controller\HomeController::index', 'Controller\HomeController::index'];
        yield 'tableau [classe, méthode]' => [[HomeController::class, 'index'], HomeController::class . '::index'];
        yield 'tableau [objet, méthode]' => [[new stdClass(), 'run'], 'stdClass::run'];
        yield 'closure interne' => [strlen(...), 'Closure'];
        yield 'objet invocable' => [$invokable, 'class@anonymous::__invoke'];
        yield 'objet' => [new stdClass(), 'stdClass'];
        yield 'entier' => [42, 'int'];
        yield 'null' => [null, 'null'];
        yield 'tableau quelconque' => [[1, 2, 3], 'array'];
        yield 'tableau associatif' => [['class' => 'A', 'method' => 'b'], 'array'];
    }

    #[PHPUnit\Test]
    public function describeHandlerLocatesClosures(): void
    {
        $route = new DeclaredRoute('GET', '/', static fn (): string => 'ok', null, []);

        self::assertSame('Closure (DeclaredRouteTest.php:' . (__LINE__ - 2) . ')', $route->describeHandler());
    }

    #[PHPUnit\Test]
    public function isFallbackOnlyForAny(): void
    {
        self::assertTrue(new DeclaredRoute('*', '/', 'h', null, [])->isFallback());
        self::assertFalse(new DeclaredRoute('GET', '/', 'h', null, [])->isFallback());
    }
}
