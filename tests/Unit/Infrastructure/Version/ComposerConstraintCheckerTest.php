<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Version;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ComposerConstraintChecker::class)]
class ComposerConstraintCheckerTest extends TestCase
{
    private ComposerConstraintChecker $subject;

    protected function setUp(): void
    {
        $this->subject = new ComposerConstraintChecker();
    }

    public static function compatibleConstraintsProvider(): iterable
    {
        yield 'compound OR - first branch matches (^13.4 || ^14.4, target 13.4)' => [
            'constraint' => '^13.4 || ^14.4',
            'version' => '13.4',
        ];

        yield 'compound OR - second branch matches (^13.4 || ^14.4, target 14.4)' => [
            'constraint' => '^13.4 || ^14.4',
            'version' => '14.4',
        ];

        yield 'compound OR with lower bound - second branch matches (^12.4 || ^13.4, target 13.4)' => [
            'constraint' => '^12.4 || ^13.4',
            'version' => '13.4',
        ];

        yield 'tilde range matches minor version (~13.4, target 13.4)' => [
            'constraint' => '~13.4',
            'version' => '13.4',
        ];

        yield 'AND range covers target (>=13.4.0,<15.0.0, target 13.4)' => [
            'constraint' => '>=13.4.0,<15.0.0',
            'version' => '13.4',
        ];

        yield 'caret on major - target within range (^13.0, target 13.4)' => [
            'constraint' => '^13.0',
            'version' => '13.4',
        ];
    }

    public static function incompatibleConstraintsProvider(): iterable
    {
        yield 'caret range below target (^12.4, target 13.4)' => [
            'constraint' => '^12.4',
            'version' => '13.4',
        ];

        yield 'caret range above target (^14.4, target 13.4)' => [
            'constraint' => '^14.4',
            'version' => '13.4',
        ];

        yield 'compound OR - both branches above target (^14.0 || ^15.0, target 13.4)' => [
            'constraint' => '^14.0 || ^15.0',
            'version' => '13.4',
        ];
    }

    #[Test]
    #[DataProvider('compatibleConstraintsProvider')]
    public function isConstraintCompatibleReturnsTrueForCompatibleConstraint(string $constraint, string $version): void
    {
        $target = new Version($version);

        self::assertTrue(
            $this->subject->isConstraintCompatible($constraint, $target),
            \sprintf('Expected "%s" to be compatible with target "%s"', $constraint, $version),
        );
    }

    #[Test]
    #[DataProvider('incompatibleConstraintsProvider')]
    public function isConstraintCompatibleReturnsFalseForIncompatibleConstraint(string $constraint, string $version): void
    {
        $target = new Version($version);

        self::assertFalse(
            $this->subject->isConstraintCompatible($constraint, $target),
            \sprintf('Expected "%s" to be incompatible with target "%s"', $constraint, $version),
        );
    }

    #[Test]
    public function isConstraintCompatibleReturnsFalseForUnparseableConstraint(): void
    {
        $target = new Version('13.4');

        // An unparseable constraint must not throw and must return false (safe default)
        self::assertFalse(
            $this->subject->isConstraintCompatible('@@@invalid@@@', $target),
        );
    }

    #[Test]
    public function isConstraintCompatibleReturnsFalseForEmptyConstraint(): void
    {
        $target = new Version('13.4');

        self::assertFalse(
            $this->subject->isConstraintCompatible('', $target),
        );
    }

    #[Test]
    public function georgingerNewsV14TargetingTypo3134IsCompatible(): void
    {
        // Confirmed false-negative case: georgringer/news v14.0.1 targeting TYPO3 13.4
        // Actual Packagist constraint: ^13.4.20 || ^14.0
        // Without patch ceiling: 13.4.0.0 < 13.4.20.0.0 → false (wrong)
        // With patch ceiling: 13.4.9999.0 >= 13.4.20.0.0 → true (correct)
        $constraint = '^13.4.20 || ^14.0';
        $target = new Version('13.4');

        self::assertTrue($this->subject->isConstraintCompatible($constraint, $target));
    }

    #[Test]
    public function majorMinorTargetWithoutPatchUsesUpperBoundOfMinorSeries(): void
    {
        // When target has no explicit patch (e.g. "13.4"), the constraint is checked against
        // the ceiling of the minor series so that "^13.4.20" is treated as compatible.
        self::assertTrue($this->subject->isConstraintCompatible('^13.4.20', new Version('13.4')));
        self::assertFalse($this->subject->isConstraintCompatible('^13.4.20', new Version('13.4.0')));
        self::assertTrue($this->subject->isConstraintCompatible('^13.4.20', new Version('13.4.20')));
    }

    #[Test]
    public function friendsOfTypo3TtAddressV10TargetingTypo3134IsCompatible(): void
    {
        // Confirmed false-negative: friendsoftypo3/tt-address v10.0.0 targeting TYPO3 13.4
        $constraint = '^13.4 || ^14.4';
        $target = new Version('13.4');

        self::assertTrue($this->subject->isConstraintCompatible($constraint, $target));
    }

    #[Test]
    public function isConstraintCompatibleLogsWarningWhenConstraintCannotBeParsed(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'ComposerConstraintChecker: failed to parse constraint',
                self::arrayHasKey('constraint'),
            );

        $checker = new ComposerConstraintChecker($logger);
        $result = $checker->isConstraintCompatible('@@@invalid@@@', new Version('13.4'));

        self::assertFalse($result);
    }
}
