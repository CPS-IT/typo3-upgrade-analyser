<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool;

/**
 * Value object representing the result of a VCS resolution attempt.
 */
final readonly class VcsResolutionResult
{
    public function __construct(
        public VcsResolutionStatus $status,
        public string $sourceUrl,
        public ?string $latestCompatibleVersion,
    ) {
    }

    public function shouldTryFallback(): bool
    {
        return VcsResolutionStatus::NOT_FOUND === $this->status
            || VcsResolutionStatus::FAILURE === $this->status;
    }
}
