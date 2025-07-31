<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Application\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand;

/**
 * Test case for the InitConfigCommand
 *
 * @covers \CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand
 */
class InitConfigCommandTest extends TestCase
{
    private InitConfigCommand $command;
    private CommandTester $commandTester;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->command = new InitConfigCommand();
        
        $application = new Application();
        $application->add($this->command);
        
        $this->commandTester = new CommandTester($this->command);
        $this->tempDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDir();
    }

    public function testCommandConfiguration(): void
    {
        self::assertEquals('init-config', $this->command->getName());
        self::assertEquals('Generate a configuration file for analysis', $this->command->getDescription());
        
        $definition = $this->command->getDefinition();
        
        // Check options
        self::assertTrue($definition->hasOption('interactive'));
        self::assertTrue($definition->hasOption('output'));
        
        // Check default values
        self::assertEquals('typo3-analyzer.yaml', $definition->getOption('output')->getDefault());
        self::assertFalse($definition->getOption('interactive')->getDefault());
    }

    public function testExecuteWithDefaultConfiguration(): void
    {
        $outputFile = $this->tempDir . '/test-config.yaml';
        
        $this->commandTester->execute([
            '--output' => $outputFile,
        ]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Configuration file generated', $this->commandTester->getDisplay());
        
        // Verify file was created
        self::assertFileExists($outputFile);
        
        // Verify content structure
        $content = file_get_contents($outputFile);
        self::assertStringContainsString('analysis:', $content);
        self::assertStringContainsString('target_version:', $content);
        self::assertStringContainsString('reporting:', $content);
        self::assertStringContainsString('external_tools:', $content);
        
        // Verify YAML is valid
        $config = Yaml::parse($content);
        self::assertIsArray($config);
        self::assertArrayHasKey('analysis', $config);
        self::assertArrayHasKey('reporting', $config);
        self::assertArrayHasKey('external_tools', $config);
    }

    public function testExecuteWithFileWriteFailure(): void
    {
        // Try to write to a non-existent directory
        $invalidPath = '/non/existent/directory/config.yaml';
        
        $this->commandTester->execute([
            '--output' => $invalidPath,
        ]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Failed to write configuration', $this->commandTester->getDisplay());
    }

    public function testDefaultConfigurationStructure(): void
    {
        $outputFile = $this->tempDir . '/config-structure-test.yaml';
        
        $this->commandTester->execute([
            '--output' => $outputFile,
        ]);

        $content = file_get_contents($outputFile);
        $config = Yaml::parse($content);
        
        // Verify analysis section
        self::assertArrayHasKey('analysis', $config);
        self::assertEquals('12.4', $config['analysis']['target_version']);
        self::assertEquals(['8.1', '8.2', '8.3'], $config['analysis']['php_versions']);
        
        // Verify analyzers configuration
        self::assertArrayHasKey('analyzers', $config['analysis']);
        $analyzers = $config['analysis']['analyzers'];
        
        self::assertArrayHasKey('version_availability', $analyzers);
        self::assertTrue($analyzers['version_availability']['enabled']);
        self::assertEquals(['ter', 'packagist', 'github'], $analyzers['version_availability']['sources']);
        
        self::assertArrayHasKey('static_analysis', $analyzers);
        self::assertTrue($analyzers['static_analysis']['enabled']);
        
        self::assertArrayHasKey('deprecation_scanner', $analyzers);
        self::assertTrue($analyzers['deprecation_scanner']['enabled']);
        
        // Verify reporting section
        self::assertArrayHasKey('reporting', $config);
        self::assertEquals(['html', 'json'], $config['reporting']['formats']);
        self::assertEquals('tests/upgradeAnalysis', $config['reporting']['output_directory']);
        self::assertTrue($config['reporting']['include_charts']);
        
        // Verify external tools section
        self::assertArrayHasKey('external_tools', $config);
        self::assertArrayHasKey('rector', $config['external_tools']);
        self::assertArrayHasKey('fractor', $config['external_tools']);
        self::assertArrayHasKey('typoscript_lint', $config['external_tools']);
    }

    public function testExecuteWithCustomOutputPath(): void
    {
        $customPath = $this->tempDir . '/custom/path/analyzer-config.yaml';
        
        // Create directory structure
        mkdir(dirname($customPath), 0755, true);
        
        $this->commandTester->execute([
            '--output' => $customPath,
        ]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertFileExists($customPath);
        
        $display = $this->commandTester->getDisplay();
        // Check for success message and file path components (handles console line wrapping)
        self::assertStringContainsString('Configuration file generated', $display);
        self::assertStringContainsString('analyzer-config', $display);
        self::assertStringContainsString('.yaml', $display);
    }

    public function testExecuteWithVeryLongPath(): void
    {
        // Create a very long path that will definitely cause line wrapping in console output
        $longDirName = str_repeat('very-long-directory-name-that-will-cause-wrapping-', 3);
        $customPath = $this->tempDir . '/' . $longDirName . '/analyzer-config.yaml';
        
        // Create directory structure
        mkdir(dirname($customPath), 0755, true);
        
        $this->commandTester->execute([
            '--output' => $customPath,
        ]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertFileExists($customPath);
        
        $display = $this->commandTester->getDisplay();
        // This test verifies that even with very long paths that cause line wrapping,
        // our assertion strategy works by checking for parts of the filename
        self::assertStringContainsString('Configuration file generated', $display);
        self::assertStringContainsString('analyzer-config', $display);
        self::assertStringContainsString('.yaml', $display);
    }

    /**
     * Test interactive mode (mocked since it requires user input)
     * Note: Full interactive testing would require more complex mocking
     */
    public function testInteractiveModeOption(): void
    {
        $definition = $this->command->getDefinition();
        $interactiveOption = $definition->getOption('interactive');
        
        self::assertFalse($interactiveOption->acceptValue());
        self::assertFalse($interactiveOption->isValueRequired());
        self::assertEquals('Run in interactive mode', $interactiveOption->getDescription());
    }

    public function testCommandTitle(): void
    {
        $outputFile = $this->tempDir . '/title-test.yaml';
        
        $this->commandTester->execute([
            '--output' => $outputFile,
        ]);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('TYPO3 Upgrade Analyzer - Configuration Generator', $display);
    }

    private function cleanupTempDir(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }

            rmdir($this->tempDir);
        }
    }
}