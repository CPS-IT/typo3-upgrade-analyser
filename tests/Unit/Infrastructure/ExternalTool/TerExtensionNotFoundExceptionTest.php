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

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerExtensionNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TerExtensionNotFoundException::class)]
class TerExtensionNotFoundExceptionTest extends TestCase
{
    public function testExceptionInheritsFromTerApiException(): void
    {
        $exception = new TerExtensionNotFoundException('test_extension');

        self::assertSame('Extension "test_extension" not found in TER', $exception->getMessage());
    }

    public function testExceptionMessageContainsExtensionKey(): void
    {
        $exception = new TerExtensionNotFoundException('news');

        self::assertSame('Extension "news" not found in TER', $exception->getMessage());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new \RuntimeException('HTTP 404 error');
        $exception = new TerExtensionNotFoundException('test_extension', $previous);

        self::assertSame('Extension "test_extension" not found in TER', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }
}
