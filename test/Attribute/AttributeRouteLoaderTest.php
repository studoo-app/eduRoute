<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Test\Attribute;

use PHPUnit\Framework\Attributes as PHPUnit;
use PHPUnit\Framework\TestCase;
use Studoo\EduRoute\Attribute\AttributeRouteLoader;
use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;
use Studoo\EduRoute\DeclaredRoute;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\EduRoute;
use Studoo\EduRoute\Test\Attribute\Fixture\ArticleController;
use Studoo\EduRoute\Test\Attribute\Fixture\ArticleRoutes;
use Studoo\EduRoute\Test\Attribute\Fixture\BaseController;
use Studoo\EduRoute\Test\Attribute\Fixture\ChildController;
use Studoo\EduRoute\Test\Attribute\Fixture\ClassLevelRouteController;
use Studoo\EduRoute\Test\Attribute\Fixture\ConstructorRouteController;
use Studoo\EduRoute\Test\Attribute\Fixture\DuplicateRouteController;
use Studoo\EduRoute\Test\Attribute\Fixture\EmptyNameController;
use Studoo\EduRoute\Test\Attribute\Fixture\HomeController;
use Studoo\EduRoute\Test\Attribute\Fixture\NoMethodController;
use Studoo\EduRoute\Test\Attribute\Fixture\PrivateRouteController;
use Studoo\EduRoute\Test\Attribute\Fixture\ProductController;
use Studoo\EduRoute\Test\Attribute\Fixture\RoutedContract;
use Studoo\EduRoute\Test\Attribute\Fixture\RoutedEnum;
use Studoo\EduRoute\Test\Attribute\Fixture\RouteGroupOnMethodController;
use Studoo\EduRoute\Test\Attribute\Fixture\StaticRouteController;

use function array_map;
use function file_exists;
use function unlink;

final class AttributeRouteLoaderTest extends TestCase
{
    private const string CACHE_FILE = __DIR__ . '/attribute_routes_cache.php';

    #[PHPUnit\After]
    public function cleanUpCache(): void
    {
        if (! file_exists(self::CACHE_FILE)) {
            return;
        }

        unlink(self::CACHE_FILE);
    }

    /** @param class-string ...$controllers */
    private function router(string ...$controllers): EduRoute
    {
        return EduRoute::recommendedSettings(
            static function (ConfigureRoutes $r) use ($controllers): void {
                AttributeRouteLoader::register($r, ...$controllers);
            },
            'unused',
        )->disableCache();
    }

    #[PHPUnit\Test]
    public function routesAreRegisteredWithClassMethodHandlers(): void
    {
        $dispatcher = $this->router(HomeController::class, ProductController::class)->dispatcher();

        $home = $dispatcher->dispatch('GET', '/');
        self::assertInstanceOf(Matched::class, $home);
        self::assertSame(HomeController::class . '::index', $home->handler);

        $show = $dispatcher->dispatch('GET', '/admin/product/42');
        self::assertInstanceOf(Matched::class, $show);
        self::assertSame(ProductController::class . '::show', $show->handler);
        self::assertSame(['id' => '42'], $show->variables);
        self::assertSame('catalogue', $show->extraParameters['section']);
    }

    #[PHPUnit\Test]
    public function methodsAndRepeatedAttributesAreSupported(): void
    {
        $dispatcher = $this->router(ProductController::class)->dispatcher();

        foreach (['PUT', 'PATCH'] as $method) {
            $result = $dispatcher->dispatch($method, '/admin/product/7');
            self::assertInstanceOf(Matched::class, $result);
            self::assertSame(ProductController::class . '::update', $result->handler);
        }

        $edit = $dispatcher->dispatch('POST', '/admin/product/7/edit');
        self::assertInstanceOf(Matched::class, $edit);
        self::assertSame(ProductController::class . '::update', $edit->handler);

        $notAllowed = $dispatcher->dispatch('DELETE', '/admin/product/7');
        self::assertInstanceOf(MethodNotAllowed::class, $notAllowed);
        self::assertSame(['GET', 'PUT', 'PATCH'], $notAllowed->allowedMethods);
    }

    #[PHPUnit\Test]
    public function namesArePrefixedByTheGroupAndUsableForUriGeneration(): void
    {
        $uris = $this->router(HomeController::class, ProductController::class)->uriGenerator();

        self::assertSame('/', (string) $uris->forRoute('home'));
        self::assertSame('/admin/product', (string) $uris->forRoute('admin.product.index'));
        self::assertSame('/admin/product/3', (string) $uris->forRoute('admin.product.show', ['id' => '3']));
    }

    #[PHPUnit\Test]
    public function methodsWithoutAttributeAreIgnored(): void
    {
        $result = $this->router(ProductController::class)->dispatcher()->dispatch('GET', '/admin/product/helperWithoutRoute');

        self::assertNotInstanceOf(Matched::class, $result);
    }

    #[PHPUnit\Test]
    public function attributeRoutesWorkWithTheFileCache(): void
    {
        $build = static fn (): Dispatcher => EduRoute::recommendedSettings(
            static function (ConfigureRoutes $r): void {
                AttributeRouteLoader::register($r, ProductController::class);
            },
            self::CACHE_FILE,
        )->dispatcher();

        $build(); // écrit le cache
        $result = $build()->dispatch('GET', '/admin/product/9'); // relit le cache

        self::assertInstanceOf(Matched::class, $result);
        self::assertSame(ProductController::class . '::show', $result->handler);
    }

    /** @param class-string $controller */
    #[PHPUnit\Test]
    #[PHPUnit\TestWith([PrivateRouteController::class, 'hidden'])]
    #[PHPUnit\TestWith([StaticRouteController::class, 'shared'])]
    public function routedMethodsMustBePublicInstanceMethods(string $controller, string $method): void
    {
        $this->expectException(BadRouteException::class);
        $this->expectExceptionMessageIsOrContains($controller . '::' . $method . '()');

        $this->router($controller)->dispatcher();
    }

    #[PHPUnit\Test]
    public function unknownControllerIsReported(): void
    {
        $this->expectException(BadRouteException::class);
        $this->expectExceptionMessageIsOrContains('App\Missing');

        // @phpstan-ignore argument.type (classe inexistante volontaire)
        $this->router('App\Missing')->dispatcher();
    }

    /** @param class-string $controller */
    #[PHPUnit\Test]
    #[PHPUnit\TestWith([ChildController::class, 'BaseController::health()'], 'méthode héritée')]
    #[PHPUnit\TestWith([BaseController::class, 'pas instanciable'], 'classe abstraite')]
    #[PHPUnit\TestWith([RoutedEnum::class, 'est une enum'], 'enum')]
    #[PHPUnit\TestWith([RoutedContract::class, 'est une interface'], 'interface')]
    #[PHPUnit\TestWith([ArticleRoutes::class, 'est un trait'], 'trait seul')]
    #[PHPUnit\TestWith([ConstructorRouteController::class, '__construct()'], 'constructeur')]
    #[PHPUnit\TestWith([ClassLevelRouteController::class, 'utilisez #[RouteGroup]'], 'Route sur la classe')]
    #[PHPUnit\TestWith([RouteGroupOnMethodController::class, 'se place sur la classe'], 'RouteGroup sur une méthode')]
    #[PHPUnit\TestWith([EmptyNameController::class, 'Nom de route vide'], 'nom vide')]
    #[PHPUnit\TestWith([NoMethodController::class, 'sans méthode HTTP'], 'aucune méthode')]
    public function invalidControllersAreRejectedWithAnExplicitMessage(string $controller, string $expectedMessage): void
    {
        $this->expectException(BadRouteException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->router($controller)->dispatcher();
    }

    #[PHPUnit\Test]
    public function routingErrorsNameTheControllerMethod(): void
    {
        $this->expectException(BadRouteException::class);
        $this->expectExceptionMessageIsOrContains('Cannot register two routes matching "/same" for method "GET" (route déclarée sur ' . DuplicateRouteController::class . '::second())');

        $this->router(DuplicateRouteController::class)->dispatcher();
    }

    #[PHPUnit\Test]
    public function routesDeclaredInATraitAreAccepted(): void
    {
        $dispatcher = $this->router(ArticleController::class)->dispatcher();

        $index = $dispatcher->dispatch('GET', '/articles');
        self::assertInstanceOf(Matched::class, $index);
        self::assertSame(ArticleController::class . '::index', $index->handler);

        $show = $dispatcher->dispatch('GET', '/articles/5');
        self::assertInstanceOf(Matched::class, $show);
        self::assertSame(ArticleController::class . '::show', $show->handler);
        self::assertSame('/articles/{id:\\d+}', $show->extraParameters[ConfigureRoutes::ROUTE_REGEX]);
    }

    #[PHPUnit\Test]
    public function attributeRoutesAreListed(): void
    {
        $routes = $this->router(ProductController::class)->declaredRoutes();

        self::assertSame(
            [
                ['GET', '/admin/product', ProductController::class . '::index', 'admin.product.index', []],
                ['GET', '/admin/product/{id:\d+}', ProductController::class . '::show', 'admin.product.show', ['section' => 'catalogue']],
                ['PUT', '/admin/product/{id:\d+}', ProductController::class . '::update', 'admin.product.update', []],
                ['PATCH', '/admin/product/{id:\d+}', ProductController::class . '::update', 'admin.product.update', []],
                ['POST', '/admin/product/{id:\d+}/edit', ProductController::class . '::update', null, []],
            ],
            array_map(
                static fn (DeclaredRoute $d): array => [$d->method, $d->pattern, $d->describeHandler(), $d->name, $d->extraParameters],
                $routes,
            ),
        );
    }
}
