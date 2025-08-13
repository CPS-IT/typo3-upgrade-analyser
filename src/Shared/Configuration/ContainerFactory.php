<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Shared\Configuration;

use CPSIT\UpgradeAnalyzer\Shared\Utility\ProjectRootResolver;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Factory for creating and configuring the dependency injection container.
 */
class ContainerFactory
{
    public static function create(): ContainerInterface
    {
        // Load environment variables from .env files
        EnvironmentLoader::load();

        $container = new ContainerBuilder();

        // Register core services
        self::registerCoreServices($container);

        // Load service definitions from configuration
        self::loadServiceDefinitions($container);

        // Auto-register analyzers (disabled - using manual service configuration)
        // self::autoRegisterAnalyzers($container);

        $container->compile();

        return $container;
    }

    private static function registerCoreServices(ContainerBuilder $container): void
    {
        // Logger - register single instance for both interfaces
        $projectRoot = ProjectRootResolver::findProjectRoot();
        $logDir = $projectRoot . '/var/log';

        // Ensure log directory exists
        if (!is_dir($logDir)) {
            mkdir($logDir, 0o755, true);
        }

        $container->register(Logger::class)
            ->setArguments(['typo3-upgrade-analyzer'])
            ->addMethodCall('pushHandler', [new StreamHandler($logDir . '/typo3-upgrade-analyzer.log', Logger::INFO)])
            ->setPublic(true);

        $container->setAlias(LoggerInterface::class, Logger::class)
            ->setPublic(true);

        // Add 'logger' alias for services.yaml compatibility
        $container->setAlias('logger', Logger::class)
            ->setPublic(true);

        // HTTP Client - register as a service definition
        $container->register('http_client', \Symfony\Contracts\HttpClient\HttpClientInterface::class)
            ->setFactory([HttpClient::class, 'create'])
            ->setArguments([[
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'TYPO3-Upgrade-Analyzer/1.0',
                ],
            ]])
            ->setPublic(true);

        // Configuration parameters
        $sourceDir = \dirname(__DIR__, 3);

        // Set both project root (for working directories) and source dir (for templates/config)
        $container->setParameter('app.project_root', $projectRoot);
        $container->setParameter('app.source_dir', $sourceDir);
        $container->setParameter('app.config_dir', '%app.source_dir%/config');
        $container->setParameter('app.resources_dir', '%app.source_dir%/resources');

        // Legacy parameters for backward compatibility
        $container->setParameter('app.root_dir', $projectRoot); // Now points to project root, not source dir
        $container->setParameter('app.install_dir', $projectRoot);
    }

    private static function loadServiceDefinitions(ContainerBuilder $container): void
    {
        $sourceDir = $container->getParameter('app.source_dir');
        \assert(\is_string($sourceDir), 'app.source_dir parameter must be a string');
        $configDir = $sourceDir . '/config';

        if (file_exists($configDir . '/services.yaml')) {
            $loader = new YamlFileLoader($container, new FileLocator($configDir));
            $loader->load('services.yaml');
        }
    }

    /**
     * Auto-register analyzer classes from directory.
     *
     * @param ContainerBuilder $container Container builder
     *
     * @phpstan-ignore-next-line method.unused
     */
    private static function autoRegisterAnalyzers(ContainerBuilder $container): void
    {
        // Auto-register all analyzers in the Infrastructure/Analyzer directory
        $analyzerDir = \dirname(__DIR__, 2) . '/Infrastructure/Analyzer';

        if (!is_dir($analyzerDir)) {
            return;
        }

        $analyzerFiles = glob($analyzerDir . '/*Analyzer.php');

        if (false === $analyzerFiles) {
            return;
        }

        foreach ($analyzerFiles as $file) {
            $className = 'CPSIT\\UpgradeAnalyzer\\Infrastructure\\Analyzer\\' .
                        pathinfo($file, PATHINFO_FILENAME);

            if (class_exists($className)) {
                $container->autowire($className);
                $container->addDefinitions([$className => $container->getDefinition($className)]);
            }
        }
    }
}
