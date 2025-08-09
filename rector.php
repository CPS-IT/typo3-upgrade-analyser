<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkipPath('tests/Integration/Fixtures/TYPO3Installations/BrokenInstallation/')
    ->withSkipPath('tests/Fixtures/Configuration/InvalidSyntax.php')
    // uncomment to reach your current PHP version
    // ->withPhpSets()
    ->withTypeCoverageLevel(40)
    ->withDeadCodeLevel(4)
    ->withCodeQualityLevel(10);
