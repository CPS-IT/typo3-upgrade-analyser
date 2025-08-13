<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector;

/**
 * Result object containing parsed Rector output data.
 */
final readonly class RectorOutputResult
{
    /**
     * @param array<RectorFinding> $findings
     * @param array<string>        $errors
     */
    public function __construct(
        public array $findings,
        public array $errors,
        public int $processedFiles,
    ) {
    }

    /**
     * Get the total number of findings.
     */
    public function getFindingsCount(): int
    {
        return \count($this->findings);
    }

    /**
     * Get the total number of errors.
     */
    public function getErrorsCount(): int
    {
        return \count($this->errors);
    }

    /**
     * Check if parsing was successful (no errors occurred).
     */
    public function isSuccessful(): bool
    {
        return empty($this->errors);
    }

    /**
     * Check if any findings were detected.
     */
    public function hasFindings(): bool
    {
        return !empty($this->findings);
    }
}
