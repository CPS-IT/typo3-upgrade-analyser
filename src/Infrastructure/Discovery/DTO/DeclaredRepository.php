<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DTO;

/**
 * Represents a VCS repository declared in composer.lock or composer.json,
 * grouping all packages sourced from the same URL.
 */
final readonly class DeclaredRepository
{
    /** @param array<string> $packages */
    public function __construct(
        public string $url,
        public array $packages,
    ) {
    }
}
