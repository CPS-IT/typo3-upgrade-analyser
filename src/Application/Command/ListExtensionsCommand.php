<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Application\Command;

use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'list-extensions',
    description: 'List extensions in a TYPO3 installation',
)]
class ListExtensionsCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ExtensionDiscoveryServiceInterface $extensionDiscovery,
        private readonly InstallationDiscoveryServiceInterface $installationDiscovery,
        private readonly ConfigurationServiceInterface $configService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to custom configuration file',
                ConfigurationService::DEFAULT_CONFIG_PATH,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configPath = $input->getOption('config');

        // Validate config file exists
        if (!file_exists($configPath)) {
            $io->error(\sprintf('Configuration file does not exist: %s', $configPath));

            return Command::FAILURE;
        }

        try {
            // Use ConfigurationService with custom config path if provided
            $configService = ConfigurationService::DEFAULT_CONFIG_PATH !== $configPath
                ? $this->configService->withConfigPath($configPath)
                : $this->configService;

            // Use ConfigurationService to get settings
            $installationPath = $configService->getInstallationPath();

            if (!$installationPath) {
                $io->error('No installation path specified in configuration file');

                return Command::FAILURE;
            }

            $io->title('TYPO3 Extensions');
            $io->section(\sprintf('Installation: %s', $installationPath));

            // Validate installation path exists
            if (!is_dir($installationPath)) {
                $io->error(\sprintf('Installation path does not exist: %s', $installationPath));

                return Command::FAILURE;
            }

            // First, discover the installation to get custom paths
            $io->text('Discovering TYPO3 installation...');
            $installationResult = $this->installationDiscovery->discoverInstallation($installationPath);

            if (!$installationResult->isSuccessful()) {
                $io->warning(\sprintf('Installation discovery failed: %s', $installationResult->getErrorMessage()));
                $io->text('Proceeding with extension discovery using default paths...');

                // Check if this is a legacy installation and provide appropriate custom paths
                $customPaths = $this->detectLegacyInstallationPaths($installationPath);
            } else {
                $installation = $installationResult->getInstallation();
                $customPaths = $installation?->getMetadata()?->getCustomPaths();
                $io->text(\sprintf('Installation discovered: TYPO3 %s', $installation?->getVersion()->toString() ?? 'unknown'));
            }

            // Discover extensions using installation metadata
            $io->text('Discovering extensions...');
            $extensionsToSkip = $configService->getExtensionsToSkip();
            $discoveryResult = $this->extensionDiscovery->discoverExtensions($installationPath, $customPaths, $extensionsToSkip);

            if (!$discoveryResult->isSuccessful()) {
                $io->error(\sprintf('Extension discovery failed: %s', $discoveryResult->getErrorMessage()));

                return Command::FAILURE;
            }

            if (!$discoveryResult->hasExtensions()) {
                $io->warning('No extensions found in the installation');

                return Command::SUCCESS;
            }

            $extensions = $discoveryResult->getExtensions();

            // Display discovery summary
            $io->text($discoveryResult->getSummary());

            // Display table
            $io->table(
                ['Extension', 'Version', 'Type', 'Active'],
                array_map(fn ($ext): array => [
                    $ext->getKey(),
                    $ext->getVersion()->toString(),
                    $ext->getType(),
                    $ext->isActive() ? 'YES' : 'NO',
                ], $extensions),
            );

            // Summary
            $activeCount = array_reduce($extensions, fn ($count, $ext): float|int => $count + ($ext->isActive() ? 1 : 0), 0);
            $inactiveCount = \count($extensions) - $activeCount;

            $io->writeln('');
            $io->writeln(\sprintf(
                'Found %d extensions (%d active, %d inactive)',
                \count($extensions),
                $activeCount,
                $inactiveCount,
            ));

            $this->logger->info('Extension list generated', [
                'installation_path' => $installationPath,
                'discovery_result' => $discoveryResult->getStatistics(),
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(\sprintf('Failed to process configuration: %s', $e->getMessage()));
            $this->logger->error('List extensions failed', ['exception' => $e]);

            return Command::FAILURE;
        }
    }

    /**
     * Detect if this is a legacy installation and return appropriate custom paths.
     */
    private function detectLegacyInstallationPaths(string $installationPath): ?array
    {
        // Check for legacy TYPO3 installation structure (typo3conf directly in root)
        $legacyPackageStatesPath = $installationPath . '/typo3conf/PackageStates.php';
        $legacyTypo3ConfPath = $installationPath . '/typo3conf';

        if (file_exists($legacyPackageStatesPath) && is_dir($legacyTypo3ConfPath)) {
            return [
                'web-dir' => '.',
                'typo3conf-dir' => 'typo3conf',
            ];
        }

        return null;
    }
}
