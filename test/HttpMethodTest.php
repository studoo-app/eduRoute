<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test;

use PHPUnit\Framework\Attributes as PHPUnit;
use PHPUnit\Framework\TestCase;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\HttpMethod;
use TypeError;

final class HttpMethodTest extends TestCase
{
    /**
     * @param HttpMethod|string|array<HttpMethod|string> $input
     * @param list<string>                               $expected
     */
    #[PHPUnit\Test]
    #[PHPUnit\DataProvider('provideMethodsToNormalize')]
    public function methodsAreNormalizedToStrings(HttpMethod|string|array $input, array $expected): void
    {
        self::assertSame($expected, HttpMethod::normalize($input));
    }

    /** @return iterable<string, array{HttpMethod|string|array<HttpMethod|string>, list<string>}> */
    public static function provideMethodsToNormalize(): iterable
    {
        yield 'enum' => [HttpMethod::Get, ['GET']];
        yield 'chaîne' => ['POST', ['POST']];
        yield 'méthode non standard' => ['PURGE', ['PURGE']];
        yield 'repli' => [HttpMethod::Any, ['*']];
        yield 'liste mixte' => [[HttpMethod::Put, 'PATCH'], ['PUT', 'PATCH']];
        yield 'liste à clés' => [['a' => HttpMethod::Delete, 'b' => 'GET'], ['DELETE', 'GET']];
    }

    #[PHPUnit\Test]
    public function enumAndStringCanBeMixedForRegistrationAndDispatch(): void
    {
        $dispatcher = EduRoute::recommendedSettings(
            static function (ConfigureRoutes $r): void {
                $r->addRoute([HttpMethod::Put, 'PATCH'], '/article/{id:\d+}', 'update');
                $r->get('/article/{id:\d+}', 'show');
            },
            'unused',
        )->disableCache()->dispatcher();

        $byEnum = $dispatcher->dispatch(HttpMethod::Patch, '/article/7');
        self::assertInstanceOf(Matched::class, $byEnum);
        self::assertSame('update', $byEnum->handler);

        $byString = $dispatcher->dispatch('GET', '/article/7');
        self::assertInstanceOf(Matched::class, $byString);
        self::assertSame('show', $byString->handler);

        $notAllowed = $dispatcher->dispatch(HttpMethod::Delete, '/article/7');
        self::assertInstanceOf(MethodNotAllowed::class, $notAllowed);
        self::assertSame(['PUT', 'PATCH', 'GET'], $notAllowed->allowedMethods);
    }

    /** @param array<mixed> $input */
    #[PHPUnit\Test]
    #[PHPUnit\TestWith([[1]])]
    #[PHPUnit\TestWith([[true]])]
    #[PHPUnit\TestWith([[HttpMethod::Get, 405]])]
    public function nonStringMethodsInAListAreRejected(array $input): void
    {
        $this->expectException(TypeError::class);

        // @phpstan-ignore staticMethod.resultUnused, argument.type (on attend l'exception, valeur invalide volontaire)
        HttpMethod::normalize($input);
    }
}
