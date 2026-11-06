<?php
declare(strict_types=1);

namespace Studoo\EduRoute\Dispatcher;

use Override;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Dispatcher;
use Studoo\EduRoute\Dispatcher\Result\Matched;
use Studoo\EduRoute\Dispatcher\Result\MethodNotAllowed;
use Studoo\EduRoute\Dispatcher\Result\NotMatched;
use Studoo\EduRoute\HttpMethod;

/**
 * @internal
 *
 * @phpstan-import-type StaticRoutes from DataGenerator
 * @phpstan-import-type DynamicRouteChunk from DataGenerator
 * @phpstan-import-type DynamicRouteChunks from DataGenerator
 * @phpstan-import-type DynamicRoutes from DataGenerator
 * @phpstan-import-type RouteData from DataGenerator
 */
abstract class RegexBasedAbstract implements Dispatcher
{
    /** @var StaticRoutes */
    protected array $staticRouteMap = [];

    /** @var DynamicRoutes */
    protected array $variableRouteData = [];

    /** @param RouteData $data */
    public function __construct(array $data)
    {
        [$this->staticRouteMap, $this->variableRouteData] = $data;
    }

    /** @param DynamicRouteChunks $routeData */
    abstract protected function dispatchVariableRoute(array $routeData, string $uri): ?Matched;

    #[Override]
    public function dispatch(HttpMethod|string $httpMethod, string $uri): Matched|NotMatched|MethodNotAllowed
    {
        if ($httpMethod instanceof HttpMethod) {
            $httpMethod = $httpMethod->value;
        }

        $route = $this->staticRouteMap[$httpMethod][$uri] ?? null;
        if ($route !== null) {
            $result = new Matched();
            $result->handler = $route[0];
            $result->variables = [];
            $result->extraParameters = $route[1];

            return $result;
        }

        $routeData = $this->variableRouteData[$httpMethod] ?? null;
        if ($routeData !== null) {
            $result = $this->dispatchVariableRoute($routeData, $uri);
            if ($result instanceof Matched) {
                return $result;
            }
        }

        // For HEAD requests, attempt fallback to GET
        if ($httpMethod === 'HEAD') {
            $route = $this->staticRouteMap['GET'][$uri] ?? null;
            if ($route !== null) {
                $result = new Matched();
                $result->handler = $route[0];
                $result->variables = [];
                $result->extraParameters = $route[1];

                return $result;
            }

            $routeData = $this->variableRouteData['GET'] ?? null;
            if ($routeData !== null) {
                $result = $this->dispatchVariableRoute($routeData, $uri);
                if ($result instanceof Matched) {
                    return $result;
                }
            }
        }

        // If nothing else matches, try fallback routes
        $route = $this->staticRouteMap['*'][$uri] ?? null;
        if ($route !== null) {
            $result = new Matched();
            $result->handler = $route[0];
            $result->variables = [];
            $result->extraParameters = $route[1];

            return $result;
        }

        $routeData = $this->variableRouteData['*'] ?? null;
        if ($routeData !== null) {
            $result = $this->dispatchVariableRoute($routeData, $uri);
            if ($result instanceof Matched) {
                return $result;
            }
        }

        // Find allowed methods for this URI by matching against all other HTTP methods as well
        $allowedMethods = [];

        foreach ($this->staticRouteMap as $method => $uriMap) {
            if ($method === $httpMethod || ! isset($uriMap[$uri])) {
                continue;
            }

            $allowedMethods[] = $method;
        }

        foreach ($this->variableRouteData as $method => $routeData) {
            if ($method === $httpMethod) {
                continue;
            }

            $result = $this->dispatchVariableRoute($routeData, $uri);
            if (! $result instanceof Matched) {
                continue;
            }

            $allowedMethods[] = $method;
        }

        // If there are no allowed methods the route simply does not exist
        if ($allowedMethods !== []) {
            $result = new MethodNotAllowed();
            $result->allowedMethods = $allowedMethods;

            return $result;
        }

        return new NotMatched();
    }
}
