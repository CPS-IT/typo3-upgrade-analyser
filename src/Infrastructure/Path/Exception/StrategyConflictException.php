<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception;

/**
 * Exception for strategy conflicts and registration issues.
 */
class StrategyConflictException extends PathResolutionException
{
    public function getErrorCode(): string
    {
        return 'STRATEGY_CONFLICT';
    }

    public function getRetryable(): bool
    {
        return false;
    }

    public function getSeverity(): string
    {
        return 'error';
    }
}
