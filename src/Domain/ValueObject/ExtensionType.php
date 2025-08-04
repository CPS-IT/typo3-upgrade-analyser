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
 * Represents different types of TYPO3 extensions based on their source and installation method.
 */
enum ExtensionType: string
{
    case SYSTEM = 'system';
    case LOCAL = 'local';
    case COMPOSER = 'composer';

    public function isSystemExtension(): bool
    {
        return self::SYSTEM === $this;
    }

    public function isLocalExtension(): bool
    {
        return self::LOCAL === $this;
    }

    public function isComposerExtension(): bool
    {
        return self::COMPOSER === $this;
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::SYSTEM => 'System Extension',
            self::LOCAL => 'Local Extension',
            self::COMPOSER => 'Composer Extension',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SYSTEM => 'Core TYPO3 system extension (typo3/sysext/)',
            self::LOCAL => 'Local extension installed in typo3conf/ext/',
            self::COMPOSER => 'Extension managed via Composer (vendor/)',
        };
    }

    public function getTypicalPath(string $basePath = ''): string
    {
        return match ($this) {
            self::SYSTEM => $basePath . '/typo3/sysext/',
            self::LOCAL => $basePath . '/typo3conf/ext/',
            self::COMPOSER => $basePath . '/vendor/',
        };
    }
}
