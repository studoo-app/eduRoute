<?php
declare(strict_types=1);

namespace Studoo\EduRoute\DataGenerator;

use Override;
use Studoo\EduRoute\BadRouteException;
use Studoo\EduRoute\DataGenerator;
use Studoo\EduRoute\Route;
use Studoo\EduRoute\RouteParser;

use function array_chunk;
use function array_map;
use function assert;
use function ceil;
use function count;
use function is_string;
use function max;
use function round;

/**
 * @internal
 *
 * @phpstan-import-type StaticRoutes from DataGenerator
 * @phpstan-import-type DynamicRouteChunk from DataGenerator
 * @phpstan-import-type DynamicRoutes from DataGenerator
 * @phpstan-import-type RouteData from DataGenerator
 * @phpstan-import-type ExtraParameters from DataGenerator
 * @phpstan-import-type ParsedRoute from RouteParser
 */
abstract class RegexBasedAbstract implements DataGenerator
{
    /** @var StaticRoutes */
    protected array $staticRoutes = [];

    /** @var array<string, array<string, Route>> */
    protected array $methodToRegexToRoutesMap = [];

    abstract protected function getApproxChunkSize(): int;

    /**
     * @param array<string, Route> $regexToRoutesMap
     *
     * @return DynamicRouteChunk
     */
    abstract protected function processChunk(array $regexToRoutesMap): array;

    /** @inheritDoc */
    #[Override]
    public function addRoute(string $httpMethod, array $routeData, mixed $handler, array $extraParameters = []): void
    {
        if ($this->isStaticRoute($routeData)) {
            $this->addStaticRoute($httpMethod, $routeData, $handler, $extraParameters);
        } else {
            $this->addVariableRoute($httpMethod, $routeData, $handler, $extraParameters);
        }
    }

    /** @inheritDoc */
    #[Override]
    public function getData(): array
    {
        if ($this->methodToRegexToRoutesMap === []) {
            return [$this->staticRoutes, []];
        }

        return [$this->staticRoutes, $this->generateVariableRouteData()];
    }

    /** @return DynamicRoutes */
    private function generateVariableRouteData(): array
    {
        $data = [];
        foreach ($this->methodToRegexToRoutesMap as $method => $regexToRoutesMap) {
            $chunkSize = $this->computeChunkSize(count($regexToRoutesMap));
            $chunks = array_chunk($regexToRoutesMap, $chunkSize, true);
            $data[$method] = array_map($this->processChunk(...), $chunks);
        }

        return $data;
    }

    /** @return positive-int */
    private function computeChunkSize(int $count): int
    {
        $numParts = max(1, round($count / $this->getApproxChunkSize()));
        $size = (int) ceil($count / $numParts);
        assert($size > 0);

        return $size;
    }

    /** @param ParsedRoute $routeData */
    private function isStaticRoute(array $routeData): bool
    {
        return count($routeData) === 1 && is_string($routeData[0]);
    }

    /**
     * @param ParsedRoute     $routeData
     * @param ExtraParameters $extraParameters
     */
    private function addStaticRoute(string $httpMethod, array $routeData, mixed $handler, array $extraParameters): void
    {
        $routeStr = $routeData[0];
        assert(is_string($routeStr));

        if (isset($this->staticRoutes[$httpMethod][$routeStr])) {
            throw BadRouteException::alreadyRegistered($routeStr, $httpMethod);
        }

        // foreach plutôt qu'array_find() : la closure appelée à chaque comparaison coûte ~9 % sur
        // l'enregistrement des routes, refait à chaque requête quand le cache est désactivé.
        foreach ($this->methodToRegexToRoutesMap[$httpMethod] ?? [] as $route) {
            if ($route->matches($routeStr)) {
                throw BadRouteException::shadowedByVariableRoute($routeStr, $route->regex, $httpMethod);
            }
        }

        $this->staticRoutes[$httpMethod][$routeStr] = [$handler, $extraParameters];
    }

    /**
     * @param ParsedRoute     $routeData
     * @param ExtraParameters $extraParameters
     */
    private function addVariableRoute(string $httpMethod, array $routeData, mixed $handler, array $extraParameters): void
    {
        $route = new Route($httpMethod, $routeData, $handler, $extraParameters);
        $regex = $route->regex;

        if (isset($this->methodToRegexToRoutesMap[$httpMethod][$regex])) {
            throw BadRouteException::alreadyRegistered($regex, $httpMethod);
        }

        $this->methodToRegexToRoutesMap[$httpMethod][$regex] = $route;
    }
}
