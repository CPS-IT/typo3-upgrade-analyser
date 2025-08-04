<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ExternalToolException;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiException
 */
class TerApiExceptionTest extends TestCase
{
    public function testExceptionInheritsFromExternalToolException(): void
    {
        $exception = new TerApiException('Test message');

        self::assertInstanceOf(ExternalToolException::class, $exception);
    }

    public function testExceptionWithMessage(): void
    {
        $exception = new TerApiException('TER API failed');

        self::assertSame('TER API failed', $exception->getMessage());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new \RuntimeException('HTTP error');
        $exception = new TerApiException('TER API failed', $previous);

        self::assertSame('TER API failed', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }
}
