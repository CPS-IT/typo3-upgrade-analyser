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
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationServiceInterface as CSI;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'init-config',
    description: 'Generate a configuration file for analysis',
)]
class InitConfigCommand extends Command
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'interactive',
                'i',
                InputOption::VALUE_NONE,
                'Run in interactive mode',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file path',
                'typo3-analyzer.yaml',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('TYPO3 Upgrade Analyzer - Configuration Generator');

        $config = $this->configurationService->getDefaultConfiguration();

        if ($input->getOption('interactive')) {
            $config = $this->runInteractiveConfiguration($io, $config);
        }

        $outputFile = $input->getOption('output');

        // Check if the file exists and ask for confirmation before overwriting
        if (file_exists($outputFile)) {
            if (!$io->confirm(\sprintf('Configuration file "%s" already exists. Overwrite?', $outputFile), false)) {
                $io->note('Configuration generation cancelled.');

                return Command::SUCCESS;
            }
        }

        $yamlContent = Yaml::dump($config, 4, 2);

        if (false === file_put_contents($outputFile, $yamlContent)) {
            $io->error(\sprintf('Failed to write configuration to: %s', $outputFile));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Configuration file generated: %s', $outputFile));
        if (empty($config[CSI::KEY_ANALYSIS][CSI::KEY_INSTALLATION_PATH])) {
            $io->note('Make sure the analysis.installationPath is set to the root of your TYPO3 installation.');
        }
        $io->note('Edit the configuration file to customize the analysis.');
        $io->text('You can now run the analyze command with the --config option.');

        return Command::SUCCESS;
    }

    private function runInteractiveConfiguration(SymfonyStyle $io, array $config): array
    {
        // Installation path
        $installationPath = $io->ask(
            'Path to TYPO3 installation (relative or absolute, e.g. /var/www/html/typo3)',
        );
        $config[CSI::KEY_ANALYSIS][CSI::KEY_INSTALLATION_PATH] = $installationPath;
        // Target version
        $targetVersion = $io->ask(
            'Target TYPO3 version',
            $this->configurationService->getTargetVersion(),
        );
        $config[CSI::KEY_ANALYSIS][CSI::KEY_TARGET_VERSION] = $targetVersion;

        // PHP versions
        $phpVersions = $io->ask(
            'Supported PHP versions (comma-separated)',
            implode(', ', $this->configurationService->get(CSI::CONFIG_ANALYSIS_PHP_VERSIONS)),
        );
        $config[CSI::KEY_ANALYSIS][CSI::KEY_PHP_VERSIONS] = array_map('trim', explode(',', $phpVersions));

        // Output directory
        $outputDir = $io->ask(
            'Output directory for reports',
            $this->configurationService->get(CSI::CONFIG_REPORTING_OUTPUT_DIRECTORY),
        );
        $config[CSI::KEY_REPORTING][CSI::KEY_OUTPUT_DIRECTORY] = $outputDir;

        // Report formats
        $formats = $io->choice(
            question: 'Report formats (multiple selection allowed)',
            choices: ConfigurationService::AVAILABLE_REPORT_FORMATS,
            default: 'html',
            /*
            $this->configurationService->get(
                CSI::CONFIG_REPORTING_FORMATS,
                ConfigurationService::DEFAULT_REPORT_FORMATS,
            ),
            */
            multiSelect: true,
        );
        $config[CSI::KEY_REPORTING][CSI::KEY_FORMATS] = \is_array($formats) ? $formats : [$formats];

        return $config;
    }
}
