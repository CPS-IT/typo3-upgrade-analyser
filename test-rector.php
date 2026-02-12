<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../../app/web/typo3conf/ext/mailqueue',
    ])
    ->withSkip([
        '*/vendor/*',
        '*/node_modules/*',
        '*/.Build/*',
        '*/Documentation/*',
        '*/Tests/*',
        '*/tests/*',
    ])
    ->withSets([
        Typo3LevelSetList::UP_TO_TYPO3_12,
    ]);
