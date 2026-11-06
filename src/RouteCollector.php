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

        $extraParameters = [self::ROUTE_REGEX => $route] + $extraParameters;

        foreach (HttpMethod::normalize($httpMethod) as $method) {
            foreach ($parsedRoutes as $parsedRoute) {
                $this->dataGenerator->addRoute($method, $parsedRoute, $handler, $extraParameters);
            }
        }

        if (array_key_exists(self::ROUTE_NAME, $extraParameters)) {
            $this->registerNamedRoute($extraParameters[self::ROUTE_NAME], $parsedRoutes);
        }
    }

    /** @param ParsedRoutes $parsedRoutes */
    private function registerNamedRoute(mixed $name, array $parsedRoutes): void
    {
        if (! is_string($name) || $name === '') {
            throw BadRouteException::invalidRouteName($name);
        }

        if (array_key_exists($name, $this->namedRoutes)) {
            throw BadRouteException::namedRouteAlreadyDefined($name);
        }

        $this->namedRoutes[$name] = array_reverse($parsedRoutes);
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

    /** @inheritDoc */
    #[Override]
    public function processedRoutes(): array
    {
        $data =  $this->dataGenerator->getData();
        $data[] = $this->namedRoutes;

        return $data;
    }
}
