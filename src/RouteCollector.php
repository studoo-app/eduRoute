<?php
declare(strict_types=1);

namespace Studoo\EduRoute;

use Closure;
use Override;

use function array_key_exists;
use function array_reverse;
use function is_string;

/**
 * @phpstan-import-type ProcessedData from ConfigureRoutes
 * @phpstan-import-type ExtraParameters from DataGenerator
 * @phpstan-import-type RoutesForUriGeneration from GenerateUri
 * @phpstan-import-type ParsedRoutes from RouteParser
 */
final class RouteCollector implements ConfigureRoutes
{
    private string $currentGroupPrefix = '';

    /** @var RoutesForUriGeneration */
    private array $namedRoutes = [];

    /** @var list<array{list<string>, string, mixed, non-empty-string|null, ExtraParameters}> */
    private array $declarations = [];

    public function __construct(
        private readonly RouteParser $routeParser,
        private readonly DataGenerator $dataGenerator,
    ) {
    }

    /** @inheritDoc */
    #[Override]
    public function addRoute(HttpMethod|string|array $httpMethod, string $route, mixed $handler, array $extraParameters = []): void
    {
        $route = $this->currentGroupPrefix . $route;
        $parsedRoutes = $this->routeParser->parse($route);
        $methods = HttpMethod::normalize($httpMethod);

        // Le nom est validé avant d'ajouter la route : un nom refusé ne laisse pas de route dispatchable
        $name = array_key_exists(self::ROUTE_NAME, $extraParameters)
            ? $this->validateRouteName($extraParameters[self::ROUTE_NAME])
            : null;

        $extraParameters = [self::ROUTE_REGEX => $route] + $extraParameters;

        foreach ($methods as $method) {
            foreach ($parsedRoutes as $parsedRoute) {
                $this->dataGenerator->addRoute($method, $parsedRoute, $handler, $extraParameters);
            }
        }

        if ($name !== null) {
            $this->namedRoutes[$name] = array_reverse($parsedRoutes);
        }

        $this->declarations[] = [$methods, $route, $handler, $name, $extraParameters];
    }

    /** @return non-empty-string */
    private function validateRouteName(mixed $name): string
    {
        if (! is_string($name) || $name === '') {
            throw BadRouteException::invalidRouteName($name);
        }

        if (array_key_exists($name, $this->namedRoutes)) {
            throw BadRouteException::namedRouteAlreadyDefined($name);
        }

        return $name;
    }

    #[Override]
    public function addGroup(string $prefix, Closure $callback): void
    {
        $previousGroupPrefix = $this->currentGroupPrefix;
        $this->currentGroupPrefix = $previousGroupPrefix . $prefix;
        $callback($this);
        $this->currentGroupPrefix = $previousGroupPrefix;
    }

    /** @inheritDoc */
    #[Override]
    public function any(string $route, mixed $handler, array $extraParameters = []): void
    {
        $this->addRoute(HttpMethod::Any, $route, $handler, $extraParameters);
    }

    /** @inheritDoc */
    #[Override]
    public function get(string $route, mixed $handler, array $extraParameters = []): void
    {
        $this->addRoute(HttpMethod::Get, $route, $handler, $extraParameters);
    }

    /** @inheritDoc */
    #[Override]
    public function post(string $route, mixed $handler, array $extraParameters = []): void
    {
        $this->addRoute(HttpMethod::Post, $route, $handler, $extraParameters);
    }

    /** @inheritDoc */
    #[Override]
    public function put(string $route, mixed $handler, array $extraParameters = []): void
    {
        $this->addRoute(HttpMethod::Put, $route, $handler, $extraParameters);
    }

    /** @inheritDoc */
    #[Override]
    public function delete(string $route, mixed $handler, array $extraParameters = []): void
    {
        $this->addRoute(HttpMethod::Delete, $route, $handler, $extraParameters);
    }

    /** @inheritDoc */
    #[Override]
    public function patch(string $route, mixed $handler, array $extraParameters = []): void
    {
        $this->addRoute(HttpMethod::Patch, $route, $handler, $extraParameters);
    }

    /** @inheritDoc */
    #[Override]
    public function head(string $route, mixed $handler, array $extraParameters = []): void
    {
        $this->addRoute(HttpMethod::Head, $route, $handler, $extraParameters);
    }

    /** @inheritDoc */
    #[Override]
    public function options(string $route, mixed $handler, array $extraParameters = []): void
    {
        $this->addRoute(HttpMethod::Options, $route, $handler, $extraParameters);
    }

    /**
     * Routes déclarées avec succès, dans l'ordre de déclaration, pour les lister (commande
     * routes:list…). Une entrée par méthode HTTP : addRoute(['GET', 'POST'], …) donne deux
     * entrées consécutives. Les segments optionnels ne sont pas éclatés.
     *
     * Après une BadRouteException, recréez le collecteur : une route déclarée pour plusieurs
     * méthodes peut avoir été enregistrée en partie.
     *
     * @return list<DeclaredRoute>
     */
    public function declaredRoutes(): array
    {
        $routes = [];

        foreach ($this->declarations as [$methods, $pattern, $handler, $name, $extraParameters]) {
            unset($extraParameters[self::ROUTE_REGEX], $extraParameters[self::ROUTE_NAME]);

            foreach ($methods as $method) {
                $routes[] = new DeclaredRoute($method, $pattern, $handler, $name, $extraParameters);
            }
        }

        return $routes;
    }

    /** @inheritDoc */
    #[Override]
    public function processedRoutes(): array
    {
        $data =  $this->dataGenerator->getData();
        $data[] = $this->namedRoutes;

        return $data;
    }
}
