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
 * Shared contract for VCS version resolvers.
 */
interface VcsResolverInterface
{
    /**
     * @param Version|null $installedVersion Lower-bound filter: only versions strictly newer than this are checked.
     *                                       If null, the newest 50 versions are scanned.
     */
    public function resolve(string $packageName, ?string $vcsUrl, Version $targetVersion, ?Version $installedVersion = null): VcsResolutionResult;
}
