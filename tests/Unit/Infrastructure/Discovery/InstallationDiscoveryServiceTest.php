<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ConfigurationDiscoveryService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DetectionStrategyInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationIssue;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationRuleInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationSeverity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService
 */
final class InstallationDiscoveryServiceTest extends TestCase
{
    private MockObject&LoggerInterface $logger;
    private MockObject&ConfigurationDiscoveryService $configurationDiscoveryService;
    private MockObject&ConfigurationService $configService;
    private MockObject&CacheService $cacheService;
    private InstallationDiscoveryService $service;
    private string $testDir;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->configurationDiscoveryService = $this->createMock(ConfigurationDiscoveryService::class);
        $this->configService = $this->createMock(ConfigurationService::class);
        $this->cacheService = $this->createMock(CacheService::class);
        $this->testDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($this->testDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    }

    public function testConstructorSortsStrategiesByPriority(): void
    {
        $lowPriorityStrategy = $this->createMockStrategy('Low Priority', 10);
        $highPriorityStrategy = $this->createMockStrategy('High Priority', 100);
        $mediumPriorityStrategy = $this->createMockStrategy('Medium Priority', 50);

        $service = $this->createService(
            [$lowPriorityStrategy, $highPriorityStrategy, $mediumPriorityStrategy],
        );

        $strategies = $service->getDetectionStrategies();

        self::assertSame('High Priority', $strategies[0]->getName());
        self::assertSame('Medium Priority', $strategies[1]->getName());
        self::assertSame('Low Priority', $strategies[2]->getName());
    }

    public function testDiscoverInstallationFailsForNonExistentPath(): void
    {
        $strategy = $this->createMockStrategy('Test Strategy', 100);
        $this->service = $this->createService([$strategy]);

        $this->logger->expects(self::once())
            ->method('info')
            ->with('Starting installation discovery', self::isType('array'));

        $result = $this->service->discoverInstallation('/does/not/exist');

        self::assertFalse($result->isSuccessful());
        self::assertSame('Path does not exist or is not a directory', $result->getErrorMessage());
        self::assertEmpty($result->getAttemptedStrategies());
    }

    public function testDiscoverInstallationSkipsStrategyWithoutRequiredIndicators(): void
    {
        $strategy = $this->createMockStrategy('Test Strategy', 100, ['required-file.txt']);
        $this->service = $this->createService([$strategy]);

        $this->logger->expects(self::atLeastOnce())
            ->method('debug');

        $result = $this->service->discoverInstallation($this->testDir);

        self::assertFalse($result->isSuccessful());
        $attemptedStrategies = $result->getAttemptedStrategies();
        self::assertCount(1, $attemptedStrategies);
        self::assertFalse($attemptedStrategies[0]['supported']);
        self::assertStringContainsString('Required indicators not found', $attemptedStrategies[0]['reason']);
    }

    public function testDiscoverInstallationSkipsStrategyThatDoesNotSupport(): void
    {
        // Create indicator file so required indicators check passes
        file_put_contents($this->testDir . '/indicator.txt', 'test');

        $strategy = $this->createMockStrategy('Test Strategy', 100, ['indicator.txt']);
        $strategy->method('supports')->willReturn(false);

        $this->service = $this->createService([$strategy]);

        $this->logger->expects(self::atLeastOnce())
            ->method('debug');

        $result = $this->service->discoverInstallation($this->testDir);

        self::assertFalse($result->isSuccessful());
        $attemptedStrategies = $result->getAttemptedStrategies();
        self::assertCount(1, $attemptedStrategies);
        self::assertFalse($attemptedStrategies[0]['supported']);
        self::assertSame('Strategy-specific support check failed', $attemptedStrategies[0]['reason']);
    }

    public function testDiscoverInstallationSuccessfullyDetectsInstallation(): void
    {
        file_put_contents($this->testDir . '/indicator.txt', 'test');

        $installation = $this->createMockInstallation();
        $strategy = $this->createMockStrategy('Test Strategy', 100, ['indicator.txt']);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('detect')->willReturn($installation);

        // Mock configuration discovery to return the same installation
        $this->configurationDiscoveryService->expects(self::once())
            ->method('discoverConfiguration')
            ->with($installation)
            ->willReturn($installation);

        $this->service = $this->createService([$strategy]);

        $this->logger->expects(self::atLeastOnce())
            ->method('info');

        $result = $this->service->discoverInstallation($this->testDir);

        self::assertTrue($result->isSuccessful());
        self::assertSame($installation, $result->getInstallation());
        self::assertSame($strategy, $result->getSuccessfulStrategy());
        self::assertEmpty($result->getValidationIssues());
    }

    public function testDiscoverInstallationWithValidation(): void
    {
        file_put_contents($this->testDir . '/indicator.txt', 'test');

        $installation = $this->createMockInstallation();
        $strategy = $this->createMockStrategy('Test Strategy', 100, ['indicator.txt']);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('detect')->willReturn($installation);

        // Mock configuration discovery to return the same installation
        $this->configurationDiscoveryService->expects(self::once())
            ->method('discoverConfiguration')
            ->with($installation)
            ->willReturn($installation);

        $validationIssue = new ValidationIssue(
            'Test Rule',
            ValidationSeverity::WARNING,
            'Test warning message',
            'test',
            [],
            [],
            [],
        );

        $validationRule = $this->createMockValidationRule('Test Rule', [$validationIssue]);

        $this->service = $this->createService([$strategy], [$validationRule]);

        $result = $this->service->discoverInstallation($this->testDir, true);

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->getValidationIssues());
        self::assertSame($validationIssue, $result->getValidationIssues()[0]);
    }

    public function testDiscoverInstallationWithoutValidation(): void
    {
        file_put_contents($this->testDir . '/indicator.txt', 'test');

        $installation = $this->createMockInstallation();
        $strategy = $this->createMockStrategy('Test Strategy', 100, ['indicator.txt']);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('detect')->willReturn($installation);

        // Mock configuration discovery to return the same installation
        $this->configurationDiscoveryService->expects(self::once())
            ->method('discoverConfiguration')
            ->with($installation)
            ->willReturn($installation);

        $validationRule = $this->createMockValidationRule('Test Rule', []);

        $this->service = $this->createService([$strategy], [$validationRule]);

        $result = $this->service->discoverInstallation($this->testDir, false);

        self::assertTrue($result->isSuccessful());
        self::assertEmpty($result->getValidationIssues());
    }

    public function testDiscoverInstallationHandlesStrategyException(): void
    {
        file_put_contents($this->testDir . '/indicator.txt', 'test');

        $strategy = $this->createMockStrategy('Test Strategy', 100, ['indicator.txt']);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('detect')->willThrowException(new \RuntimeException('Test exception'));

        $this->service = $this->createService([$strategy]);

        $this->logger->expects(self::atLeastOnce())
            ->method('warning');

        $result = $this->service->discoverInstallation($this->testDir);

        self::assertFalse($result->isSuccessful());
        $attemptedStrategies = $result->getAttemptedStrategies();
        self::assertCount(1, $attemptedStrategies);
        self::assertTrue($attemptedStrategies[0]['supported']);
        self::assertSame('error', $attemptedStrategies[0]['result']);
        self::assertSame('Test exception', $attemptedStrategies[0]['error']);
    }

    public function testDiscoverInstallationReturnsNullFromStrategy(): void
    {
        file_put_contents($this->testDir . '/indicator.txt', 'test');

        $strategy = $this->createMockStrategy('Test Strategy', 100, ['indicator.txt']);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('detect')->willReturn(null);

        $this->service = $this->createService([$strategy]);

        $this->logger->expects(self::atLeastOnce())
            ->method('debug');

        $result = $this->service->discoverInstallation($this->testDir);

        self::assertFalse($result->isSuccessful());
        $attemptedStrategies = $result->getAttemptedStrategies();
        self::assertCount(1, $attemptedStrategies);
        self::assertTrue($attemptedStrategies[0]['supported']);
        self::assertSame('no_installation_found', $attemptedStrategies[0]['result']);
    }

    public function testGetDetectionStrategies(): void
    {
        $strategy1 = $this->createMockStrategy('Strategy 1', 100);
        $strategy2 = $this->createMockStrategy('Strategy 2', 50);

        $this->service = $this->createService([$strategy1, $strategy2]);

        $strategies = $this->service->getDetectionStrategies();

        self::assertCount(2, $strategies);
        self::assertSame($strategy1, $strategies[0]);
        self::assertSame($strategy2, $strategies[1]);
    }

    public function testGetApplicableStrategies(): void
    {
        // Create indicator files
        file_put_contents($this->testDir . '/file1.txt', 'test');
        file_put_contents($this->testDir . '/file2.txt', 'test');

        $strategy1 = $this->createMockStrategy('Strategy 1', 100, ['file1.txt']);
        $strategy2 = $this->createMockStrategy('Strategy 2', 50, ['file2.txt']);
        $strategy3 = $this->createMockStrategy('Strategy 3', 25, ['missing.txt']);

        $this->service = $this->createService([$strategy1, $strategy2, $strategy3]);

        $applicable = $this->service->getApplicableStrategies($this->testDir);

        self::assertCount(2, $applicable);
        self::assertContains($strategy1, $applicable);
        self::assertContains($strategy2, $applicable);
        self::assertNotContains($strategy3, $applicable);
    }

    public function testGetSupportedStrategies(): void
    {
        file_put_contents($this->testDir . '/file1.txt', 'test');
        file_put_contents($this->testDir . '/file2.txt', 'test');

        $strategy1 = $this->createMockStrategy('Strategy 1', 100, ['file1.txt']);
        $strategy1->expects(self::once())->method('supports')->willReturn(true);

        $strategy2 = $this->createMockStrategy('Strategy 2', 50, ['file2.txt']);
        $strategy2->expects(self::once())->method('supports')->willReturn(false);

        $this->service = $this->createService([$strategy1, $strategy2]);

        $supported = $this->service->getSupportedStrategies($this->testDir);

        self::assertCount(1, $supported);
        self::assertContains($strategy1, $supported);
        self::assertNotContains($strategy2, $supported);
    }

    public function testCanDiscoverInstallation(): void
    {
        file_put_contents($this->testDir . '/indicator.txt', 'test');

        $strategy = $this->createMockStrategy('Test Strategy', 100, ['indicator.txt']);
        $this->service = $this->createService([$strategy]);

        self::assertTrue($this->service->canDiscoverInstallation($this->testDir));
        self::assertFalse($this->service->canDiscoverInstallation('/does/not/exist'));
    }

    public function testGetValidationRules(): void
    {
        $rule1 = $this->createMockValidationRule('Rule 1', []);
        $rule2 = $this->createMockValidationRule('Rule 2', []);

        $this->service = $this->createService([], [$rule1, $rule2]);

        $rules = $this->service->getValidationRules();

        self::assertCount(2, $rules);
        self::assertContains($rule1, $rules);
        self::assertContains($rule2, $rules);
    }

    public function testValidateInstallationWithApplicableRules(): void
    {
        $installation = $this->createMockInstallation();

        $issue1 = new ValidationIssue('Rule 1', ValidationSeverity::ERROR, 'Error message', 'test');
        $issue2 = new ValidationIssue('Rule 2', ValidationSeverity::WARNING, 'Warning message', 'test');

        $rule1 = $this->createMockValidationRule('Rule 1', [$issue1]);
        $rule2 = $this->createMockValidationRule('Rule 2', [$issue2]);

        $this->service = $this->createService([], [$rule1, $rule2]);

        $issues = $this->service->validateInstallation($installation);

        self::assertCount(2, $issues);
        self::assertContains($issue1, $issues);
        self::assertContains($issue2, $issues);
    }

    public function testValidateInstallationSkipsNonApplicableRules(): void
    {
        $installation = $this->createMockInstallation();

        $rule1 = $this->createMockValidationRule('Rule 1', [], true);
        $rule2 = $this->createMockValidationRule('Rule 2', [], false);

        $this->service = $this->createService([], [$rule1, $rule2]);

        $this->logger->expects(self::atLeastOnce())
            ->method('debug');

        $issues = $this->service->validateInstallation($installation);

        self::assertEmpty($issues);
    }

    public function testValidateInstallationHandlesRuleException(): void
    {
        $installation = $this->createMockInstallation();

        $rule = $this->createMockValidationRule('Failing Rule', []);
        $rule->method('validate')->willThrowException(new \RuntimeException('Validation failed'));

        $this->service = $this->createService([], [$rule]);

        $this->logger->expects(self::atLeastOnce())
            ->method('warning');

        $issues = $this->service->validateInstallation($installation);

        self::assertCount(1, $issues);
        self::assertSame('Failing Rule', $issues[0]->getRuleName());
        self::assertSame(ValidationSeverity::ERROR, $issues[0]->getSeverity());
        self::assertStringContainsString('Validation rule failed: Validation failed', $issues[0]->getMessage());
    }

    public function testDiscoverInstallationWithConfigurationDiscovery(): void
    {
        file_put_contents($this->testDir . '/indicator.txt', 'test');

        $installation = $this->createMockInstallation();
        $strategy = $this->createMockStrategy('Test Strategy', 100, ['indicator.txt']);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('detect')->willReturn($installation);

        // Configure ConfigurationDiscoveryService to return enhanced installation
        $enhancedInstallation = $this->createMockInstallation();
        $this->configurationDiscoveryService->expects(self::once())
            ->method('discoverConfiguration')
            ->with($installation)
            ->willReturn($enhancedInstallation);

        $this->service = $this->createService([$strategy]);

        $result = $this->service->discoverInstallation($this->testDir);

        self::assertTrue($result->isSuccessful());
        self::assertSame($enhancedInstallation, $result->getInstallation());
    }

    public function testDiscoverInstallationWithConfigurationDiscoveryException(): void
    {
        file_put_contents($this->testDir . '/indicator.txt', 'test');

        $installation = $this->createMockInstallation();
        $strategy = $this->createMockStrategy('Test Strategy', 100, ['indicator.txt']);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('detect')->willReturn($installation);

        // Configure ConfigurationDiscoveryService to throw exception
        $this->configurationDiscoveryService->expects(self::once())
            ->method('discoverConfiguration')
            ->with($installation)
            ->willThrowException(new \RuntimeException('Configuration discovery failed'));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Configuration discovery failed during installation discovery',
                self::callback(function (array $context): bool {
                    return 'Configuration discovery failed' === $context['exception_message'];
                }),
            );

        $this->service = $this->createService([$strategy]);

        $result = $this->service->discoverInstallation($this->testDir);

        // Installation discovery should still succeed even if configuration discovery fails
        self::assertTrue($result->isSuccessful());
        self::assertSame($installation, $result->getInstallation());
    }

    public function testCreateServiceWithCustomConfigurationDiscoveryService(): void
    {
        $customConfigService = $this->createMock(ConfigurationDiscoveryService::class);
        $service = $this->createService([], [], $customConfigService);

        self::assertInstanceOf(InstallationDiscoveryService::class, $service);
    }

    public function testConfigurationDiscoveryServiceIntegrationInWorkflow(): void
    {
        file_put_contents($this->testDir . '/indicator.txt', 'test');

        // Create installation with basic data
        $installation = $this->createMockInstallation();

        // Create strategy that returns the installation
        $strategy = $this->createMockStrategy('Test Strategy', 100, ['indicator.txt']);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('detect')->willReturn($installation);

        // Mock configuration discovery service to verify it's called with correct installation
        $this->configurationDiscoveryService->expects(self::once())
            ->method('discoverConfiguration')
            ->with(self::callback(function ($arg) use ($installation): bool {
                return $arg === $installation
                       && $arg->getPath() === $installation->getPath()
                       && $arg->getVersion()->toString() === $installation->getVersion()->toString();
            }))
            ->willReturnCallback(function ($installation) {
                // Simulate configuration discovery enhancing the installation
                return $installation;
            });

        $this->service = $this->createService([$strategy]);

        $result = $this->service->discoverInstallation($this->testDir);

        self::assertTrue($result->isSuccessful());
        self::assertSame($installation, $result->getInstallation());
    }

    private function createMockStrategy(string $name, int $priority, array $requiredIndicators = []): DetectionStrategyInterface&MockObject
    {
        $strategy = $this->createMock(DetectionStrategyInterface::class);
        $strategy->method('getName')->willReturn($name);
        $strategy->method('getPriority')->willReturn($priority);
        $strategy->method('getRequiredIndicators')->willReturn($requiredIndicators);
        $strategy->method('getDescription')->willReturn("Description for {$name}");

        // Don't set default return values for supports() and detect() here
        // Let the individual tests configure these as needed

        return $strategy;
    }

    private function createMockInstallation(): Installation
    {
        $installation = new Installation($this->testDir, new Version('12.4.8'));
        $installation->setMode(InstallationMode::COMPOSER);

        // Add a test extension
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'));
        $installation->addExtension($extension);

        return $installation;
    }

    private function createMockValidationRule(
        string $name,
        array $issues,
        bool $appliesTo = true,
    ): ValidationRuleInterface&MockObject {
        $rule = $this->createMock(ValidationRuleInterface::class);
        $rule->method('getName')->willReturn($name);
        $rule->method('getSeverity')->willReturn(ValidationSeverity::WARNING);
        $rule->method('getDescription')->willReturn("Description for {$name}");
        $rule->method('getCategory')->willReturn('test');
        $rule->method('appliesTo')->willReturn($appliesTo);
        $rule->method('validate')->willReturn($issues);

        return $rule;
    }

    private function createService(
        array $strategies = [],
        array $validationRules = [],
        ?ConfigurationDiscoveryService $configService = null,
    ): InstallationDiscoveryService {
        return new InstallationDiscoveryService(
            $strategies,
            $validationRules,
            $configService ?? $this->configurationDiscoveryService,
            $this->logger,
            $this->configService,
            $this->cacheService,
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
