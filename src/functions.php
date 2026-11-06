<?php
declare(strict_types=1);

namespace FastRoute;

use Deprecated;

use function function_exists;

if (! function_exists('FastRoute\simpleDispatcher')) {
    /**
     * @deprecated since 1.0 (studoo/edu-route), will be removed in 2.0
     *
     * @see FastRoute::recommendedSettings()
     * @see FastRoute::disableCache()
     *
     * @param callable(ConfigureRoutes):void                                                                                                                                                                                                                                                           $routeDefinitionCallback
     * @param array{routeParser?: class-string<RouteParser>, dataGenerator?: class-string<DataGenerator>, dispatcher?: class-string<Dispatcher>, routeCollector?: class-string<ConfigureRoutes>, cacheDisabled?: bool, cacheKey?: string, cacheFile?: string, cacheDriver?: class-string<Cache>|Cache} $options
     */
    #[Deprecated('use FastRoute::recommendedSettings()->disableCache() instead', since: '1.0')]
    function simpleDispatcher(callable $routeDefinitionCallback, array $options = []): Dispatcher
    {
        return LegacyDispatcherFactory::create(
            $routeDefinitionCallback,
            ['cacheDisabled' => true] + $options,
        );
    }

    /**
     * @deprecated since 1.0 (studoo/edu-route), will be removed in 2.0
     *
     * @see FastRoute::recommendedSettings()
     *
     * @param callable(ConfigureRoutes):void                                                                                                                                                                                                                                                           $routeDefinitionCallback
     * @param array{routeParser?: class-string<RouteParser>, dataGenerator?: class-string<DataGenerator>, dispatcher?: class-string<Dispatcher>, routeCollector?: class-string<ConfigureRoutes>, cacheDisabled?: bool, cacheKey?: string, cacheFile?: string, cacheDriver?: class-string<Cache>|Cache} $options
     */
    #[Deprecated('use FastRoute::recommendedSettings() instead', since: '1.0')]
    function cachedDispatcher(callable $routeDefinitionCallback, array $options = []): Dispatcher
    {
        return LegacyDispatcherFactory::create($routeDefinitionCallback, $options);
    }
}
