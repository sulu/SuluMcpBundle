<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\PHPUnit100\Rector\Class_\StaticDataProviderClassMethodRector;
use Rector\PHPUnit\Set\PHPUnitSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        // Generated container/cache artifacts from booting the functional test kernel.
        __DIR__ . '/tests/Application/var',
    ])
    ->withImportNames(importShortClasses: false)
    ->withSets([
        // Currently disabled as code is not typed enough:
        // SetList::CODE_QUALITY,
        // LevelSetList::UP_TO_PHP_82,
        PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
    ])
    ->withRules([
        StaticDataProviderClassMethodRector::class,
    ]);
