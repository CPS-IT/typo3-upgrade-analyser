<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum;

/**
 * Installation type enumeration with detection capabilities.
 */
enum InstallationTypeEnum: string
{
    case COMPOSER_STANDARD = 'composer_standard';
    case COMPOSER_CUSTOM = 'composer_custom';
    case LEGACY_SOURCE = 'legacy_source';
    case DOCKER_CONTAINER = 'docker_container';
    case CUSTOM = 'custom';
    case AUTO_DETECT = 'auto_detect';

    /**
     * Get typical directory structure for this installation type.
     */
    public function getTypicalDirectories(): array
    {
        return match ($this) {
            self::COMPOSER_STANDARD => ['vendor', 'public', 'var'],
            self::COMPOSER_CUSTOM => ['vendor', 'web', 'var'],
            self::LEGACY_SOURCE => ['typo3_src', 'typo3conf', 'fileadmin'],
            self::DOCKER_CONTAINER => ['app', 'vendor', 'public'],
            default => [],
        };
    }
}
