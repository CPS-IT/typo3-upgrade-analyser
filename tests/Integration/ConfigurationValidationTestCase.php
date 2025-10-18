<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration;

/**
 * Validation test to ensure integration test configuration is correct
 * This test can run without real API calls to validate the setup.
 *
 * @group integration
 */
class ConfigurationValidationTestCase extends AbstractIntegrationTestCase
{
    /**
     * @coversNothing
     */
    public function testEnvironmentConfigurationIsLoaded(): void
    {
        // Test that environment variables are properly loaded and have reasonable values
        // Type checking removed as properties are already strictly typed

        // Validate reasonable values
        $this->assertGreaterThan(0, $this->apiRequestTimeout);
        $this->assertGreaterThanOrEqual(0, $this->rateLimitDelay);
        $this->assertNotEmpty($this->cacheDir);
    }

    /**
     * @coversNothing
     */
    public function testCacheDirectoryExists(): void
    {
        $this->assertDirectoryExists($this->cacheDir);
        $this->assertIsWritable($this->cacheDir);
    }

    /**
     * @coversNothing
     */
    public function testTestDataCanBeLoaded(): void
    {
        $testData = $this->loadTestData('known_extensions.json');

        // Type assertion removed as method has explicit return type
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
     * @coversNothing
     */
    public function testTestExtensionCreation(): void
    {
        $extension = $this->createTestExtension('test_key', 'vendor/test-package');

        $this->assertEquals('test_key', $extension->getKey());
        $this->assertEquals('vendor/test-package', $extension->getComposerName());
        $this->assertFalse($extension->isSystemExtension());
        $this->assertFalse($extension->isLocalExtension());
    }

    /**
     * @coversNothing
     */
    public function testAnalysisContextCreation(): void
    {
        $context = $this->createTestAnalysisContext('12.4.0');

        $this->assertEquals('12.4.0', $context->getTargetVersion()->toString());
    }

    /**
     * @coversNothing
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
     * @coversNothing
     */
    public function testTestDataPath(): void
    {
        $testDataPath = $this->getTestDataPath();

        // Type checking removed as return type is already strictly typed
        $this->assertDirectoryExists($testDataPath);
        $this->assertFileExists($testDataPath . '/known_extensions.json');
    }

    /**
     * @coversNothing
     */
    public function testSkipConditions(): void
    {
        // Verify functionality when conditions are met (non-skipping case)
        if ($this->enableRealApiCalls) {
            $this->requiresRealApiCalls(); // Should not throw when enabled
        }

        if (!empty($this->githubToken)) {
            $this->requiresGitHubToken(); // Should not throw when token provided
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @coversNothing
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
