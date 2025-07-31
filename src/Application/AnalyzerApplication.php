<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Application;

use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use CPSIT\UpgradeAnalyzer\Application\Command\AnalyzeCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\ListAnalyzersCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\ValidateCommand;
use CPSIT\UpgradeAnalyzer\Shared\Configuration\ContainerFactory;

/**
 * Main console application for the TYPO3 Upgrade Analyzer
 */
class AnalyzerApplication extends Application
{
    private ContainerInterface $container;

    public function __construct()
    {
        parent::__construct('TYPO3 Upgrade Analyzer', '1.0.0');
        
        $this->container = ContainerFactory::create();
        $this->addCommands([
            $this->container->get(AnalyzeCommand::class),
            $this->container->get(InitConfigCommand::class),
            $this->container->get(ListAnalyzersCommand::class),
            $this->container->get(ValidateCommand::class),
        ]);
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }
}