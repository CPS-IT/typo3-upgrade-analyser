<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VcsResolutionResult::class)]
class VcsResolutionResultTest extends TestCase
{
    private const SOURCE_URL = 'https://github.com/vendor/package';

    public function testResolvedCompatibleHasVersion(): void
    {
        $result = new VcsResolutionResult(VcsResolutionStatus::RESOLVED_COMPATIBLE, self::SOURCE_URL, '1.2.3');

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame(self::SOURCE_URL, $result->sourceUrl);
        self::assertSame('1.2.3', $result->latestCompatibleVersion);
    }

    public function testResolvedNoMatchHasNullVersion(): void
    {
        $result = new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, self::SOURCE_URL, null);

        self::assertNull($result->latestCompatibleVersion);
    }

    public function testShouldTryFallbackReturnsFalseForResolvedCompatible(): void
    {
        $result = new VcsResolutionResult(VcsResolutionStatus::RESOLVED_COMPATIBLE, self::SOURCE_URL, '1.0.0');
        self::assertFalse($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsFalseForResolvedNoMatch(): void
    {
        $result = new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, self::SOURCE_URL, null);
        self::assertFalse($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsTrueForNotFound(): void
    {
        $result = new VcsResolutionResult(VcsResolutionStatus::NOT_FOUND, self::SOURCE_URL, null);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsTrueForFailure(): void
    {
        $result = new VcsResolutionResult(VcsResolutionStatus::FAILURE, self::SOURCE_URL, null);
        self::assertTrue($result->shouldTryFallback());
    }
}
