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

use CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMetadata;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ListExtensionsCommandTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject $logger;
    private \PHPUnit\Framework\MockObject\MockObject $extensionDiscovery;
    private \PHPUnit\Framework\MockObject\MockObject $installationDiscovery;
    private \PHPUnit\Framework\MockObject\MockObject $configService;
    private ListExtensionsCommand $command;
    private CommandTester $commandTester;
    private string $tempConfigFile;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->extensionDiscovery = $this->createMock(ExtensionDiscoveryServiceInterface::class);
        $this->installationDiscovery = $this->createMock(InstallationDiscoveryServiceInterface::class);
        $this->configService = $this->createMock(ConfigurationServiceInterface::class);

        $this->command = new ListExtensionsCommand(
            $this->logger,
            $this->extensionDiscovery,
            $this->installationDiscovery,
            $this->configService,
        );

        $this->commandTester = new CommandTester($this->command);
        $this->tempConfigFile = tempnam(sys_get_temp_dir(), 'config_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempConfigFile)) {
            unlink($this->tempConfigFile);
        }

        // Clean up any temporary default config file created during tests
        $defaultConfigPath = ConfigurationService::DEFAULT_CONFIG_PATH;
        if (file_exists($defaultConfigPath)) {
            unlink($defaultConfigPath);
        }
    }

    private function createTestExtension(string $key, string $version = '1.0.0', string $type = 'local', bool $active = true): Extension
    {
        $extension = new Extension(
            $key,
            ucfirst($key) . ' Extension',
            Version::fromString($version),
            $type,
            'composer' === $type ? "vendor/$key" : null,
        );
        $extension->setActive($active);

        return $extension;
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::configure
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::getName
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::getDescription
     */
    public function testCommandConfiguration(): void
    {
        $this->assertSame('list-extensions', $this->command->getName());
        $this->assertSame('List extensions in a TYPO3 installation', $this->command->getDescription());

        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('config'));

        $configOption = $definition->getOption('config');
        $this->assertSame('c', $configOption->getShortcut());
        $this->assertTrue($configOption->isValueRequired());
        $this->assertSame('Path to custom configuration file', $configOption->getDescription());
        $this->assertSame(ConfigurationService::DEFAULT_CONFIG_PATH, $configOption->getDefault());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithNonExistentConfigFile(): void
    {
        $result = $this->commandTester->execute([
            '--config' => '/non/existent/config.yaml',
        ]);

        $this->assertSame(Command::FAILURE, $result);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Configuration file does not exist: /non/existent/config.yaml', $output);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithDefaultConfigPath(): void
    {
        // Create a temporary config file at the default path for this test
        $defaultConfigPath = ConfigurationService::DEFAULT_CONFIG_PATH;
        $configContent = "installation_path: /tmp/test\n";

        // Create the config file temporarily
        file_put_contents($defaultConfigPath, $configContent);

        // Create a real directory for the installation path
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            // Since we're using the default config path, withConfigPath should never be called
            $this->configService->expects($this->never())
                ->method('withConfigPath');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->with($tempDir)
                ->willReturn($installationResult);

            // Mock extension discovery
            $extensions = [$this->createTestExtension('news', '10.0.0')];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->with($tempDir, null)
                ->willReturn($extensionResult);

            $this->logger->expects($this->once())
                ->method('info')
                ->with('Extension list generated', $this->callback(function ($context): bool {
                    return isset($context['installation_path'])
                        && isset($context['discovery_result']);
                }));

            $result = $this->commandTester->execute([]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('TYPO3 Extensions', $output);
            $this->assertStringContainsString('Installation: ' . $tempDir, $output);
            $this->assertStringContainsString('news', $output);
            $this->assertStringContainsString('10.0.0', $output);
        } finally {
            // Clean up
            if (file_exists($defaultConfigPath)) {
                unlink($defaultConfigPath);
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithCustomConfigPath(): void
    {
        $customConfigFile = tempnam(sys_get_temp_dir(), 'custom_config_');
        file_put_contents($customConfigFile, '');

        // Create a real directory for the installation path
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);

            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($customConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->with($tempDir)
                ->willReturn($installationResult);

            // Mock extension discovery
            $extensionResult = ExtensionDiscoveryResult::success([], []);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->with($tempDir, null)
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $customConfigFile,
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Installation: ' . $tempDir, $output);
        } finally {
            unlink($customConfigFile);
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithMissingInstallationPath(): void
    {
        file_put_contents($this->tempConfigFile, '');

        $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
        $this->configService->expects($this->once())
            ->method('withConfigPath')
            ->with($this->tempConfigFile)
            ->willReturn($customConfigService);

        $customConfigService->expects($this->once())
            ->method('getInstallationPath')
            ->willReturn(null);

        $result = $this->commandTester->execute([
            '--config' => $this->tempConfigFile,
        ]);

        $this->assertSame(Command::FAILURE, $result);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No installation path specified in configuration file', $output);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithNonExistentInstallationPath(): void
    {
        file_put_contents($this->tempConfigFile, '');

        $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
        $this->configService->expects($this->once())
            ->method('withConfigPath')
            ->with($this->tempConfigFile)
            ->willReturn($customConfigService);

        $customConfigService->expects($this->once())
            ->method('getInstallationPath')
            ->willReturn('/non/existent/path');

        $result = $this->commandTester->execute([
            '--config' => $this->tempConfigFile,
        ]);

        $this->assertSame(Command::FAILURE, $result);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Installation path does not exist: /non/existent/path', $output);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithSuccessfulInstallationDiscovery(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($this->tempConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock successful installation discovery
            $customPaths = ['vendor-dir' => 'custom-vendor', 'web-dir' => 'web'];
            $metadata = new InstallationMetadata(
                ['required' => '8.1', 'current' => '8.2'],
                ['driver' => 'mysql', 'host' => 'localhost'],
                ['feature1', 'feature2'],
                new \DateTimeImmutable(),
                $customPaths,
            );
            $installation = new Installation($tempDir, Version::fromString('12.4.0'), 'composer');
            $installation->setMetadata($metadata);
            $mockStrategy = $this->createMock(\CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DetectionStrategyInterface::class);
            $installationResult = InstallationDiscoveryResult::success($installation, $mockStrategy);

            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->with($tempDir)
                ->willReturn($installationResult);

            // Mock extension discovery with custom paths
            $extensions = [$this->createTestExtension('news', '10.0.0')];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->with($tempDir, $customPaths)
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile,
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Installation discovered: TYPO3 12.4.0', $output);
        } finally {
            rmdir($tempDir);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithInstallationDiscoveryWarning(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($this->tempConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock failed installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation files not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->with($tempDir)
                ->willReturn($installationResult);

            // Mock extension discovery with null custom paths
            $extensions = [$this->createTestExtension('news', '10.0.0')];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->with($tempDir, null)
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile,
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Installation discovery failed: Installation files not found', $output);
            $this->assertStringContainsString('Proceeding with extension discovery using default paths...', $output);
        } finally {
            rmdir($tempDir);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithFailedExtensionDiscovery(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($this->tempConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            // Mock failed extension discovery
            $extensionResult = ExtensionDiscoveryResult::failed('Failed to read PackageStates.php');
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile,
            ]);

            $this->assertSame(Command::FAILURE, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Extension discovery failed: Failed to read PackageStates.php', $output);
        } finally {
            rmdir($tempDir);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithNoExtensionsFound(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($this->tempConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            // Mock extension discovery with no extensions
            $extensionResult = ExtensionDiscoveryResult::success([], []);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile,
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('No extensions found in the installation', $output);
        } finally {
            rmdir($tempDir);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithMultipleExtensions(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($this->tempConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            // Mock extension discovery with multiple extensions
            $extensions = [
                $this->createTestExtension('news', '10.0.0', 'composer', true),
                $this->createTestExtension('tt_address', '7.1.0', 'composer', false),
                $this->createTestExtension('local_ext', '1.0.0', 'local', true),
            ];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php', 'composer installed.json']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile,
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();

            // Check table headers
            $this->assertStringContainsString('Extension', $output);
            $this->assertStringContainsString('Version', $output);
            $this->assertStringContainsString('Type', $output);
            $this->assertStringContainsString('Active', $output);

            // Check extension data
            $this->assertStringContainsString('news', $output);
            $this->assertStringContainsString('10.0.0', $output);
            $this->assertStringContainsString('tt_address', $output);
            $this->assertStringContainsString('7.1.0', $output);
            $this->assertStringContainsString('local_ext', $output);
            $this->assertStringContainsString('1.0.0', $output);

            // Check summary
            $this->assertStringContainsString('Found 3 extensions (2 active, 1 inactive)', $output);
        } finally {
            rmdir($tempDir);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithException(): void
    {
        file_put_contents($this->tempConfigFile, '');

        $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
        $this->configService->expects($this->once())
            ->method('withConfigPath')
            ->with($this->tempConfigFile)
            ->willReturn($customConfigService);

        $customConfigService->expects($this->once())
            ->method('getInstallationPath')
            ->willThrowException(new \RuntimeException('Configuration error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('List extensions failed', $this->callback(function ($context): bool {
                return isset($context['exception']) && $context['exception'] instanceof \RuntimeException;
            }));

        $result = $this->commandTester->execute([
            '--config' => $this->tempConfigFile,
        ]);

        $this->assertSame(Command::FAILURE, $result);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Failed to process configuration: Configuration error', $output);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteDisplaysDiscoverySummary(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($this->tempConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            // Mock extension discovery with summary
            $extensions = [$this->createTestExtension('news', '10.0.0')];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile,
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();

            // Should display the discovery summary
            $summary = $extensionResult->getSummary();
            $this->assertStringContainsString($summary, $output);
        } finally {
            rmdir($tempDir);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteLogsSuccessfulCompletion(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($this->tempConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            // Mock extension discovery
            $extensions = [$this->createTestExtension('news', '10.0.0')];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->willReturn($extensionResult);

            $this->logger->expects($this->once())
                ->method('info')
                ->with('Extension list generated', [
                    'installation_path' => $tempDir,
                    'discovery_result' => $extensionResult->getStatistics(),
                ]);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile,
            ]);

            $this->assertSame(Command::SUCCESS, $result);
        } finally {
            rmdir($tempDir);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteHandlesInstallationWithoutMetadata(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($this->tempConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock installation discovery with installation but no metadata
            $installation = new Installation($tempDir, Version::fromString('12.4.0'), 'composer');
            $mockStrategy = $this->createMock(\CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DetectionStrategyInterface::class);
            $installationResult = InstallationDiscoveryResult::success($installation, $mockStrategy);

            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            // Mock extension discovery with null custom paths
            $extensions = [$this->createTestExtension('news', '10.0.0')];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->with($tempDir, null)
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile,
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Installation discovered: TYPO3 12.4.0', $output);
        } finally {
            rmdir($tempDir);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testExecuteWithUnknownVersionInstallation(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($this->tempConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock installation discovery with unknown version - create minimal installation
            $installation = new Installation($tempDir, Version::fromString('0.0.0'), 'unknown');
            $mockStrategy = $this->createMock(\CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DetectionStrategyInterface::class);
            $installationResult = InstallationDiscoveryResult::success($installation, $mockStrategy);

            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            // Mock extension discovery
            $extensions = [$this->createTestExtension('news', '10.0.0')];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->with($tempDir, null)
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile,
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Installation discovered: TYPO3 0.0.0', $output);
        } finally {
            rmdir($tempDir);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand::execute
     */
    public function testTableFormatting(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $customConfigService = $this->createMock(ConfigurationServiceInterface::class);
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($this->tempConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            // Mock extension discovery with extension having different versions
            $extensions = [
                $this->createTestExtension('extension_with_long_name', '10.5.2', 'composer', true),
                $this->createTestExtension('ext', '1.0.0-dev', 'local', false),
            ];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile,
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();

            // Check that all extensions are displayed in table format
            $this->assertStringContainsString('extension_with_long_name', $output);
            $this->assertStringContainsString('10.5.2', $output);
            $this->assertStringContainsString('ext', $output);
            $this->assertStringContainsString('1.0.0-dev', $output);
            $this->assertStringContainsString('YES', $output); // Active status
            $this->assertStringContainsString('NO', $output); // Inactive status
        } finally {
            rmdir($tempDir);
        }
    }
}
