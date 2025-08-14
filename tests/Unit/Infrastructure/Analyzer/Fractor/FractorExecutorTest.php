<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Fractor;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(FractorExecutor::class)]
class FractorExecutorTest extends TestCase
{
    private FractorExecutor $executor;
    private MockObject&LoggerInterface $logger;
    private string $tempBinaryPath;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tempBinaryPath = tempnam(sys_get_temp_dir(), 'fractor_test_');

        $this->executor = new FractorExecutor(
            $this->tempBinaryPath,
            $this->logger,
            300,
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempBinaryPath)) {
            unlink($this->tempBinaryPath);
        }
    }

    #[Test]
    public function isAvailableReturnsFalseWhenBinaryNotExists(): void
    {
        $nonExistentPath = '/nonexistent/path/fractor';
        $executor = new FractorExecutor($nonExistentPath, $this->logger);

        self::assertFalse($executor->isAvailable());
    }

    #[Test]
    public function isAvailableDoesNotThrowExceptions(): void
    {
        // Test that the executor handles availability checking gracefully without throwing exceptions
        // Even if the binary path points to an invalid file, it should not throw exceptions

        $exceptionThrown = false;
        try {
            $this->executor->isAvailable();
        } catch (\Throwable $e) {
            $exceptionThrown = true;
        }

        // Verify no exception was thrown during availability check
        self::assertFalse($exceptionThrown, 'isAvailable() should not throw exceptions');
    }
}
