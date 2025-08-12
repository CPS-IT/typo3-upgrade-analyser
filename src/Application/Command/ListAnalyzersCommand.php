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

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'list-analyzers',
    description: 'List all available analyzers',
)]
class ListAnalyzersCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Available Analyzers');

        $analyzers = [
            [
                'Name' => 'Version Availability',
                'Description' => 'Checks if compatible versions exist in TER, Packagist, or Git',
                'Status' => 'Available',
            ],
            [
                'Name' => 'Static Analysis',
                'Description' => 'Runs PHPStan and other static analysis tools',
                'Status' => 'Available',
            ],
            [
                'Name' => 'Lines of Code',
                'Description' => 'Counts lines of code and calculates complexity metrics',
                'Status' => 'Available',
            ],
            [
                'Name' => 'PHP Compatibility',
                'Description' => 'Checks PHP version compatibility',
                'Status' => 'Available',
            ],
            [
                'Name' => 'Deprecation Scanner',
                'Description' => 'Scans for deprecated TYPO3 API usage',
                'Status' => 'Available',
            ],
            [
                'Name' => 'TCA Migration',
                'Description' => 'Checks for required TCA migrations',
                'Status' => 'Available',
            ],
            [
                'Name' => 'Rector Analysis',
                'Description' => 'Checks for available Rector migration rules',
                'Status' => 'Planned',
            ],
            [
                'Name' => 'Fractor Analysis',
                'Description' => 'Analyzes TypoScript for modernization opportunities',
                'Status' => 'Planned',
            ],
            [
                'Name' => 'TypoScript Lint',
                'Description' => 'Validates TypoScript configuration',
                'Status' => 'Planned',
            ],
            [
                'Name' => 'Test Coverage',
                'Description' => 'Analyzes existing test coverage',
                'Status' => 'Planned',
            ],
        ];

        $io->table(['Name', 'Description', 'Status'], $analyzers);

        $io->note('Use the --analyzers option with the analyze command to run specific analyzers.');

        return Command::SUCCESS;
    }
}
