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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'list-analyzers',
    description: 'List all registered analyzers',
)]
class ListAnalyzersCommand extends Command
{
    /**
     * @param iterable<AnalyzerInterface> $analyzers
     */
    public function __construct(
        private readonly iterable $analyzers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Registered Analyzers');

        $analyzerData = [];
        foreach ($this->analyzers as $analyzer) {
            // Skip test analyzers
            if ($this->isTestAnalyzer($analyzer)) {
                continue;
            }

            $status = $analyzer->hasRequiredTools() ? '✅' : '❌';
            $requiredTools = $analyzer->getRequiredTools();
            $description = $analyzer->getDescription();

            // Truncate description if too long
            if (\strlen($description) > 50) {
                $description = substr($description, 0, 47) . '...';
            }

            $analyzerData[] = [
                'Name' => $analyzer->getName(),
                'Description' => $description,
                'OK' => $status,
                'Tools' => empty($requiredTools) ? '-' : implode(', ', $requiredTools),
            ];
        }

        if (empty($analyzerData)) {
            $io->warning('No analyzers are currently registered.');

            return Command::FAILURE;
        }

        $io->table(['Name', 'Description', 'OK', 'Tools'], $analyzerData);

        $io->note([
            'Use the --analyzers option with the analyze command to run specific analyzers.',
            'Example: ./bin/typo3-analyzer analyze --analyzers=version_availability,lines_of_code',
        ]);

        return Command::SUCCESS;
    }

    private function isTestAnalyzer(AnalyzerInterface $analyzer): bool
    {
        $className = \get_class($analyzer);

        // Check if the class name contains "Test" (case-insensitive)
        return false !== stripos($className, 'test');
    }
}
