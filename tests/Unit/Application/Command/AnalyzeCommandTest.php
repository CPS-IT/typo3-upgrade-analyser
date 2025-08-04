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

use CPSIT\UpgradeAnalyzer\Application\Command\AnalyzeCommand;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test case for the AnalyzeCommand.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Application\Command\AnalyzeCommand
 */
class AnalyzeCommandTest extends TestCase
{
    private LoggerInterface $logger;
    private ExtensionDiscoveryServiceInterface $extensionDiscovery;
    private InstallationDiscoveryServiceInterface $installationDiscovery;
    private ConfigurationServiceInterface $configService;
    private ReportService $reportService;
    private AnalyzeCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->extensionDiscovery = $this->createMock(ExtensionDiscoveryServiceInterface::class);
        $this->installationDiscovery = $this->createMock(InstallationDiscoveryServiceInterface::class);
        $this->configService = $this->createMock(ConfigurationServiceInterface::class);
        $this->reportService = $this->createMock(ReportService::class);
        
        $this->command = new AnalyzeCommand(
            $this->logger,
            $this->extensionDiscovery,
            $this->installationDiscovery,
            $this->configService,
            $this->reportService
        );

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    public function testCommandConfiguration(): void
    {
        self::assertEquals('analyze', $this->command->getName());
        self::assertEquals('Analyze a TYPO3 installation for upgrade readiness', $this->command->getDescription());

        $definition = $this->command->getDefinition();
        
        self::assertTrue($definition->hasOption('config'));
        self::assertTrue($definition->hasOption('analyzers'));
    }

    public function testExecuteWithNonExistentConfigFile(): void
    {
        $this->commandTester->execute([
            '--config' => '/non/existent/config.yaml',
        ]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Configuration file does not exist', $this->commandTester->getDisplay());
    }

    public function testExecuteWithNoInstallationPath(): void
    {
        // Create a temporary config file without installation path
        $tempConfig = sys_get_temp_dir() . '/test-config-' . uniqid() . '.yaml';
        file_put_contents($tempConfig, "target_version: '12.4'\n");

        try {
            $this->commandTester->execute([
                '--config' => $tempConfig,
            ]);

            self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
            self::assertStringContainsString('No installation path specified in configuration file', $this->commandTester->getDisplay());
        } finally {
            unlink($tempConfig);
        }
    }

    public function testExecuteWithValidConfiguration(): void
    {
        // Create a temporary directory with TYPO3 indicators  
        $tempDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/typo3conf');
        touch($tempDir . '/typo3conf/LocalConfiguration.php');

        // Create a temporary config file
        $tempConfig = sys_get_temp_dir() . '/test-config-' . uniqid() . '.yaml';
        file_put_contents($tempConfig, "installation_path: '$tempDir'\ntarget_version: '12.4'\n");

        try {
            // Mock the configuration service methods
            $this->configService->expects(self::any())
                ->method('withConfigPath')
                ->willReturn($this->configService);
                
            $this->configService->expects(self::any())
                ->method('getInstallationPath')
                ->willReturn($tempDir);
            
            $this->configService->expects(self::any())
                ->method('getTargetVersion')
                ->willReturn('12.4');
                
            $this->configService->expects(self::any())
                ->method('get')
                ->willReturnMap([
                    ['reporting.output_directory', 'var/reports/', 'var/reports/'],
                    ['reporting.formats', ['markdown'], ['markdown']],
                ]);

            // Mock discovery services to return successful results
            $installationResult = \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects(self::once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            $extensionResult = \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryResult::success([], ['PackageStates.php']);
            $this->extensionDiscovery->expects(self::once())
                ->method('discoverExtensions')
                ->willReturn($extensionResult);

            $this->logger->expects(self::atLeastOnce())
                ->method('info');

            $this->commandTester->execute([
                '--config' => $tempConfig,
            ]);

            self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
            self::assertStringContainsString('Analysis completed successfully', $this->commandTester->getDisplay());
        } finally {
            unlink($tempConfig);
            unlink($tempDir . '/typo3conf/LocalConfiguration.php');
            rmdir($tempDir . '/typo3conf');
            rmdir($tempDir);
        }
    }

    public function testDefaultConfigOption(): void
    {
        $definition = $this->command->getDefinition();

        // Check default config path
        self::assertEquals('typo3-analyzer.yaml', $definition->getOption('config')->getDefault());
        
        // Check that analyzers option has no default (empty array)
        self::assertEquals([], $definition->getOption('analyzers')->getDefault());
    }
}
