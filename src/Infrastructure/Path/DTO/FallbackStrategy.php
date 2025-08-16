<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO;

/**
 * Fallback strategy object for ordered fallback handling.
 */
final readonly class FallbackStrategy
{
    public function __construct(
        public string $strategyClass,
        public int $priority,
        public array $options = [],
    ) {
    }
}
