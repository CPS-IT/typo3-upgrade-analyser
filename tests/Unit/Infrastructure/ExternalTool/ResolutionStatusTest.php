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

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ResolutionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolutionStatus::class)]
class ResolutionStatusTest extends TestCase
{
    public function testCasesHaveExpectedValues(): void
    {
        self::assertSame('resolved_compatible', ResolutionStatus::RESOLVED_COMPATIBLE->value);
        self::assertSame('resolved_no_match', ResolutionStatus::RESOLVED_NO_MATCH->value);
        self::assertSame('not_on_packagist', ResolutionStatus::NOT_ON_PACKAGIST->value);
        self::assertSame('failure', ResolutionStatus::FAILURE->value);
    }

    public function testFromBackingValue(): void
    {
        self::assertSame(ResolutionStatus::RESOLVED_COMPATIBLE, ResolutionStatus::from('resolved_compatible'));
        self::assertSame(ResolutionStatus::RESOLVED_NO_MATCH, ResolutionStatus::from('resolved_no_match'));
        self::assertSame(ResolutionStatus::NOT_ON_PACKAGIST, ResolutionStatus::from('not_on_packagist'));
        self::assertSame(ResolutionStatus::FAILURE, ResolutionStatus::from('failure'));
    }
}
