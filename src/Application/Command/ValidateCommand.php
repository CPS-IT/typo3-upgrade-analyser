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

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'validate',
    description: 'Validate a TYPO3 installation for analysis compatibility',
)]
class ValidateCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'path',
            InputArgument::REQUIRED,
            'Path to the TYPO3 installation',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $path = $input->getArgument('path');

        $io->title('TYPO3 Installation Validation');
        $io->section(\sprintf('Validating: %s', $path));

        $issues = [];
        $checks = [];

        // Check if path exists
        if (!is_dir($path)) {
            $issues[] = 'Directory does not exist';
            $checks[] = ['Path exists', '❌', 'Directory not found'];
        } else {
            $checks[] = ['Path exists', '✅', 'Directory found'];
        }

        // Check for TYPO3 indicators
        $typo3Indicators = [
            'typo3conf/LocalConfiguration.php' => 'LocalConfiguration found',
            'typo3/index.php' => 'TYPO3 backend entry point found',
            'vendor/typo3/cms-core' => 'TYPO3 Core found (Composer mode)',
            'composer.json' => 'Composer configuration found',
        ];

        $foundIndicators = 0;
        foreach ($typo3Indicators as $indicator => $description) {
            if (file_exists($path . '/' . $indicator)) {
                $checks[] = [$description, '✅', 'Found'];
                ++$foundIndicators;
            } else {
                $checks[] = [$description, '❌', 'Not found'];
            }
        }

        if (0 === $foundIndicators) {
            $issues[] = 'No TYPO3 installation indicators found';
        }

        // Check permissions
        if (is_dir($path) && !is_readable($path)) {
            $issues[] = 'Directory is not readable';
            $checks[] = ['Read permissions', '❌', 'Directory not readable'];
        } else {
            $checks[] = ['Read permissions', '✅', 'Directory is readable'];
        }

        // Check for database configuration
        $localConfigPath = $path . '/typo3conf/LocalConfiguration.php';
        if (file_exists($localConfigPath) && is_readable($localConfigPath)) {
            $checks[] = ['Database configuration', '✅', 'LocalConfiguration.php is readable'];
        } else {
            $checks[] = ['Database configuration', '⚠️', 'LocalConfiguration.php not accessible'];
        }

        // Display results
        $io->table(['Check', 'Status', 'Details'], $checks);

        if (empty($issues)) {
            $io->success('TYPO3 installation is valid and ready for analysis!');

            // Show detected version if possible
            $version = $this->detectTYPO3Version($path);
            if ($version) {
                $io->note(\sprintf('Detected TYPO3 version: %s', $version));
            }

            return Command::SUCCESS;
        } else {
            $io->error('Validation failed with the following issues:');
            $io->listing($issues);

            return Command::FAILURE;
        }
    }

    private function detectTYPO3Version(string $path): ?string
    {
        // Try to detect version from composer.json
        $composerPath = $path . '/composer.json';
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            if (isset($composer['require']['typo3/cms-core'])) {
                return $composer['require']['typo3/cms-core'];
            }
        }

        // Try to detect from TYPO3 constants
        $constantsFile = $path . '/typo3/sysext/core/Classes/Information/Typo3Version.php';
        if (file_exists($constantsFile)) {
            $content = file_get_contents($constantsFile);
            if (preg_match('/const VERSION = [\'"]([^\'"]*)[\'"]/m', $content, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
