<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Domain\ValueObject;

/**
 * Represents different TYPO3 installation modes
 * 
 * Currently focuses on Composer-based installations as specified
 * in the InstallationDiscoverySystem feature plan.
 */
enum InstallationMode: string
{
    case COMPOSER = 'composer';

    public function isComposerMode(): bool
    {
        return $this === self::COMPOSER;
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::COMPOSER => 'Composer Installation',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::COMPOSER => 'Modern TYPO3 installation managed via Composer with vendor directory and composer.json',
        };
    }
}