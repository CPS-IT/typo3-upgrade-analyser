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

use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cache-clear',
    description: 'Clear all cached analysis results',
)]
class ClearCacheCommand extends Command
{
    public function __construct(
        private readonly CacheService $cacheService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $success = $this->cacheService->clear();

        if ($success) {
            $io->success('Cache cleared.');

            return Command::SUCCESS;
        }

        $io->error('Failed to clear cache. Check the log for details.');

        return Command::FAILURE;
    }
}
