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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorConfigGenerator;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Test case for RectorConfigGenerator service.
 */
class RectorConfigGeneratorTest extends TestCase
{
    private RectorConfigGenerator $generator;
    private \PHPUnit\Framework\MockObject\MockObject $ruleRegistry;
    private string $tempDirectory;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/rector_test_' . uniqid();
        $this->filesystem = new Filesystem();
        $this->ruleRegistry = $this->createMock(RectorRuleRegistry::class);

        $this->generator = new RectorConfigGenerator(
            $this->ruleRegistry,
            $this->tempDirectory,
            $this->filesystem,
        );
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->tempDirectory)) {
            $this->filesystem->remove($this->tempDirectory);
        }
    }

    public function testConstructorCreatesTempDirectory(): void
    {
        $this->assertDirectoryExists($this->tempDirectory);
    }

    public function testGenerateConfig(): void
    {
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );
        $extensionPath = '/path/to/extension';

        $this->ruleRegistry
            ->expects($this->once())
            ->method('getSetsForVersionUpgrade')
            ->with($context->getCurrentVersion(), $context->getTargetVersion())
            ->willReturn(['Rule1', 'Rule2']);

        $configPath = $this->generator->generateConfig($extension, $context, $extensionPath);

        $this->assertFileExists($configPath);
        $this->assertStringStartsWith($this->tempDirectory, $configPath);
        $this->assertStringEndsWith('.php', $configPath);

        $content = file_get_contents($configPath);
        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('RectorConfig', $content);
        $this->assertStringContainsString('Rule1', $content);
        $this->assertStringContainsString('Rule2', $content);
    }

    public function testGenerateMinimalConfig(): void
    {
        $rules = ['MinimalRule1', 'MinimalRule2'];
        $targetPath = '/tmp/target';

        $configPath = $this->generator->generateMinimalConfig($rules, $targetPath);

        $this->assertFileExists($configPath);

        $content = file_get_contents($configPath);
        $this->assertStringContainsString('MinimalRule1', $content);
        $this->assertStringContainsString('MinimalRule2', $content);
        $this->assertStringContainsString($targetPath, $content);
    }

    public function testGenerateConfigForCategory(): void
    {
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );

        $this->ruleRegistry
            ->expects($this->once())
            ->method('getSetsByCategory')
            ->with('breaking_changes')
            ->willReturn(['BreakingRule1', 'BreakingRule2']);

        $configPath = $this->generator->generateConfigForCategory('breaking_changes', $extension, $context);

        $this->assertFileExists($configPath);

        $content = file_get_contents($configPath);
        $this->assertStringContainsString('BreakingRule1', $content);
        $this->assertStringContainsString('BreakingRule2', $content);
    }

    public function testGenerateConfigForCategoryWithEmptyRules(): void
    {
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );

        $this->ruleRegistry
            ->expects($this->once())
            ->method('getSetsByCategory')
            ->with('nonexistent_category')
            ->willReturn([]);

        $this->expectException(AnalyzerException::class);
        $this->expectExceptionMessage('No sets found for category: nonexistent_category');

        $this->generator->generateConfigForCategory('nonexistent_category', $extension, $context);
    }

    public function testCleanup(): void
    {
        // Generate some config files
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );

        $this->ruleRegistry
            ->method('getSetsForVersionUpgrade')
            ->willReturn(['Rule1']);

        $configPath1 = $this->generator->generateConfig($extension, $context, '/path/to/extension');
        $configPath2 = $this->generator->generateMinimalConfig(['Rule2'], '/tmp/target');

        $this->assertFileExists($configPath1);
        $this->assertFileExists($configPath2);

        // Clean up
        $this->generator->cleanup();

        $this->assertFileDoesNotExist($configPath1);
        $this->assertFileDoesNotExist($configPath2);
    }

    public function testGetPhpVersionForTypo3Versions(): void
    {
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'));

        // Test TYPO3 13+
        $context13 = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.0.0'),
        );

        $this->ruleRegistry
            ->method('getSetsForVersionUpgrade')
            ->willReturn(['Rule1']);

        $configPath = $this->generator->generateConfig($extension, $context13, '/path/to/extension');
        $content = file_get_contents($configPath);
        $this->assertStringContainsString('PhpVersion::PHP_82', $content);

        // Test TYPO3 12
        $context12 = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );

        $configPath = $this->generator->generateConfig($extension, $context12, '/path/to/extension');
        $content = file_get_contents($configPath);
        $this->assertStringContainsString('PhpVersion::PHP_81', $content);

        // Test TYPO3 11
        $context11 = new AnalysisContext(
            new Version('10.4.0'),
            new Version('11.5.0'),
        );

        $configPath = $this->generator->generateConfig($extension, $context11, '/path/to/extension');
        $content = file_get_contents($configPath);
        $this->assertStringContainsString('PhpVersion::PHP_80', $content);
    }

    public function testGetSkipPatternsForRegularExtension(): void
    {
        $extension = new Extension('my_extension', 'My Extension', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );

        $this->ruleRegistry
            ->method('getSetsForVersionUpgrade')
            ->willReturn(['Rule1']);

        $configPath = $this->generator->generateConfig($extension, $context, '/path/to/extension');
        $content = file_get_contents($configPath);

        $this->assertStringContainsString('*/vendor/*', $content);
        $this->assertStringContainsString('*/Tests/*', $content);
        $this->assertStringContainsString('*/Documentation/*', $content);
        $this->assertStringNotContainsString('*/Migrations/*', $content); // Only for system extensions
    }

    public function testGetSkipPatternsForTestExtension(): void
    {
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );

        $this->ruleRegistry
            ->method('getSetsForVersionUpgrade')
            ->willReturn(['Rule1']);

        $configPath = $this->generator->generateConfig($extension, $context, '/path/to/extension');
        $content = file_get_contents($configPath);

        $this->assertStringContainsString('*/vendor/*', $content);
        $this->assertStringNotContainsString('*/Tests/*', $content); // Tests NOT skipped for test_extension
        $this->assertStringContainsString('*/Documentation/*', $content);
        $this->assertStringNotContainsString('*/Migrations/*', $content); // Only for system extensions
    }

    public function testGetSkipPatternsForSystemExtension(): void
    {
        $extension = new Extension('core', 'Core', new Version('1.0.0'), 'system', 'typo3/cms-core');

        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );

        $this->ruleRegistry
            ->method('getSetsForVersionUpgrade')
            ->willReturn(['Rule1']);

        $configPath = $this->generator->generateConfig($extension, $context, '/path/to/extension');
        $content = file_get_contents($configPath);

        $this->assertStringContainsString('*/Migrations/*', $content); // Added for system extensions
    }

    public function testGetExtensionPathForSystemExtension(): void
    {
        $extension = new Extension('core', 'Core', new Version('1.0.0'), 'system', 'typo3/cms-core');

        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );

        $this->ruleRegistry
            ->method('getSetsForVersionUpgrade')
            ->willReturn(['Rule1']);

        $extensionPath = '/path/to/vendor/typo3/cms-core';
        $configPath = $this->generator->generateConfig($extension, $context, $extensionPath);
        $content = file_get_contents($configPath);

        $this->assertStringContainsString($extensionPath, $content);
    }

    public function testGetExtensionPathForRegularExtension(): void
    {
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );

        $this->ruleRegistry
            ->method('getSetsForVersionUpgrade')
            ->willReturn(['Rule1']);

        $extensionPath = '/path/to/extensions/test_extension';
        $configPath = $this->generator->generateConfig($extension, $context, $extensionPath);
        $content = file_get_contents($configPath);

        $this->assertStringContainsString($extensionPath, $content);
    }

    public function testGenerateConfigFileContent(): void
    {
        $config = [
            'paths' => ['/tmp/target'],
            'sets' => ['Rule1', 'Rule2'],
            'php_version' => '8.1',
            'parallel' => true,
            'cache_directory' => '/tmp/cache',
            'skip' => ['*/vendor/*', '*/Tests/*'],
        ];

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('generateConfigFileContent');
        $method->setAccessible(true);

        $content = $method->invoke($this->generator, $config);

        $this->assertStringStartsWith('<?php', $content);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('use Rector\\Config\\RectorConfig', $content);
        $this->assertStringContainsString('$rectorConfig->paths(array', $content);
        $this->assertStringContainsString('Rule1', $content);
        $this->assertStringContainsString('Rule2', $content);
        $this->assertStringContainsString('PhpVersion::PHP_81', $content);
        $this->assertStringContainsString('$rectorConfig->parallel()', $content);
        $this->assertStringContainsString('/tmp/cache', $content);
        $this->assertStringContainsString('*/vendor/*', $content);
        $this->assertStringContainsString('*/Tests/*', $content);
    }

    public function testGetPhpVersionConstant(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('getPhpVersionConstant');
        $method->setAccessible(true);

        $this->assertEquals('PhpVersion::PHP_74', $method->invoke($this->generator, '7.4'));
        $this->assertEquals('PhpVersion::PHP_80', $method->invoke($this->generator, '8.0'));
        $this->assertEquals('PhpVersion::PHP_81', $method->invoke($this->generator, '8.1'));
        $this->assertEquals('PhpVersion::PHP_82', $method->invoke($this->generator, '8.2'));
        $this->assertEquals('PhpVersion::PHP_83', $method->invoke($this->generator, '8.3'));

        // Test default fallback
        $this->assertEquals('PhpVersion::PHP_81', $method->invoke($this->generator, '9.0'));
    }
}
