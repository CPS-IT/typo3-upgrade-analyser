<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Application\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'analyze',
    description: 'Analyze a TYPO3 installation for upgrade readiness'
)]
class AnalyzeCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'Path to the TYPO3 installation'
            )
            ->addOption(
                'target-version',
                't',
                InputOption::VALUE_REQUIRED,
                'Target TYPO3 version to analyze for',
                '12.4'
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to custom configuration file'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Output format(s) [html, json, csv, markdown]',
                ['html', 'json']
            )
            ->addOption(
                'output-dir',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for reports',
                'tests/upgradeAnalysis'
            )
            ->addOption(
                'analyzers',
                'a',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Specific analyzers to run (run all if not specified)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Perform a dry run without generating reports'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $path = $input->getArgument('path');
        $targetVersion = $input->getOption('target-version');
        
        $io->title('TYPO3 Upgrade Analyzer');
        $io->section(sprintf('Analyzing TYPO3 installation at: %s', $path));
        $io->note(sprintf('Target TYPO3 version: %s', $targetVersion));
        
        // Validate path exists
        if (!is_dir($path)) {
            $io->error(sprintf('Directory does not exist: %s', $path));
            return Command::FAILURE;
        }
        
        // Validate path is a TYPO3 installation
        if (!$this->isValidTYPO3Installation($path)) {
            $io->error('The specified path does not appear to be a valid TYPO3 installation.');
            return Command::FAILURE;
        }
        
        $this->logger->info('Starting TYPO3 upgrade analysis', [
            'path' => $path,
            'target_version' => $targetVersion,
        ]);
        
        try {
            // TODO: Implement actual analysis logic in next phase
            $io->progressStart(3);
            
            // Phase 1: Discovery
            $io->text('Phase 1: Discovering installation and extensions...');
            $io->progressAdvance();
            sleep(1); // Simulate work
            
            // Phase 2: Analysis
            $io->text('Phase 2: Running analyzers...');
            $io->progressAdvance();
            sleep(2); // Simulate work
            
            // Phase 3: Reporting
            $io->text('Phase 3: Generating reports...');
            $io->progressAdvance();
            sleep(1); // Simulate work
            
            $io->progressFinish();
            
            $io->success('Analysis completed successfully!');
            $io->note(sprintf('Reports generated in: %s', $input->getOption('output-dir')));
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error(sprintf('Analysis failed: %s', $e->getMessage()));
            $this->logger->error('Analysis failed', ['exception' => $e]);
            return Command::FAILURE;
        }
    }
    
    private function isValidTYPO3Installation(string $path): bool
    {
        // Check for common TYPO3 indicators
        $indicators = [
            'typo3conf/LocalConfiguration.php',
            'typo3/index.php',
            'vendor/typo3/cms-core',
            'composer.json'
        ];
        
        foreach ($indicators as $indicator) {
            if (file_exists($path . '/' . $indicator)) {
                return true;
            }
        }
        
        return false;
    }
}