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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Shared contract for VCS version resolvers (Tier 1: Packagist, Tier 2: generic git).
 */
interface VcsResolverInterface
{
    public function resolve(string $packageName, string $vcsUrl, Version $targetVersion): VcsResolutionResult;
}
