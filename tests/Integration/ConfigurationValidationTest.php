<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration;

/**
 * Validation test to ensure integration test configuration is correct
 * This test can run without real API calls to validate the setup.
 *
 * @group integration
 */
class ConfigurationValidationTest extends AbstractIntegrationTest
{
    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::setUp
     */
    public function testEnvironmentConfigurationIsLoaded(): void
    {
        // Test that environment variables are properly loaded
        $this->assertIsBool($this->enableRealApiCalls);
        $this->assertIsString($this->githubToken);
        $this->assertIsInt($this->apiRequestTimeout);
        $this->assertIsInt($this->rateLimitDelay);
        $this->assertIsString($this->cacheDir);

        // Validate reasonable values
        $this->assertGreaterThan(0, $this->apiRequestTimeout);
        $this->assertGreaterThanOrEqual(0, $this->rateLimitDelay);
        $this->assertNotEmpty($this->cacheDir);
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::setUp
     */
    public function testHttpClientIsConfigured(): void
    {
        $this->assertInstanceOf(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $this->httpClient);
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::setUp
     */
    public function testCacheDirectoryExists(): void
    {
        $this->assertDirectoryExists($this->cacheDir);
        $this->assertIsWritable($this->cacheDir);
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::loadTestData
     */
    public function testTestDataCanBeLoaded(): void
    {
        $testData = $this->loadTestData('known_extensions.json');

        $this->assertIsArray($testData);
        $this->assertArrayHasKey('extensions', $testData);
        $this->assertArrayHasKey('test_configurations', $testData);

        // Validate structure of known extensions
        $extensions = $testData['extensions'];
        $this->assertNotEmpty($extensions);

        foreach ($extensions as $extension) {
            $this->assertArrayHasKey('extension_key', $extension);
            $this->assertArrayHasKey('description', $extension);
            $this->assertArrayHasKey('is_active', $extension);
            $this->assertArrayHasKey('has_ter_versions', $extension);
            $this->assertArrayHasKey('has_packagist_versions', $extension);
            $this->assertArrayHasKey('test_scenarios', $extension);
        }
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::createTestExtension
     */
    public function testTestExtensionCreation(): void
    {
        $extension = $this->createTestExtension('test_key', 'vendor/test-package');

        $this->assertInstanceOf(\CPSIT\UpgradeAnalyzer\Domain\Entity\Extension::class, $extension);
        $this->assertEquals('test_key', $extension->getKey());
        $this->assertEquals('vendor/test-package', $extension->getComposerName());
        $this->assertFalse($extension->isSystemExtension());
        $this->assertFalse($extension->isLocalExtension());
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::createTestAnalysisContext
     */
    public function testAnalysisContextCreation(): void
    {
        $context = $this->createTestAnalysisContext('12.4.0');

        $this->assertInstanceOf(\CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext::class, $context);
        $this->assertEquals('12.4.0', $context->getTargetVersion()->toString());
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::createLogger
     */
    public function testLoggerCreation(): void
    {
        $logger = $this->createLogger();

        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $logger);
        $this->assertInstanceOf(\Psr\Log\NullLogger::class, $logger);
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::createAuthenticatedGitHubClient
     */
    public function testGitHubClientCreation(): void
    {
        $client = $this->createAuthenticatedGitHubClient();

        $this->assertInstanceOf(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $client);
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::cacheApiResponse
     */
    public function testApiResponseCaching(): void
    {
        $cacheKey = 'test_cache_key_' . uniqid();
        $testData = ['test' => 'data', 'timestamp' => time()];

        // First call should execute the callable
        $result1 = $this->cacheApiResponse($cacheKey, fn (): array => $testData);
        $this->assertEquals($testData, $result1);

        // Second call should return cached data
        $result2 = $this->cacheApiResponse($cacheKey, fn (): array => ['different' => 'data']);
        $this->assertEquals($testData, $result2); // Should be same as first call
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::getTestDataPath
     */
    public function testTestDataPath(): void
    {
        $testDataPath = $this->getTestDataPath();

        $this->assertIsString($testDataPath);
        $this->assertDirectoryExists($testDataPath);
        $this->assertFileExists($testDataPath . '/known_extensions.json');
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::requiresRealApiCalls
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::requiresGitHubToken
     */
    public function testSkipConditions(): void
    {
        $hasAssertions = false;

        // These should not skip when properly configured
        if (!$this->enableRealApiCalls) {
            try {
                $this->requiresRealApiCalls();
                $this->fail('Should have marked test as skipped');
            } catch (\PHPUnit\Framework\SkippedTestError $e) {
                $this->assertStringContainsString('Real API calls are disabled', $e->getMessage());
                $hasAssertions = true;
            }
        } else {
            // If real API calls are enabled, the method should not skip
            $this->requiresRealApiCalls(); // Should not throw exception
        }

        if (empty($this->githubToken)) {
            try {
                $this->requiresGitHubToken();
                $this->fail('Should have marked test as skipped');
            } catch (\PHPUnit\Framework\SkippedTestError $e) {
                $this->assertStringContainsString('GitHub token not provided', $e->getMessage());
                $hasAssertions = true;
            }
        } else {
            // If GitHub token is provided, the method should not skip
            $this->requiresGitHubToken(); // Should not throw exception
        }

        // Always assert that skip methods are callable
        $this->assertTrue(method_exists($this, 'requiresRealApiCalls'));
        $this->assertTrue(method_exists($this, 'requiresGitHubToken'));
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::assertResponseTimeAcceptable
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest::assertRateLimitRespected
     */
    public function testAssertionHelpers(): void
    {
        // Test response time assertion
        $this->assertResponseTimeAcceptable(1.5, 5.0); // Should pass

        try {
            $this->assertResponseTimeAcceptable(10.0, 5.0); // Should fail
            $this->fail('Should have failed assertion');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('response time', $e->getMessage());
        }

        // Test rate limit assertion with mock headers
        $headers = ['x-ratelimit-remaining' => ['100']];
        $this->assertRateLimitRespected($headers); // Should pass

        try {
            $headers = ['x-ratelimit-remaining' => ['0']];
            $this->assertRateLimitRespected($headers); // Should fail
            $this->fail('Should have failed assertion');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('rate limit exceeded', $e->getMessage());
        }
    }
}
