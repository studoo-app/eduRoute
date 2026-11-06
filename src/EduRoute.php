<?php
declare(strict_types=1);

namespace Studoo\EduRoute;

use Closure;
use Studoo\EduRoute\Cache\FileCache;

use function assert;
use function is_string;

/** @phpstan-import-type ProcessedData from ConfigureRoutes */
final class EduRoute
{
    /** @var ProcessedData|null */
    private ?array $processedConfiguration = null;

    /**
     * Les propriétés sont privées et ne sont modifiées que sur un clone fraîchement créé
     * (voir copy()) : chaque méthode with*()/use*() renvoie une nouvelle instance.
     *
     * @param Closure(ConfigureRoutes):void  $routeDefinitionCallback
     * @param class-string<RouteParser>      $routeParser
     * @param class-string<DataGenerator>    $dataGenerator
     * @param class-string<Dispatcher>       $dispatcher
     * @param class-string<ConfigureRoutes>  $routesConfiguration
     * @param class-string<GenerateUri>      $uriGenerator
     * @param Cache|class-string<Cache>|null $cacheDriver
     * @param non-empty-string|null          $cacheKey
     */
    private function __construct(
        private Closure $routeDefinitionCallback,
        private string $routeParser,
        private string $dataGenerator,
        private string $dispatcher,
        private string $routesConfiguration,
        private string $uriGenerator,
        private Cache|string|null $cacheDriver,
        private ?string $cacheKey,
    ) {
    }

    /**
     * @param Closure(ConfigureRoutes):void $routeDefinitionCallback
     * @param non-empty-string              $cacheKey
     */
    public static function recommendedSettings(Closure $routeDefinitionCallback, string $cacheKey): self
    {
        return new self(
            routeDefinitionCallback: $routeDefinitionCallback,
            routeParser: RouteParser\Std::class,
            dataGenerator: DataGenerator\MarkBased::class,
            dispatcher: Dispatcher\MarkBased::class,
            routesConfiguration: RouteCollector::class,
            uriGenerator: GenerateUri\FromProcessedConfiguration::class,
            cacheDriver: FileCache::class,
            cacheKey: $cacheKey,
        );
    }

    public function disableCache(): self
    {
        $new = $this->copy();
        $new->cacheDriver = null;
        $new->cacheKey = null;

        return $new;
    }

    /**
     * @param Cache|class-string<Cache> $driver
     * @param non-empty-string          $cacheKey
     */
    public function withCache(Cache|string $driver, string $cacheKey): self
    {
        $new = $this->copy();
        $new->cacheDriver = $driver;
        $new->cacheKey = $cacheKey;

        return $new;
    }

    public function useCharCountDispatcher(): self
    {
        return $this->useCustomDispatcher(DataGenerator\CharCountBased::class, Dispatcher\CharCountBased::class);
    }

    public function useGroupCountDispatcher(): self
    {
        return $this->useCustomDispatcher(DataGenerator\GroupCountBased::class, Dispatcher\GroupCountBased::class);
    }

    public function useGroupPosDispatcher(): self
    {
        return $this->useCustomDispatcher(DataGenerator\GroupPosBased::class, Dispatcher\GroupPosBased::class);
    }

    public function useMarkDispatcher(): self
    {
        return $this->useCustomDispatcher(DataGenerator\MarkBased::class, Dispatcher\MarkBased::class);
    }

    /**
     * @param class-string<DataGenerator> $dataGenerator
     * @param class-string<Dispatcher>    $dispatcher
     */
    public function useCustomDispatcher(string $dataGenerator, string $dispatcher): self
    {
        $new = $this->copy();
        $new->dataGenerator = $dataGenerator;
        $new->dispatcher = $dispatcher;

        return $new;
    }

    /** @param class-string<GenerateUri> $uriGenerator */
    public function withUriGenerator(string $uriGenerator): self
    {
        $new = $this->copy();
        $new->uriGenerator = $uriGenerator;

        return $new;
    }

    /**
     * Clone sans les routes déjà calculées : leur format dépend du générateur de données,
     * elles ne doivent jamais être réutilisées par une configuration différente.
     */
    private function copy(): self
    {
        $new = clone $this;
        $new->processedConfiguration = null;

        return $new;
    }

    /** @return ProcessedData */
    private function buildConfiguration(): array
    {
        if ($this->processedConfiguration !== null) {
            return $this->processedConfiguration;
        }

        $loader = function (): array {
            $configuredRoutes = new $this->routesConfiguration(
                new $this->routeParser(),
                new $this->dataGenerator(),
            );

            ($this->routeDefinitionCallback)($configuredRoutes);

            return $configuredRoutes->processedRoutes();
        };

        if ($this->cacheDriver === null) {
            return $this->processedConfiguration = $loader();
        }

        assert(is_string($this->cacheKey));

        $cache = is_string($this->cacheDriver)
            ? new $this->cacheDriver()
            : $this->cacheDriver;

        return $this->processedConfiguration = $cache->get($this->cacheKey, $loader);
    }

    public function dispatcher(): Dispatcher
    {
        return new $this->dispatcher($this->buildConfiguration());
    }

    public function uriGenerator(): GenerateUri
    {
        return new $this->uriGenerator($this->buildConfiguration()[2]);
    }
}
