<?php
declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;

/*
 * Rector sert d'assistant à la modernisation PHP 8.4 (phase 2).
 * En CI il tourne en "--dry-run" : il signale ce qui reste à moderniser
 * sans modifier le code. Pour appliquer : `make rector-fix`, puis relire le diff.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/benchmark',
        __DIR__ . '/src',
        __DIR__ . '/test',
    ])
    ->withCache(__DIR__ . '/.rector.cache')
    ->withPhpSets(php84: true)
    ->withAttributesSets(phpunit: true)
    ->withComposerBased(phpunit: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSkip([
        // Le standard de code (lcobucci) impose self::assert*() dans les tests
        PreferPHPUnitThisCallRector::class,
    ]);
