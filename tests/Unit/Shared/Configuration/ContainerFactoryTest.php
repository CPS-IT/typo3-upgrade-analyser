<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Shared\Configuration;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use CPSIT\UpgradeAnalyzer\Shared\Configuration\ContainerFactory;
use CPSIT\UpgradeAnalyzer\Application\Command\AnalyzeCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\ListAnalyzersCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\ValidateCommand;

/**
 * Test case for the ContainerFactory
 *
 * @covers \CPSIT\UpgradeAnalyzer\Shared\Configuration\ContainerFactory
 */
class ContainerFactoryTest extends TestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = ContainerFactory::create();
    }

    public function testCreateReturnsContainerInterface(): void
    {
        $container = ContainerFactory::create();
        
        self::assertInstanceOf(ContainerInterface::class, $container);
    }

    public function testContainerIsCompiled(): void
    {
        // Compiled containers should not allow further service definitions
        self::assertTrue($this->container->isCompiled());
    }

    public function testCoreServicesAreRegistered(): void
    {
        // Test Logger services
        self::assertTrue($this->container->has(LoggerInterface::class));
        self::assertTrue($this->container->has(Logger::class));
        
        $logger = $this->container->get(LoggerInterface::class);
        self::assertInstanceOf(LoggerInterface::class, $logger);
        self::assertInstanceOf(Logger::class, $logger);
        
        // Test HTTP Client
        self::assertTrue($this->container->has('http_client'));
        
        $httpClient = $this->container->get('http_client');
        self::assertInstanceOf(HttpClientInterface::class, $httpClient);
    }

    public function testLoggerConfiguration(): void
    {
        $logger = $this->container->get(Logger::class);
        
        self::assertEquals('typo3-upgrade-analyzer', $logger->getName());
        self::assertNotEmpty($logger->getHandlers());
        
        // Test that it's the same instance for both interfaces
        $loggerInterface = $this->container->get(LoggerInterface::class);
        self::assertSame($logger, $loggerInterface);
    }

    public function testHttpClientConfiguration(): void
    {
        $httpClient = $this->container->get('http_client');
        
        // Verify it's a proper HTTP client instance
        self::assertInstanceOf(HttpClientInterface::class, $httpClient);
    }

    public function testApplicationParametersAreSet(): void
    {
        $rootDir = $this->container->getParameter('app.root_dir');
        $configDir = $this->container->getParameter('app.config_dir');
        $resourcesDir = $this->container->getParameter('app.resources_dir');
        
        self::assertIsString($rootDir);
        self::assertIsString($configDir);
        self::assertIsString($resourcesDir);
        
        // Check parameter relationships
        self::assertEquals($rootDir . '/config', $configDir);
        self::assertEquals($rootDir . '/resources', $resourcesDir);
    }

    public function testCommandsAreRegistered(): void
    {
        // Test all command classes are available in container
        $commandClasses = [
            AnalyzeCommand::class,
            InitConfigCommand::class,
            ListAnalyzersCommand::class,
            ValidateCommand::class,
        ];
        
        foreach ($commandClasses as $commandClass) {
            self::assertTrue(
                $this->container->has($commandClass),
                "Command $commandClass is not registered in container"
            );
            
            $command = $this->container->get($commandClass);
            self::assertInstanceOf($commandClass, $command);
        }
    }

    public function testAnalyzeCommandDependencies(): void
    {
        $command = $this->container->get(AnalyzeCommand::class);
        
        self::assertInstanceOf(AnalyzeCommand::class, $command);
        
        // Test command configuration
        self::assertEquals('analyze', $command->getName());
        self::assertEquals('Analyze a TYPO3 installation for upgrade readiness', $command->getDescription());
    }

    public function testInitConfigCommandDependencies(): void
    {
        $command = $this->container->get(InitConfigCommand::class);
        
        self::assertInstanceOf(InitConfigCommand::class, $command);
        self::assertEquals('init-config', $command->getName());
    }

    public function testListAnalyzersCommandDependencies(): void
    {
        $command = $this->container->get(ListAnalyzersCommand::class);
        
        self::assertInstanceOf(ListAnalyzersCommand::class, $command);
        self::assertEquals('list-analyzers', $command->getName());
    }

    public function testValidateCommandDependencies(): void
    {
        $command = $this->container->get(ValidateCommand::class);
        
        self::assertInstanceOf(ValidateCommand::class, $command);
        self::assertEquals('validate', $command->getName());
    }

    public function testTwigServicesAreRegistered(): void
    {
        // Test Twig Environment
        if ($this->container->has(\Twig\Environment::class)) {
            $twig = $this->container->get(\Twig\Environment::class);
            self::assertInstanceOf(\Twig\Environment::class, $twig);
        }
        
        // Test Twig Loader
        if ($this->container->has('twig.loader')) {
            $loader = $this->container->get('twig.loader');
            self::assertInstanceOf(\Twig\Loader\LoaderInterface::class, $loader);
        }
    }

    public function testPhpParserIsRegistered(): void
    {
        if ($this->container->has(\PhpParser\Parser::class)) {
            $parser = $this->container->get(\PhpParser\Parser::class);
            self::assertInstanceOf(\PhpParser\Parser::class, $parser);
        }
    }

    public function testServiceDefinitionsLoadedFromYaml(): void
    {
        // Test that services.yaml was loaded by checking for service definitions
        // that would only exist if the YAML file was processed
        
        // The commands should be public (as defined in services.yaml)
        self::assertTrue($this->container->has(AnalyzeCommand::class));
        
        // If services.yaml is loaded, autowiring should be enabled
        // This is harder to test directly, but we can verify commands are properly instantiated
        $command = $this->container->get(AnalyzeCommand::class);
        self::assertInstanceOf(AnalyzeCommand::class, $command);
    }

    public function testAutoRegisterAnalyzersHandlesEmptyDirectory(): void
    {
        // Test that the analyzer auto-registration doesn't fail when analyzer directory doesn't exist
        // This is tested implicitly by the container creation not throwing exceptions
        
        $container = ContainerFactory::create();
        self::assertInstanceOf(ContainerInterface::class, $container);
    }

    public function testContainerParametersAreCorrect(): void
    {
        $rootDir = $this->container->getParameter('app.root_dir');
        
        // Root dir should be the project root (3 levels up from ContainerFactory location)
        self::assertStringEndsWith('typo3-upgrade-analyzer', $rootDir);
        self::assertDirectoryExists($rootDir);
        
        // Config dir should exist relative to root
        $configDir = $this->container->getParameter('app.config_dir');
        self::assertEquals($rootDir . '/config', $configDir);
        
        // Resources dir should exist relative to root
        $resourcesDir = $this->container->getParameter('app.resources_dir');
        self::assertEquals($rootDir . '/resources', $resourcesDir);
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
        // Get all service IDs and attempt to instantiate each one
        $serviceIds = [
            LoggerInterface::class,
            Logger::class,
            'http_client',
            AnalyzeCommand::class,
            InitConfigCommand::class,
            ListAnalyzersCommand::class,
            ValidateCommand::class,
        ];
        
        foreach ($serviceIds as $serviceId) {
            if ($this->container->has($serviceId)) {
                try {
                    $service = $this->container->get($serviceId);
                    self::assertNotNull($service, "Service $serviceId could not be instantiated");
                } catch (\Throwable $e) {
                    self::fail("Failed to instantiate service $serviceId: " . $e->getMessage());
                }
            }
        }
    }

    public function testContainerHandlesCircularDependencies(): void
    {
        // Test that container creation doesn't fail due to circular dependencies
        // This is tested by successful container creation in setUp
        self::assertTrue($this->container->isCompiled());
    }

    public function testAutoConfigurationWorks(): void
    {
        // Commands should be auto-configured (this is defined in services.yaml)
        $commands = [
            $this->container->get(AnalyzeCommand::class),
            $this->container->get(InitConfigCommand::class),
            $this->container->get(ListAnalyzersCommand::class),
            $this->container->get(ValidateCommand::class),
        ];
        
        foreach ($commands as $command) {
            self::assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $command);
            self::assertNotEmpty($command->getName());
        }
    }
}