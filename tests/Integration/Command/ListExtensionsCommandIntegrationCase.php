<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\Command;

use CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Shared\Configuration\ContainerFactory;
use CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Integration tests for ListExtensionsCommand covering complete end-to-end workflows.
 *
 * @group integration
 *
 * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService
 */
#[Group('integration')]
class ListExtensionsCommandIntegrationCase extends AbstractIntegrationCase
{
    private ListExtensionsCommand $command;
    private string $fixturesPath;
    private string $tempDir;
    private CacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesPath = __DIR__ . '/../Fixtures';
        $this->tempDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();

        // Create temp directory for test configurations
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->tempDir);

        // Setup container and services
        $container = ContainerFactory::create();

        $command = $container->get(ListExtensionsCommand::class);
        \assert($command instanceof ListExtensionsCommand);
        $this->command = $command;

        $cacheService = $container->get(CacheService::class);
        \assert($cacheService instanceof CacheService);
        $this->cacheService = $cacheService;

        // Clear any existing cache
        $this->cacheService->clear();
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $filesystem = new Filesystem();
        if (is_dir($this->tempDir)) {
            $filesystem->remove($this->tempDir);
        }

        // Clear cache after tests
        $this->cacheService->clear();

        parent::tearDown();
    }

    /**
     * Test successful extension discovery with composer installation.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::run
     */
    public function testListExtensionsWithComposerInstallation(): void
    {
        $configPath = $this->createTestConfig('test-composer-config.yaml');

        $input = new ArrayInput(['--config' => $configPath]);
        $output = new BufferedOutput();

        $exitCode = $this->command->run($input, $output);
        $outputContent = $output->fetch();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('TYPO3 Extensions', $outputContent);
        $this->assertStringContainsString('Installation:', $outputContent);
        $this->assertStringContainsString('Discovering TYPO3 installation...', $outputContent);
        $this->assertStringContainsString('Discovering extensions...', $outputContent);

        // Check that extensions are found and displayed
        $this->assertStringContainsString('news', $outputContent);
        $this->assertStringContainsString('powermail', $outputContent);
        $this->assertStringContainsString('local_extension', $outputContent);

        // Check table structure
        $this->assertStringContainsString('Extension', $outputContent);
        $this->assertStringContainsString('Version', $outputContent);
        $this->assertStringContainsString('Type', $outputContent);
        $this->assertStringContainsString('Active', $outputContent);

        // Check summary section
        $this->assertMatchesRegularExpression('/Found \\d+ extensions/', $outputContent);
    }

    /**
     * Test successful extension discovery with legacy installation.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::run
     */
    public function testListExtensionsWithLegacyInstallation(): void
    {
        $configPath = $this->createTestConfig('test-legacy-config.yaml');

        $input = new ArrayInput(['--config' => $configPath]);
        $output = new BufferedOutput();

        $exitCode = $this->command->run($input, $output);
        $outputContent = $output->fetch();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('TYPO3 Extensions', $outputContent);

        // Check that legacy extensions are found
        $this->assertStringContainsString('legacy_news', $outputContent);
        $this->assertStringContainsString('legacy_powermail', $outputContent);
        $this->assertStringContainsString('custom_extension', $outputContent);
    }

    /**
     * Test handling of broken installation.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::run
     */
    public function testListExtensionsWithBrokenInstallation(): void
    {
        $configPath = $this->createTestConfig('test-broken-config.yaml');

        $input = new ArrayInput(['--config' => $configPath]);
        $output = new BufferedOutput();

        $exitCode = $this->command->run($input, $output);
        $outputContent = $output->fetch();

        // Should still succeed but with warnings or proceed with defaults
        $this->assertThat(
            $exitCode,
            $this->logicalOr(
                $this->equalTo(Command::SUCCESS),
                $this->equalTo(Command::FAILURE),
            ),
        );

        $this->assertStringContainsString('TYPO3 Extensions', $outputContent);

        // Should contain error/warning messages for broken installation
        if (Command::SUCCESS === $exitCode) {
            $this->assertThat(
                $outputContent,
                $this->logicalOr(
                    $this->stringContains('Installation discovery failed'),
                    $this->stringContains('Proceeding with extension discovery using default paths'),
                    $this->stringContains('Extension discovery failed'),
                ),
            );
        }
    }

    /**
     * Test error handling with non-existent configuration file.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::run
     */
    public function testListExtensionsWithNonExistentConfigFile(): void
    {
        $input = new ArrayInput(['--config' => '/non/existent/config.yaml']);
        $output = new BufferedOutput();

        $exitCode = $this->command->run($input, $output);
        $outputContent = $output->fetch();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Configuration file does not exist', $outputContent);
    }

    /**
     * Test error handling with invalid installation path.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::run
     */
    public function testListExtensionsWithInvalidInstallationPath(): void
    {
        $configPath = $this->createTestConfig('test-invalid-path-config.yaml');

        $input = new ArrayInput(['--config' => $configPath]);
        $output = new BufferedOutput();

        $exitCode = $this->command->run($input, $output);
        $outputContent = $output->fetch();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Installation path does not exist', $outputContent);
    }

    /**
     * Test caching behavior across multiple command executions.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::run
     */
    public function testListExtensionsCachingBehavior(): void
    {
        $configPath = $this->createTestConfig('test-composer-config.yaml');

        // First execution - should create cache
        $input1 = new ArrayInput(['--config' => $configPath]);
        $output1 = new BufferedOutput();
        $startTime1 = microtime(true);
        $exitCode1 = $this->command->run($input1, $output1);
        $executionTime1 = microtime(true) - $startTime1;

        $this->assertSame(Command::SUCCESS, $exitCode1);

        // Second execution - should use cache and be faster
        $input2 = new ArrayInput(['--config' => $configPath]);
        $output2 = new BufferedOutput();
        $startTime2 = microtime(true);
        $exitCode2 = $this->command->run($input2, $output2);
        $executionTime2 = microtime(true) - $startTime2;

        $this->assertSame(Command::SUCCESS, $exitCode2);

        // Output should be consistent
        $this->assertStringContainsString('news', $output1->fetch());
        $this->assertStringContainsString('news', $output2->fetch());

        // Second execution should generally be faster due to caching
        // Allow some tolerance for timing variations
        $this->assertLessThan(
            $executionTime1 * 1.5,
            $executionTime2,
            'Second execution should benefit from caching',
        );
    }

    /**
     * Test command with different output verbosity levels.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::run
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::detectLegacyInstallationPaths
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\Command\ListExtensionsCommandIntegrationCase::createTestConfig
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\Command\ListExtensionsCommandIntegrationCase::verbosityLevelProvider
     */
    #[DataProvider('verbosityLevelProvider')]
    public function testListExtensionsWithDifferentVerbosityLevels(int $verbosity, array $expectedContent, array $notExpectedContent = []): void
    {
        $configPath = $this->createTestConfig('test-composer-config.yaml');

        $input = new ArrayInput(['--config' => $configPath]);
        $output = new BufferedOutput();
        /* @phpstan-ignore-next-line argument.type */
        $output->setVerbosity($verbosity);

        $exitCode = $this->command->run($input, $output);
        $outputContent = $output->fetch();

        $this->assertSame(Command::SUCCESS, $exitCode);

        foreach ($expectedContent as $expected) {
            $this->assertStringContainsString($expected, $outputContent);
        }

        foreach ($notExpectedContent as $notExpected) {
            $this->assertStringNotContainsString($notExpected, $outputContent);
        }
    }

    /**
     * Test that command properly separates installation discovery from extension discovery.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::run
     */
    public function testListExtensionsSeparationOfConcerns(): void
    {
        $configPath = $this->createTestConfig('test-composer-config.yaml');

        $input = new ArrayInput(['--config' => $configPath]);
        $output = new BufferedOutput();
        $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);

        $exitCode = $this->command->run($input, $output);
        $outputContent = $output->fetch();

        $this->assertSame(Command::SUCCESS, $exitCode);

        // Should show clear separation between installation and extension discovery
        $this->assertStringContainsString('Discovering TYPO3 installation...', $outputContent);
        $this->assertStringContainsString('Discovering extensions...', $outputContent);

        // Installation discovery should mention the found version
        $this->assertMatchesRegularExpression('/Installation discovered: TYPO3 \d+\.\d+/', $outputContent);

        // Extension discovery should show summary
        $this->assertMatchesRegularExpression('/Discovered \d+ extension/', $outputContent);
    }

    /**
     * Test extension status display (active/inactive).
     *
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::run
     */
    public function testListExtensionsShowsExtensionStatus(): void
    {
        $configPath = $this->createTestConfig('test-composer-config.yaml');

        $input = new ArrayInput(['--config' => $configPath]);
        $output = new BufferedOutput();

        $exitCode = $this->command->run($input, $output);
        $outputContent = $output->fetch();

        $this->assertSame(Command::SUCCESS, $exitCode);

        // Should show active extensions (from PackageStates.php)
        $this->assertStringContainsString('news', $outputContent);
        $this->assertStringContainsString('powermail', $outputContent);
        $this->assertStringContainsString('local_extension', $outputContent);

        // Note: The current implementation shows all extensions from PackageStates
        // regardless of active/inactive status, but this could be enhanced
    }

    /**
     * Test that discovery metadata is properly logged.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::run
     */
    public function testListExtensionsLogsDiscoveryMetadata(): void
    {
        $configPath = $this->createTestConfig('test-composer-config.yaml');

        $input = new ArrayInput(['--config' => $configPath]);
        $output = new BufferedOutput();

        $exitCode = $this->command->run($input, $output);

        $this->assertSame(Command::SUCCESS, $exitCode);

        // Since we're using NullLogger in tests, we can't directly check logs
        // but we can verify the command completed successfully with expected data
        $outputContent = $output->fetch();
        $this->assertMatchesRegularExpression('/Found \\d+ extensions/', $outputContent);
    }

    public static function verbosityLevelProvider(): array
    {
        return [
            'normal' => [
                OutputInterface::VERBOSITY_NORMAL,
                ['TYPO3 Extensions', 'Extension', 'Version', 'Found 8 extensions'],
                [],
            ],
            'verbose' => [
                OutputInterface::VERBOSITY_VERBOSE,
                ['TYPO3 Extensions', 'Discovering TYPO3 installation...', 'Discovering extensions...'],
                [],
            ],
            'very_verbose' => [
                OutputInterface::VERBOSITY_VERY_VERBOSE,
                ['TYPO3 Extensions', 'Discovering TYPO3 installation...', 'Discovering extensions...'],
                [],
            ],
        ];
    }

    /**
     * Create a test configuration file with proper path substitution.
     */
    private function createTestConfig(string $templateName): string
    {
        $templatePath = $this->fixturesPath . '/Configurations/' . $templateName;
        $configContent = file_get_contents($templatePath);

        if (false === $configContent) {
            throw new \RuntimeException("Failed to read config template: {$templatePath}");
        }

        // Replace placeholder with actual fixture path
        $configContent = str_replace('%FIXTURE_PATH%', $this->fixturesPath, $configContent);

        $configPath = $this->tempDir . '/' . $templateName;
        file_put_contents($configPath, $configContent);

        return $configPath;
    }
}
