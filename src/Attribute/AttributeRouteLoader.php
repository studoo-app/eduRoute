<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Attribute;

use ReflectionClass;
use ReflectionMethod;
use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\ConfigureRoutes;

use function class_exists;
use function enum_exists;
use function interface_exists;
use function sprintf;
use function str_starts_with;
use function trait_exists;

/**
 * Enregistre les routes déclarées par les attributs #[Route] et #[RouteGroup] de contrôleurs.
 *
 *     EduRoute::recommendedSettings(static function (ConfigureRoutes $r): void {
 *         AttributeRouteLoader::register($r, HomeController::class, ProductController::class);
 *     }, $cacheFile);
 *
 * Le handler de chaque route est la chaîne "Classe::méthode" : il reste exportable, donc
 * compatible avec le cache.
 *
 * Règles :
 * - un contrôleur est une classe instanciable (ni abstraite, ni interface, ni enum, ni trait) ;
 * - une méthode routée est publique, non statique, déclarée dans le contrôleur lui-même (ou dans
 *   un de ses traits) ; les méthodes héritées d'une classe parente, les constructeurs et les
 *   méthodes magiques (sauf __invoke) sont refusés ;
 * - #[RouteGroup] ne s'applique qu'à la classe qui le porte (il n'est pas hérité).
 *
 * Ordre d'enregistrement (il compte : une route statique déclarée après une route variable qui la
 * masque est refusée) : les contrôleurs dans l'ordre donné, puis, dans chaque contrôleur, ses
 * propres méthodes dans l'ordre du fichier, suivies des méthodes de ses traits.
 */
final class AttributeRouteLoader
{
    /** @param class-string ...$controllers */
    public static function register(ConfigureRoutes $routes, string ...$controllers): void
    {
        foreach ($controllers as $controller) {
            self::registerController($routes, $controller);
        }
    }

    /** @param class-string $controller */
    private static function registerController(ConfigureRoutes $routes, string $controller): void
    {
        $class = self::reflectController($controller);

        if ($class->getAttributes(Route::class) !== []) {
            throw new BadRouteException(sprintf(
                '#[Route] se place sur une méthode, pas sur la classe %s : utilisez #[RouteGroup] pour un préfixe commun',
                $class->getName(),
            ));
        }

        $group = ($class->getAttributes(RouteGroup::class)[0] ?? null)?->newInstance();
        $prefix = $group->prefix ?? '';
        $namePrefix = $group->namePrefix ?? '';

        foreach ($class->getMethods() as $method) {
            if ($method->getAttributes(RouteGroup::class) !== []) {
                throw new BadRouteException(sprintf(
                    '#[RouteGroup] se place sur la classe, pas sur la méthode %s::%s()',
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                ));
            }

            foreach ($method->getAttributes(Route::class) as $attribute) {
                self::guardAgainstUncallableMethod($class, $method);

                $route = $attribute->newInstance();
                $extra = $route->extra;

                if ($route->methods === []) {
                    throw new BadRouteException(sprintf(
                        '#[Route] sans méthode HTTP sur %s::%s()',
                        $class->getName(),
                        $method->getName(),
                    ));
                }

                if ($route->name !== null) {
                    // @phpstan-ignore identical.alwaysFalse (non-empty-string n'est qu'une indication PHPDoc)
                    if ($route->name === '') {
                        throw new BadRouteException(sprintf(
                            'Nom de route vide sur %s::%s()',
                            $class->getName(),
                            $method->getName(),
                        ));
                    }

                    $extra[ConfigureRoutes::ROUTE_NAME] = $namePrefix . $route->name;
                }

                try {
                    $routes->addRoute(
                        $route->methods,
                        $prefix . $route->path,
                        $class->getName() . '::' . $method->getName(),
                        $extra,
                    );
                } catch (BadRouteException $exception) {
                    throw new BadRouteException(sprintf('%s (route déclarée sur %s::%s())', $exception->getMessage(), $class->getName(), $method->getName()), $exception->getCode(), previous: $exception);
                }
            }
        }
    }

    /**
     * @param class-string $controller
     *
     * @return ReflectionClass<object>
     */
    private static function reflectController(string $controller): ReflectionClass
    {
        // class-string n'est qu'une indication PHPDoc : la chaîne vient de l'application
        $kind = match (true) {
            enum_exists($controller) => 'une enum',
            class_exists($controller) => null,
            interface_exists($controller) => 'une interface',
            trait_exists($controller) => 'un trait',
            default => throw new BadRouteException(sprintf('Contrôleur "%s" introuvable', $controller)),
        };

        if ($kind !== null) {
            throw new BadRouteException(sprintf('"%s" est %s, pas un contrôleur instanciable', $controller, $kind));
        }

        $class = new ReflectionClass($controller);

        if (! $class->isInstantiable()) {
            throw new BadRouteException(sprintf(
                'Le contrôleur %s n\'est pas instanciable (classe abstraite ou constructeur non public)',
                $controller,
            ));
        }

        return $class;
    }

    /** @param ReflectionClass<object> $class */
    private static function guardAgainstUncallableMethod(ReflectionClass $class, ReflectionMethod $method): void
    {
        $isMagic = str_starts_with($method->getName(), '__') && $method->getName() !== '__invoke';

        if (
            $method->isPublic()
            && ! $method->isStatic()
            && ! $method->isAbstract()
            && ! $isMagic
            && $method->getDeclaringClass()->getName() === $class->getName()
        ) {
            return;
        }

        throw new BadRouteException(sprintf(
            'La méthode %s::%s() porte #[Route] mais doit être publique, non statique, non magique et déclarée dans le contrôleur %s',
            $method->getDeclaringClass()->getName(),
            $method->getName(),
            $class->getName(),
        ));
    }
}
