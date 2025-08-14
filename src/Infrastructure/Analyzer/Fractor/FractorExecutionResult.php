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
 * Result of a Fractor execution.
 */
readonly class FractorExecutionResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public bool $successful,
    ) {
    }

    public function hasOutput(): bool
    {
        return !empty(trim($this->output));
    }

    public function hasErrorOutput(): bool
    {
        return !empty(trim($this->errorOutput));
    }
}
