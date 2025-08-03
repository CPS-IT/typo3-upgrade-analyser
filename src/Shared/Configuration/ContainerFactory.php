<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Shared\Configuration;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpClient\HttpClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating and configuring the dependency injection container
 */
class ContainerFactory
{
    public static function create(): ContainerInterface
    {
        $container = new ContainerBuilder();
        
        // Register core services
        self::registerCoreServices($container);
        
        // Load service definitions from configuration
        self::loadServiceDefinitions($container);
        
        // Auto-register analyzers
        self::autoRegisterAnalyzers($container);
        
        $container->compile();
        
        return $container;
    }
    
    private static function registerCoreServices(ContainerBuilder $container): void
    {
        // Logger - register single instance for both interfaces
        $container->register(Logger::class)
            ->setArguments(['typo3-upgrade-analyzer'])
            ->addMethodCall('pushHandler', [new StreamHandler('php://stdout', Logger::INFO)])
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
        $container->setParameter('app.root_dir', dirname(__DIR__, 3));
        $container->setParameter('app.config_dir', '%app.root_dir%/config');
        $container->setParameter('app.resources_dir', '%app.root_dir%/resources');
    }
    
    private static function loadServiceDefinitions(ContainerBuilder $container): void
    {
        $configDir = dirname(__DIR__, 3) . '/config';
        
        if (file_exists($configDir . '/services.yaml')) {
            $loader = new YamlFileLoader($container, new FileLocator($configDir));
            $loader->load('services.yaml');
        }
    }
    
    private static function autoRegisterAnalyzers(ContainerBuilder $container): void
    {
        // Auto-register all analyzers in the Infrastructure/Analyzer directory
        $analyzerDir = dirname(__DIR__, 2) . '/Infrastructure/Analyzer';
        
        if (!is_dir($analyzerDir)) {
            return;
        }
        
        $analyzerFiles = glob($analyzerDir . '/*Analyzer.php');
        
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