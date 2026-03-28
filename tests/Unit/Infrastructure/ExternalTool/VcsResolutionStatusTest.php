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

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VcsResolutionStatus::class)]
class VcsResolutionStatusTest extends TestCase
{
    public function testCasesHaveExpectedValues(): void
    {
        self::assertSame('resolved_compatible', VcsResolutionStatus::RESOLVED_COMPATIBLE->value);
        self::assertSame('resolved_no_match', VcsResolutionStatus::RESOLVED_NO_MATCH->value);
        self::assertSame('not_found', VcsResolutionStatus::NOT_FOUND->value);
        self::assertSame('failure', VcsResolutionStatus::FAILURE->value);
    }

    public function testFromBackingValue(): void
    {
        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, VcsResolutionStatus::from('resolved_compatible'));
        self::assertSame(VcsResolutionStatus::RESOLVED_NO_MATCH, VcsResolutionStatus::from('resolved_no_match'));
        self::assertSame(VcsResolutionStatus::NOT_FOUND, VcsResolutionStatus::from('not_found'));
        self::assertSame(VcsResolutionStatus::FAILURE, VcsResolutionStatus::from('failure'));
    }
}
