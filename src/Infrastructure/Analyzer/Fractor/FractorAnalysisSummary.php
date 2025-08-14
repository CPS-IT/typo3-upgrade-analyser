<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor;

/**
 * Summary of Fractor analysis results.
 */
readonly class FractorAnalysisSummary
{
    /**
     * @param array<string> $findings
     * @param array<string> $filePaths
     * @param array<string> $appliedRules
     */
    public function __construct(
        public int $filesScanned,
        public int $rulesApplied,
        public array $findings,
        public bool $successful,
        public int $changeBlocks = 0,
        public int $changedLines = 0,
        public array $filePaths = [],
        public array $appliedRules = [],
        public ?string $errorMessage = null,
    ) {
    }

    public function hasFindings(): bool
    {
        return $this->filesScanned > 0 || $this->getTotalIssues() > 0;
    }

    public function getTotalIssues(): int
    {
        return $this->rulesApplied;
    }
}
