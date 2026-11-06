<?php
declare(strict_types=1);

namespace Studoo\EduRoute;

use Closure;

/** @phpstan-import-type ProcessedData from ConfigureRoutes */
interface Cache
{
    /**
     * @param Closure():ProcessedData $loader
     *
     * @return ProcessedData
     */
    public function get(string $key, Closure $loader): array;
}
