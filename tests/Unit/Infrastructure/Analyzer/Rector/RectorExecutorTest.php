<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Rector;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test case for RectorExecutor service.
 */
class RectorExecutorTest extends TestCase
{
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
    }

    public function testIsAvailableWithValidBinary(): void
    {
        // Use the actual configured rector binary path
        $rectorPath = getcwd() . '/vendor/bin/rector';

        // Create a temporary executable file if rector doesn't exist
        $tempFile = null;
        if (!file_exists($rectorPath) || !is_executable($rectorPath)) {
            $tempFile = tempnam(sys_get_temp_dir(), 'rector_test_');
            chmod($tempFile, 0o755);
            $executor = new RectorExecutor($tempFile, $this->logger, 300);
        } else {
            $executor = new RectorExecutor($rectorPath, $this->logger, 300);
        }

        $this->assertTrue($executor->isAvailable());

        // Clean up temp file
        if ($tempFile && file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function testIsAvailableWithInvalidBinary(): void
    {
        $executor = new RectorExecutor('/non/existent/rector', $this->logger, 300);

        $this->assertFalse($executor->isAvailable());
    }

    public function testExecuteWithUnavailableBinary(): void
    {
        $executor = new RectorExecutor('/non/existent/rector', $this->logger, 300);

        $this->expectException(AnalyzerException::class);
        $this->expectExceptionMessage('Rector binary not found');

        $executor->execute('/tmp/config.php', '/tmp/target', []);
    }

    public function testGetVersionWithUnavailableBinary(): void
    {
        $executor = new RectorExecutor('/non/existent/rector', $this->logger, 300);

        $version = $executor->getVersion();

        $this->assertNull($version);
    }

    /**
     * @dataProvider buildCommandDataProvider
     */
    public function testBuildCommand(string $configPath, string $targetPath, array $options, array $expectedInCommand): void
    {
        $executor = new RectorExecutor('/usr/bin/rector', $this->logger, 300);

        // Use reflection to test private method
        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('buildCommand');
        $method->setAccessible(true);

        $command = $method->invoke($executor, $configPath, $targetPath, $options);

        $this->assertIsArray($command);

        foreach ($expectedInCommand as $expectedPart) {
            $this->assertContains($expectedPart, $command);
        }
    }

    public static function buildCommandDataProvider(): array
    {
        return [
            'basic command' => [
                '/tmp/config.php',
                '/tmp/target',
                [],
                ['/usr/bin/rector', 'process', '/tmp/target', '--config', '/tmp/config.php', '--dry-run', '--output-format', 'json', '--no-progress-bar'],
            ],
            'with memory limit' => [
                '/tmp/config.php',
                '/tmp/target',
                ['memory_limit' => '2G'],
                ['--memory-limit', '2G'],
            ],
            'with debug flag' => [
                '/tmp/config.php',
                '/tmp/target',
                ['debug' => true],
                ['--debug'],
            ],
            'with clear cache flag' => [
                '/tmp/config.php',
                '/tmp/target',
                ['clear_cache' => true],
                ['--clear-cache'],
            ],
        ];
    }

    public function testCreateTempConfigWithRules(): void
    {
        $executor = new RectorExecutor('/usr/bin/rector', $this->logger, 300);

        $rules = ['Rule1', 'Rule2'];
        $targetPath = '/tmp/target';

        // Use reflection to test private method
        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('createTempConfigWithRules');
        $method->setAccessible(true);

        $configFile = $method->invoke($executor, $rules, $targetPath);

        $this->assertFileExists($configFile);

        $content = file_get_contents($configFile);
        if (!$content) {
            $this->fail('Failed to read config file');
        }
        $this->assertStringContainsString('Rule1::class', $content);
        $this->assertStringContainsString('Rule2::class', $content);
        $this->assertStringContainsString($targetPath, $content);

        // Clean up
        unlink($configFile);
    }
}
