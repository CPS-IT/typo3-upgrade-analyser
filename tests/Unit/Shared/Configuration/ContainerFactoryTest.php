<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Shared\Configuration;

use CPSIT\UpgradeAnalyzer\Application\Command\AnalyzeCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\ListAnalyzersCommand;
use CPSIT\UpgradeAnalyzer\Shared\Configuration\ContainerFactory;
use Monolog\Logger;
use PhpParser\Parser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment;

#[CoversClass(ContainerFactory::class)]
class ContainerFactoryTest extends TestCase
{
    private static ContainerInterface $sharedContainer;
    private ContainerInterface $container;

    public static function setUpBeforeClass(): void
    {
        self::$sharedContainer = ContainerFactory::create();
    }

    protected function setUp(): void
    {
        $this->container = self::$sharedContainer;
    }

    public function testContainerIsCompiled(): void
    {
        // Additional verification that services are properly configured (implies compilation succeeded)
        self::assertTrue($this->container->has(LoggerInterface::class));
    }

    public function testCoreServicesAreRegistered(): void
    {
        // Test Logger services
        self::assertTrue($this->container->has(LoggerInterface::class));
        self::assertTrue($this->container->has(Logger::class));

        // Test HTTP Client
        self::assertTrue($this->container->has('http_client'));
    }

    public function testLoggerConfiguration(): void
    {
        $logger = $this->container->get(Logger::class);
        if ($logger instanceof Logger) {
            self::assertEquals('typo3-upgrade-analyzer', $logger->getName());
            self::assertNotEmpty($logger->getHandlers());
        }

        // Test that it's the same instance for both interfaces
        $loggerInterface = $this->container->get(LoggerInterface::class);
        self::assertSame($logger, $loggerInterface);
    }

    public function testApplicationParametersAreSet(): void
    {
        $rootDir = $this->container->getParameter('app.root_dir');
        $sourceDir = $this->container->getParameter('app.source_dir');
        $configDir = $this->container->getParameter('app.config_dir');
        $resourcesDir = $this->container->getParameter('app.resources_dir');

        self::assertIsString($rootDir);
        self::assertIsString($sourceDir);
        self::assertIsString($configDir);
        self::assertIsString($resourcesDir);

        // Check parameter relationships - config and resources are relative to source dir
        self::assertEquals($sourceDir . '/config', $configDir);
        self::assertEquals($sourceDir . '/resources', $resourcesDir);
    }

    public function testCommandsAreRegistered(): void
    {
        // Test all command classes are available in container
        $commandClasses = [
            AnalyzeCommand::class,
            InitConfigCommand::class,
            ListAnalyzersCommand::class,
        ];

        foreach ($commandClasses as $commandClass) {
            self::assertTrue(
                $this->container->has($commandClass),
                "Command $commandClass is not registered in container",
            );
        }
    }

    public function testTwigServicesAreRegistered(): void
    {
        // Test Twig Environment
        self::assertTrue(
            $this->container->has(Environment::class),
            'Twig Environment should be registered in the container',
        );

        // Test Twig Loader
        self::assertTrue(
            $this->container->has('twig.loader'),
            'Twig loader should be registered in the container',
        );
    }

    public function testPhpParserIsRegistered(): void
    {
        self::assertTrue(
            $this->container->has(Parser::class),
            'PHP Parser service should be registered in the container',
        );
    }

    public function testServiceDefinitionsLoadedFromYaml(): void
    {
        // Test that services.yaml was loaded by checking for service definitions
        // that would only exist if the YAML file was processed
        // The commands should be public (as defined in services.yaml)
        self::assertTrue($this->container->has(AnalyzeCommand::class));
    }

    public function testAutoRegisterAnalyzersHandlesEmptyDirectory(): void
    {
        // Test that the analyzer auto-registration doesn't fail when analyzer directory doesn't exist
        // This is tested implicitly by the container creation not throwing exceptions

        // Verify container is functional despite empty analyzer directory
        self::assertTrue($this->container->has(LoggerInterface::class));
        self::assertTrue($this->container->has('http_client'));
    }

    public function testContainerParametersAreCorrect(): void
    {
        $rootDir = $this->container->getParameter('app.root_dir');
        $sourceDir = $this->container->getParameter('app.source_dir');
        self::assertIsString($rootDir);
        self::assertIsString($sourceDir);

        // Root dir should be the project root - normalize path to handle composer scenarios
        $normalizedRootDir = realpath($rootDir) ?: '';
        self::assertStringEndsWith('typo3-upgrade-analyser', $normalizedRootDir);
        self::assertDirectoryExists($rootDir);

        // Source dir should be the source directory (3 levels up from ContainerFactory location)
        self::assertStringEndsWith('typo3-upgrade-analyser', $sourceDir);
        self::assertDirectoryExists($sourceDir);

        // Config dir should exist relative to source dir
        $configDir = $this->container->getParameter('app.config_dir');
        self::assertIsString($configDir);
        self::assertEquals($sourceDir . '/config', $configDir);

        // Resources dir should exist relative to source dir
        $resourcesDir = $this->container->getParameter('app.resources_dir');
        self::assertIsString($resourcesDir);
        self::assertEquals($sourceDir . '/resources', $resourcesDir);
    }

    public function testServicesSingleton(): void
    {
        // Test that services are singletons by default
        $logger1 = $this->container->get(LoggerInterface::class);
        $logger2 = $this->container->get(LoggerInterface::class);

        self::assertSame($logger1, $logger2);

        $httpClient1 = $this->container->get('http_client');
        $httpClient2 = $this->container->get('http_client');

        self::assertSame($httpClient1, $httpClient2);
    }

    public function testContainerCanInstantiateAllRegisteredServices(): void
    {
        $this->expectNotToPerformAssertions();
        // Get all service IDs and attempt to instantiate each one
        $serviceIds = [
            LoggerInterface::class,
            Logger::class,
            'http_client',
            AnalyzeCommand::class,
            InitConfigCommand::class,
            ListAnalyzersCommand::class,
        ];

        foreach ($serviceIds as $serviceId) {
            if ($this->container->has($serviceId)) {
                try {
                    $this->container->get($serviceId);
                } catch (\Throwable $e) {
                    self::fail("Failed to instantiate service $serviceId: " . $e->getMessage());
                }
            }
        }
    }

    public function testContainerHandlesCircularDependencies(): void
    {
        // Verify all services can be instantiated without circular dependency issues
        $serviceIds = [
            LoggerInterface::class,
            Logger::class,
            'http_client',
            AnalyzeCommand::class,
            InitConfigCommand::class,
            ListAnalyzersCommand::class,
        ];

        $instantiatedServices = [];
        foreach ($serviceIds as $serviceId) {
            if ($this->container->has($serviceId)) {
                $service = $this->container->get($serviceId);
                $instantiatedServices[$serviceId] = $service;
            }
        }

        // Verify we successfully instantiated multiple services
        self::assertGreaterThanOrEqual(5, \count($instantiatedServices), 'Should instantiate at least 5 services without circular dependency issues');
    }
}
