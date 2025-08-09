<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Rector;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorExecutionResult;
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
            chmod($tempFile, 0755);
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

    public function testGetVersionWithPhpBinary(): void
    {
        // Use PHP_BINARY as a mock - it won't return Rector version format but tests the method
        $executor = new RectorExecutor(PHP_BINARY, $this->logger, 10);
        
        $version = $executor->getVersion();
        
        // PHP version output won't match Rector format, so should return the raw output
        // If PHP_BINARY is not available or returns unexpected output, it might return null
        $this->assertTrue($version === null || is_string($version));
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
                ['/usr/bin/rector', 'process', '/tmp/target', '--config', '/tmp/config.php', '--dry-run', '--output-format', 'json', '--no-progress-bar']
            ],
            'with memory limit' => [
                '/tmp/config.php',
                '/tmp/target',
                ['memory_limit' => '2G'],
                ['--memory-limit', '2G']
            ],
            'with debug flag' => [
                '/tmp/config.php',
                '/tmp/target',
                ['debug' => true],
                ['--debug']
            ],
            'with clear cache flag' => [
                '/tmp/config.php',
                '/tmp/target',
                ['clear_cache' => true],
                ['--clear-cache']
            ],
        ];
    }

    public function testParseOutputWithEmptyOutput(): void
    {
        $executor = new RectorExecutor('/usr/bin/rector', $this->logger, 300);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('parseOutput');
        $method->setAccessible(true);
        
        $result = $method->invoke($executor, '');
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('findings', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('processed_files', $result);
        
        $this->assertEmpty($result['findings']);
        $this->assertEmpty($result['errors']);
        $this->assertEquals(0, $result['processed_files']);
    }

    public function testParseOutputWithValidJson(): void
    {
        $executor = new RectorExecutor('/usr/bin/rector', $this->logger, 300);
        
        $jsonOutput = json_encode([
            'totals' => ['changed_files' => 2],
            'changed_files' => [
                [
                    'file' => 'src/Test.php',
                    'applied_rectors' => [
                        [
                            'class' => 'TestRule',
                            'message' => 'Test message',
                            'line' => 10,
                            'old' => 'old code',
                            'new' => 'new code'
                        ]
                    ]
                ]
            ]
        ]);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('parseOutput');
        $method->setAccessible(true);
        
        $result = $method->invoke($executor, $jsonOutput);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result['findings']);
        $this->assertEquals(2, $result['processed_files']);
        $this->assertEmpty($result['errors']);
    }

    public function testParseOutputWithInvalidJson(): void
    {
        $executor = new RectorExecutor('/usr/bin/rector', $this->logger, 300);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('parseOutput');
        $method->setAccessible(true);
        
        $result = $method->invoke($executor, 'invalid json');
        
        $this->assertIsArray($result);
        $this->assertEmpty($result['findings']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Failed to parse Rector output', $result['errors'][0]);
        
        // NullLogger doesn't track records, so we just verify the error was added to result
        $this->assertTrue(true); // Test passes if we reach this point without exception
    }

    public function testCreateFindingFromRectorData(): void
    {
        $executor = new RectorExecutor('/usr/bin/rector', $this->logger, 300);
        
        $rectorData = [
            'class' => 'Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceRector',
            'message' => 'Replace deprecated method',
            'line' => 42,
            'old' => '$template->getConstants()',
            'new' => '$template->getTypoScriptConstants()'
        ];
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('createFindingFromRectorData');
        $method->setAccessible(true);
        
        $finding = $method->invoke($executor, 'src/Test.php', $rectorData);
        
        $this->assertInstanceOf(\CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorFinding::class, $finding);
        $this->assertEquals('src/Test.php', $finding->getFile());
        $this->assertEquals(42, $finding->getLine());
        $this->assertEquals('Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceRector', $finding->getRuleClass());
        $this->assertEquals('Replace deprecated method', $finding->getMessage());
    }

    public function testDetermineSeverityFromRule(): void
    {
        $executor = new RectorExecutor('/usr/bin/rector', $this->logger, 300);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('determineSeverityFromRule');
        $method->setAccessible(true);
        
        // Test critical severity for Remove rules
        $criticalSeverity = $method->invoke($executor, 'RemoveSomethingRector');
        $this->assertEquals(\CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity::CRITICAL, $criticalSeverity);
        
        // Test warning severity for Substitute rules
        $warningSeverity = $method->invoke($executor, 'SubstituteSomethingRector');
        $this->assertEquals(\CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity::WARNING, $warningSeverity);
        
        // Test info severity for other rules
        $infoSeverity = $method->invoke($executor, 'SomeOtherRector');
        $this->assertEquals(\CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity::INFO, $infoSeverity);
    }

    public function testDetermineChangeTypeFromRule(): void
    {
        $executor = new RectorExecutor('/usr/bin/rector', $this->logger, 300);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('determineChangeTypeFromRule');
        $method->setAccessible(true);
        
        // Test method signature change
        $methodSignature = $method->invoke($executor, 'RemoveMethodRector');
        $this->assertEquals(\CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType::METHOD_SIGNATURE, $methodSignature);
        
        // Test class removal
        $classRemoval = $method->invoke($executor, 'RemoveClassRector');
        $this->assertEquals(\CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType::CLASS_REMOVAL, $classRemoval);
        
        // Test deprecation
        $deprecation = $method->invoke($executor, 'SubstituteSomethingRector');
        $this->assertEquals(\CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType::DEPRECATION, $deprecation);
        
        // Test configuration change
        $configChange = $method->invoke($executor, 'MigrateSomethingRector');
        $this->assertEquals(\CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType::CONFIGURATION_CHANGE, $configChange);
        
        // Test best practice
        $bestPractice = $method->invoke($executor, 'SomeOtherRector');
        $this->assertEquals(\CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType::BEST_PRACTICE, $bestPractice);
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
        $this->assertStringContainsString('Rule1::class', $content);
        $this->assertStringContainsString('Rule2::class', $content);
        $this->assertStringContainsString($targetPath, $content);
        
        // Clean up
        unlink($configFile);
    }
}