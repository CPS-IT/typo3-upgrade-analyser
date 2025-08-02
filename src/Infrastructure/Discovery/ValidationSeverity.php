<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

/**
 * Represents the severity level of validation issues
 */
enum ValidationSeverity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::INFO => 'Info',
            self::WARNING => 'Warning',
            self::ERROR => 'Error',
            self::CRITICAL => 'Critical',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::INFO => 'Informational message, no action required',
            self::WARNING => 'Potential issue that should be reviewed',
            self::ERROR => 'Issue that may prevent proper analysis',
            self::CRITICAL => 'Critical issue that prevents analysis',
        };
    }

    public function getNumericValue(): int
    {
        return match ($this) {
            self::INFO => 1,
            self::WARNING => 2,
            self::ERROR => 3,
            self::CRITICAL => 4,
        };
    }

    public function isBlockingAnalysis(): bool
    {
        return match ($this) {
            self::INFO, self::WARNING => false,
            self::ERROR, self::CRITICAL => true,
        };
    }

    public static function fromNumericValue(int $value): self
    {
        return match ($value) {
            1 => self::INFO,
            2 => self::WARNING,
            3 => self::ERROR,
            4 => self::CRITICAL,
            default => throw new \InvalidArgumentException("Invalid severity value: {$value}"),
        };
    }
}