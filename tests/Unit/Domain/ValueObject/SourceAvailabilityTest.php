<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\ValueObject;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\SourceAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceAvailability::class)]
final class SourceAvailabilityTest extends TestCase
{
    #[Test]
    public function availableHasCorrectBackingValue(): void
    {
        self::assertSame('available', SourceAvailability::Available->value);
    }

    #[Test]
    public function unavailableHasCorrectBackingValue(): void
    {
        self::assertSame('unavailable', SourceAvailability::Unavailable->value);
    }

    #[Test]
    public function unknownHasCorrectBackingValue(): void
    {
        self::assertSame('unknown', SourceAvailability::Unknown->value);
    }

    #[Test]
    public function allCasesAreEnumerable(): void
    {
        $cases = SourceAvailability::cases();
        self::assertCount(3, $cases);

        $values = array_map(static fn (SourceAvailability $c): string => $c->value, $cases);
        self::assertContains('available', $values);
        self::assertContains('unavailable', $values);
        self::assertContains('unknown', $values);
    }
}
