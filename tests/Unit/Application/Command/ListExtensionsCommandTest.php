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
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class ListExtensionsCommandTest extends TestCase
{
    private LoggerInterface $logger;
    private ExtensionDiscoveryService $extensionDiscovery;
    private InstallationDiscoveryService $installationDiscovery;
    private ConfigurationService $configService;
    private ListExtensionsCommand $command;
    private CommandTester $commandTester;
    private string $tempConfigFile;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->extensionDiscovery = $this->createMock(ExtensionDiscoveryService::class);
        $this->installationDiscovery = $this->createMock(InstallationDiscoveryService::class);
        $this->configService = $this->createMock(ConfigurationService::class);

        $this->command = new ListExtensionsCommand(
            $this->logger,
            $this->extensionDiscovery,
            $this->installationDiscovery,
            $this->configService
        );

        $this->commandTester = new CommandTester($this->command);
        $this->tempConfigFile = tempnam(sys_get_temp_dir(), 'config_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempConfigFile)) {
            unlink($this->tempConfigFile);
        }
    }

    private function createTestExtension(string $key, string $version = '1.0.0', string $type = 'local', bool $active = true): Extension
    {
        $extension = new Extension(
            $key,
            ucfirst($key) . ' Extension',
            Version::fromString($version),
            $type,
            $type === 'composer' ? "vendor/$key" : null
        );
        $extension->setActive($active);
        return $extension;
    }

    public function testCommandConfiguration(): void
    {
        $this->assertSame('list-extensions', $this->command->getName());
        $this->assertSame('List extensions in a TYPO3 installation with target version compatibility', $this->command->getDescription());
        
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('config'));
        
        $configOption = $definition->getOption('config');
        $this->assertSame('c', $configOption->getShortcut());
        $this->assertTrue($configOption->isValueRequired());
        $this->assertSame('Path to custom configuration file', $configOption->getDescription());
        $this->assertSame(ConfigurationService::DEFAULT_CONFIG_PATH, $configOption->getDefault());
    }

    public function testExecuteWithNonExistentConfigFile(): void
    {
        $result = $this->commandTester->execute([
            '--config' => '/non/existent/config.yaml'
        ]);

        $this->assertSame(Command::FAILURE, $result);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Configuration file does not exist: /non/existent/config.yaml', $output);
    }

    public function testExecuteWithDefaultConfigPath(): void
    {
        file_put_contents($this->tempConfigFile, '');

        $this->configService->expects($this->once())
            ->method('getInstallationPath')
            ->willReturn('/path/to/typo3');

        $this->configService->expects($this->once())
            ->method('getTargetVersion')
            ->willReturn('13.4');

        // Mock installation discovery
        $installationResult = InstallationDiscoveryResult::failed('Installation not found');
        $this->installationDiscovery->expects($this->once())
            ->method('discoverInstallation')
            ->with('/path/to/typo3')
            ->willReturn($installationResult);

        // Mock extension discovery
        $extensions = [$this->createTestExtension('news', '10.0.0')];
        $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php']);
        $this->extensionDiscovery->expects($this->once())
            ->method('discoverExtensions')
            ->with('/path/to/typo3', null)
            ->willReturn($extensionResult);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Extension list generated', $this->callback(function ($context) {
                return isset($context['installation_path'])
                    && isset($context['target_version'])
                    && isset($context['discovery_result']);
            }));

        $result = $this->commandTester->execute([
            '--config' => $this->tempConfigFile
        ]);

        $this->assertSame(Command::SUCCESS, $result);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('TYPO3 Extension List', $output);
        $this->assertStringContainsString('Installation: /path/to/typo3', $output);
        $this->assertStringContainsString('Target TYPO3 version: 13.4', $output);
        $this->assertStringContainsString('news', $output);
        $this->assertStringContainsString('10.0.0', $output);
    }

    public function testExecuteWithCustomConfigPath(): void
    {
        $customConfigFile = tempnam(sys_get_temp_dir(), 'custom_config_');
        file_put_contents($customConfigFile, '');

        try {
            $customConfigService = $this->createMock(ConfigurationService::class);
            
            $this->configService->expects($this->once())
                ->method('withConfigPath')
                ->with($customConfigFile)
                ->willReturn($customConfigService);

            $customConfigService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn('/custom/path/to/typo3');

            $customConfigService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('12.4');

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->with('/custom/path/to/typo3')
                ->willReturn($installationResult);

            // Mock extension discovery
            $extensionResult = ExtensionDiscoveryResult::success([], []);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->with('/custom/path/to/typo3', null)
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $customConfigFile
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Installation: /custom/path/to/typo3', $output);
            $this->assertStringContainsString('Target TYPO3 version: 12.4', $output);

        } finally {
            unlink($customConfigFile);
        }
    }

    public function testExecuteWithMissingInstallationPath(): void
    {
        file_put_contents($this->tempConfigFile, '');

        $this->configService->expects($this->once())
            ->method('getInstallationPath')
            ->willReturn(null);

        $result = $this->commandTester->execute([
            '--config' => $this->tempConfigFile
        ]);

        $this->assertSame(Command::FAILURE, $result);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No installation path specified in configuration file', $output);
    }

    public function testExecuteWithNonExistentInstallationPath(): void
    {
        file_put_contents($this->tempConfigFile, '');

        $this->configService->expects($this->once())
            ->method('getInstallationPath')
            ->willReturn('/non/existent/path');

        $this->configService->expects($this->once())
            ->method('getTargetVersion')
            ->willReturn('13.4');

        $result = $this->commandTester->execute([
            '--config' => $this->tempConfigFile
        ]);

        $this->assertSame(Command::FAILURE, $result);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Installation path does not exist: /non/existent/path', $output);
    }

    public function testExecuteWithSuccessfulInstallationDiscovery(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            $this->configService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('13.4');

            // Mock successful installation discovery
            $customPaths = ['vendor-dir' => 'custom-vendor', 'web-dir' => 'web'];
            $metadata = new InstallationMetadata('composer', $customPaths);
            $installation = new Installation($tempDir, Version::fromString('12.4.0'), $metadata);
            $installationResult = InstallationDiscoveryResult::success($installation);

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
                '--config' => $this->tempConfigFile
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Installation discovered: TYPO3 12.4.0', $output);

        } finally {
            rmdir($tempDir);
        }
    }

    public function testExecuteWithInstallationDiscoveryWarning(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            $this->configService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('13.4');

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
                '--config' => $this->tempConfigFile
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Installation discovery failed: Installation files not found', $output);
            $this->assertStringContainsString('Proceeding with extension discovery using default paths...', $output);

        } finally {
            rmdir($tempDir);
        }
    }

    public function testExecuteWithFailedExtensionDiscovery(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            $this->configService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('13.4');

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
                '--config' => $this->tempConfigFile
            ]);

            $this->assertSame(Command::FAILURE, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Extension discovery failed: Failed to read PackageStates.php', $output);

        } finally {
            rmdir($tempDir);
        }
    }

    public function testExecuteWithNoExtensionsFound(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            $this->configService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('13.4');

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
                '--config' => $this->tempConfigFile
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('No extensions found in the installation', $output);

        } finally {
            rmdir($tempDir);
        }
    }

    public function testExecuteWithMultipleExtensions(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            $this->configService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('13.4');

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            // Mock extension discovery with multiple extensions
            $extensions = [
                $this->createTestExtension('news', '10.0.0', 'composer', true),
                $this->createTestExtension('tt_address', '7.1.0', 'composer', false),
                $this->createTestExtension('local_ext', '1.0.0', 'local', true)
            ];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php', 'composer installed.json']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            
            // Check table headers
            $this->assertStringContainsString('Extension', $output);
            $this->assertStringContainsString('Current Version', $output);
            $this->assertStringContainsString('Target Available', $output);
            
            // Check extension data
            $this->assertStringContainsString('news', $output);
            $this->assertStringContainsString('10.0.0', $output);
            $this->assertStringContainsString('tt_address', $output);
            $this->assertStringContainsString('7.1.0', $output);
            $this->assertStringContainsString('local_ext', $output);
            $this->assertStringContainsString('1.0.0', $output);
            
            // Check summary
            $this->assertStringContainsString('Summary: 0 compatible, 0 incompatible, 3 unknown', $output);

        } finally {
            rmdir($tempDir);
        }
    }

    public function testExecuteWithException(): void
    {
        file_put_contents($this->tempConfigFile, '');

        $this->configService->expects($this->once())
            ->method('getInstallationPath')
            ->willThrowException(new \RuntimeException('Configuration error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('List extensions failed', $this->callback(function ($context) {
                return isset($context['exception']) && $context['exception'] instanceof \RuntimeException;
            }));

        $result = $this->commandTester->execute([
            '--config' => $this->tempConfigFile
        ]);

        $this->assertSame(Command::FAILURE, $result);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Failed to process configuration: Configuration error', $output);
    }

    public function testExecuteDisplaysDiscoverySummary(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            $this->configService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('13.4');

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
                '--config' => $this->tempConfigFile
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

    public function testExecuteLogsSuccessfulCompletion(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            $this->configService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('13.4');

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
                    'target_version' => '13.4',
                    'discovery_result' => $extensionResult->getStatistics()
                ]);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile
            ]);

            $this->assertSame(Command::SUCCESS, $result);

        } finally {
            rmdir($tempDir);
        }
    }

    public function testExecuteHandlesInstallationWithoutMetadata(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            $this->configService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('13.4');

            // Mock installation discovery with installation but no metadata
            $installation = new Installation($tempDir, Version::fromString('12.4.0'), null);
            $installationResult = InstallationDiscoveryResult::success($installation);

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
                '--config' => $this->tempConfigFile
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Installation discovered: TYPO3 12.4.0', $output);

        } finally {
            rmdir($tempDir);
        }
    }

    public function testExecuteWithUnknownVersionInstallation(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            $this->configService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('13.4');

            // Mock installation discovery with null installation
            $installationResult = InstallationDiscoveryResult::success(null);

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
                '--config' => $this->tempConfigFile
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            $this->assertStringContainsString('Installation discovered: TYPO3 unknown', $output);

        } finally {
            rmdir($tempDir);
        }
    }

    public function testTableFormatting(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            file_put_contents($this->tempConfigFile, '');

            $this->configService->expects($this->once())
                ->method('getInstallationPath')
                ->willReturn($tempDir);

            $this->configService->expects($this->once())
                ->method('getTargetVersion')
                ->willReturn('13.4');

            // Mock installation discovery
            $installationResult = InstallationDiscoveryResult::failed('Installation not found');
            $this->installationDiscovery->expects($this->once())
                ->method('discoverInstallation')
                ->willReturn($installationResult);

            // Mock extension discovery with extension having different versions
            $extensions = [
                $this->createTestExtension('extension_with_long_name', '10.5.2', 'composer', true),
                $this->createTestExtension('ext', '1.0.0-dev', 'local', false)
            ];
            $extensionResult = ExtensionDiscoveryResult::success($extensions, ['PackageStates.php']);
            $this->extensionDiscovery->expects($this->once())
                ->method('discoverExtensions')
                ->willReturn($extensionResult);

            $result = $this->commandTester->execute([
                '--config' => $this->tempConfigFile
            ]);

            $this->assertSame(Command::SUCCESS, $result);
            $output = $this->commandTester->getDisplay();
            
            // Check that all extensions are displayed in table format
            $this->assertStringContainsString('extension_with_long_name', $output);
            $this->assertStringContainsString('10.5.2', $output);
            $this->assertStringContainsString('ext', $output);
            $this->assertStringContainsString('1.0.0-dev', $output);
            $this->assertStringContainsString('UNKNOWN', $output); // Target compatibility status

        } finally {
            rmdir($tempDir);
        }
    }
}