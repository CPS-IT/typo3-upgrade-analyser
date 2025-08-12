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

        // Check if file exists and ask for confirmation before overwriting
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

        return Command::SUCCESS;
    }

    private function runInteractiveConfiguration(SymfonyStyle $io, array $config): array
    {
        // Target version
        $targetVersion = $io->ask(
            'Target TYPO3 version',
            $config['analysis']['target_version'],
        );
        $config['analysis']['target_version'] = $targetVersion;

        // PHP versions
        $phpVersions = $io->ask(
            'Supported PHP versions (comma-separated)',
            implode(', ', $config['analysis']['php_versions']),
        );
        $config['analysis']['php_versions'] = array_map('trim', explode(',', $phpVersions));

        // Output directory
        $outputDir = $io->ask(
            'Output directory for reports',
            $config['reporting']['output_directory'],
        );
        $config['reporting']['output_directory'] = $outputDir;

        // Report formats
        $formats = $io->choice(
            'Report formats (multiple selection allowed)',
            ['html', 'json', 'csv', 'markdown'],
            $config['reporting']['formats'][0] ?? 'html',
        );
        $config['reporting']['formats'] = \is_array($formats) ? $formats : [$formats];

        return $config;
    }
}
