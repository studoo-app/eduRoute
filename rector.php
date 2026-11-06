<?php
declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\For_\RemoveDeadLoopRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;

/*
 * Rector garantit que le code reste au niveau PHP 8.4.
 * En CI il tourne en "--dry-run" et échoue si une modernisation est possible.
 * Pour appliquer : `make rector-fix`, puis relire le diff (et lancer les tests !).
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
    ->withSkip([
        // Le standard de code (lcobucci) impose self::assert*() dans les tests
        PreferPHPUnitThisCallRector::class,
        // Supprime des boucles à corps vide qui ont un effet de bord (cf. ancien GroupPosBased)
        RemoveDeadLoopRector::class,
    ]);
