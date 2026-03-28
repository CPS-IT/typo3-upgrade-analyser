<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;

interface VersionSourceInterface
{
    /**
     * Get the unique name of the source (e.g., 'ter', 'packagist', 'git').
     */
    public function getName(): string;

    /**
     * Check version availability for the extension.
     *
     * @return array<string, mixed> Map of metric names to values
     */
    public function checkAvailability(Extension $extension, AnalysisContext $context): array;
}
