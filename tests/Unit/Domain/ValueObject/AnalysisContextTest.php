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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalysisContext::class)]
final class AnalysisContextTest extends TestCase
{
    private Version $current;
    private Version $target;

    protected function setUp(): void
    {
        $this->current = new Version('12.4.0');
        $this->target = new Version('13.4.0');
    }

    #[Test]
    public function existingPropertiesAreAccessible(): void
    {
        $context = new AnalysisContext($this->current, $this->target, ['8.1'], ['key' => 'val']);
        self::assertSame($this->current, $context->getCurrentVersion());
        self::assertSame($this->target, $context->getTargetVersion());
        self::assertSame(['8.1'], $context->getPhpVersions());
        self::assertSame(['key' => 'val'], $context->getConfiguration());
    }

    #[Test]
    public function withConfigurationMergesValues(): void
    {
        $context = new AnalysisContext($this->current, $this->target, [], ['a' => 1]);
        $updated = $context->withConfiguration(['b' => 2]);
        self::assertSame(['a' => 1, 'b' => 2], $updated->getConfiguration());
    }

    #[Test]
    public function withPhpVersionsReplacesVersions(): void
    {
        $context = new AnalysisContext($this->current, $this->target);
        $updated = $context->withPhpVersions(['8.2']);
        self::assertSame(['8.2'], $updated->getPhpVersions());
    }
}
