<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\ExternalTool;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ExternalToolException;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTestCase;
use Exception;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Integration tests for TER API with real API calls.
 *
 * @group integration
 * @group ter-api
 * @group real-world
 */
class TerApiIntegrationTestCase extends AbstractIntegrationTestCase
{
    private TerApiClient $terApiClient;
    private array $testExtensions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresRealApiCalls();
        $this->requiresTerToken();

        // Load test extension data
        $this->testExtensions = $this->loadTestData('known_extensions.json');

        // Create TER API client
        $this->terApiClient = new TerApiClient(
            $this->createHttpClientService(),
            $this->createLogger(),
        );
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     */
    public function testHasVersionForExistingExtensionWithTypo3Twelve(): void
    {
        $startTime = microtime(true);

        $extensionKey = $this->testExtensions['extensions']['georgringer/news']['extension_key'];
        $typo3Version = new Version('12.4.0');

        $hasVersion = $this->cacheApiResponse(
            "ter_has_version_{$extensionKey}_12.4.0",
            fn (): bool => $this->terApiClient->hasVersionFor($extensionKey, $typo3Version),
        );

        $responseTime = microtime(true) - $startTime;

        $this->assertTrue($hasVersion, 'News extension should have TYPO3 12.4 compatible version in TER');
        $this->assertResponseTimeAcceptable($responseTime, 10.0);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     */
    public function testHasVersionForExistingExtensionWithTypo3Eleven(): void
    {
        $extensionKey = $this->testExtensions['extensions']['georgringer/news']['extension_key'];
        $typo3Version = new Version('11.5.0');

        $hasVersion = $this->cacheApiResponse(
            "ter_has_version_{$extensionKey}_11.5.0",
            fn (): bool => $this->terApiClient->hasVersionFor($extensionKey, $typo3Version),
        );

        $this->assertTrue($hasVersion, 'News extension should have TYPO3 11.5 compatible version in TER');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     */
    public function testHasVersionForArchivedExtensionWithTypo3Twelve(): void
    {
        $extensionKey = $this->testExtensions['extensions']['dmitryd/typo3-realurl']['extension_key'];
        $typo3Version = new Version('12.4.0');

        $hasVersion = $this->cacheApiResponse(
            "ter_has_version_{$extensionKey}_12.4.0",
            fn (): bool => $this->terApiClient->hasVersionFor($extensionKey, $typo3Version),
        );

        $this->assertFalse($hasVersion, 'RealURL extension should not have TYPO3 12.4 compatible version in TER');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     */
    public function testHasVersionForArchivedExtensionWithOlderTypo3(): void
    {
        $extensionKey = $this->testExtensions['extensions']['dmitryd/typo3-realurl']['extension_key'];
        $typo3Version = new Version('8.7.0');

        $hasVersion = $this->cacheApiResponse(
            "ter_has_version_{$extensionKey}_8.7.0",
            fn (): bool => $this->terApiClient->hasVersionFor($extensionKey, $typo3Version),
        );

        $this->assertTrue($hasVersion, 'RealURL extension should have TYPO3 8.7 compatible version in TER');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     */
    public function testHasVersionForNonExistentExtension(): void
    {
        $nonExistentKey = 'non_existent_extension_' . uniqid();
        $typo3Version = new Version('12.4.0');

        // TER API with authentication should return false for non-existent extensions
        // instead of throwing exception (graceful handling of 400 responses)
        $hasVersion = $this->terApiClient->hasVersionFor($nonExistentKey, $typo3Version);

        $this->assertFalse($hasVersion, 'Non-existent extension should return false, not throw exception');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::getLatestVersion
     */
    public function testGetLatestVersionForExistingExtension(): void
    {
        $startTime = microtime(true);

        $extensionKey = $this->testExtensions['extensions']['georgringer/news']['extension_key'];
        $typo3Version = new Version('12.4.0');

        $latestVersion = $this->cacheApiResponse(
            "ter_latest_version_{$extensionKey}_12.4.0",
            fn (): ?string => $this->terApiClient->getLatestVersion($extensionKey, $typo3Version),
        );

        $responseTime = microtime(true) - $startTime;

        $this->assertNotNull($latestVersion, 'Should return a version string for news extension with TYPO3 12.4');
        $this->assertIsString($latestVersion);
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+/',
            $latestVersion,
            'Version should follow semantic versioning pattern',
        );
        $this->assertResponseTimeAcceptable($responseTime, 10.0);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::getLatestVersion
     */
    public function testGetLatestVersionForArchivedExtensionWithNewTypo3(): void
    {
        $extensionKey = $this->testExtensions['extensions']['dmitryd/typo3-realurl']['extension_key'];
        $typo3Version = new Version('12.4.0');

        $latestVersion = $this->cacheApiResponse(
            "ter_latest_version_{$extensionKey}_12.4.0",
            fn (): ?string => $this->terApiClient->getLatestVersion($extensionKey, $typo3Version),
        );

        $this->assertNull($latestVersion, 'Archived extension should not have TYPO3 12.4 compatible version');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::getLatestVersion
     */
    public function testGetLatestVersionForArchivedExtensionWithOldTypo3(): void
    {
        $extensionKey = $this->testExtensions['extensions']['dmitryd/typo3-realurl']['extension_key'];
        $typo3Version = new Version('8.7.0');

        $latestVersion = $this->cacheApiResponse(
            "ter_latest_version_{$extensionKey}_8.7.0",
            fn (): ?string => $this->terApiClient->getLatestVersion($extensionKey, $typo3Version),
        );

        $this->assertNotNull($latestVersion, 'Archived extension should have older TYPO3 compatible version');
        $this->assertIsString($latestVersion);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::getLatestVersion
     */
    public function testGetLatestVersionForNonExistentExtension(): void
    {
        $nonExistentKey = 'non_existent_extension_' . uniqid('', true);
        $typo3Version = new Version('12.4.0');

        // TER API with authentication should return null for non-existent extensions
        // instead of throwing exception (graceful handling of 400 responses)
        $latestVersion = $this->terApiClient->getLatestVersion($nonExistentKey, $typo3Version);

        $this->assertNull($latestVersion, 'Non-existent extension should return null, not throw exception');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     */
    public function testMultipleExtensionsCompatibilityCheck(): void
    {
        $typo3Version = new Version('12.4.0');
        $results = [];

        $extensionsToTest = [
            'news' => $this->testExtensions['extensions']['georgringer/news']['extension_key'],
            'extension_builder' => $this->testExtensions['extensions']['friendsoftypo3/extension-builder']['extension_key'],
            'realurl' => $this->testExtensions['extensions']['dmitryd/typo3-realurl']['extension_key'],
        ];

        foreach ($extensionsToTest as $name => $extensionKey) {
            $startTime = microtime(true);

            try {
                $hasVersion = $this->cacheApiResponse(
                    "ter_multi_test_{$extensionKey}_12.4.0",
                    fn (): bool => $this->terApiClient->hasVersionFor($extensionKey, $typo3Version),
                );

                $responseTime = microtime(true) - $startTime;
                $results[$name] = [
                    'has_version' => $hasVersion,
                    'response_time' => $responseTime,
                    'success' => true,
                ];
            } catch (\Exception $e) {
                $results[$name] = [
                    'has_version' => false,
                    'response_time' => microtime(true) - $startTime,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Assert expected results based on actual TER API compatibility
        $this->assertTrue($results['news']['has_version'], 'News should be compatible with TYPO3 12.4 in TER');
        $this->assertTrue($results['extension_builder']['has_version'], 'Extension Builder (extensionbuilder_typo3) should be compatible with TYPO3 12.4 in TER');
        $this->assertFalse($results['realurl']['has_version'], 'RealURL should not be compatible with TYPO3 12.4');

        // Assert all requests completed reasonably fast
        foreach ($results as $name => $result) {
            $this->assertLessThan(15.0, $result['response_time'], "TER API request for {$name} took too long");
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     *
     * @throws \JsonException
     * @throws \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiException
     */
    public function testVersionCompatibilityLogic(): void
    {
        $extensionKey = 'news';

        // Test different TYPO3 versions based on actual TER API data
        $versionTests = [
            '8.7.0' => true,   // Legacy version that news supports
            '11.5.0' => true,  // Modern version supported in TER
            '12.4.0' => true,  // Modern version supported in TER
        ];

        foreach ($versionTests as $versionString => $expectedResult) {
            $typo3Version = new Version($versionString);

            $hasVersion = $this->cacheApiResponse(
                "ter_version_logic_{$extensionKey}_{$versionString}",
                apiCall: fn (): bool => $this->terApiClient->hasVersionFor($extensionKey, $typo3Version),
            );

            $this->assertEquals(
                $expectedResult,
                $hasVersion,
                "Extension {$extensionKey} compatibility with TYPO3 {$versionString} doesn't match expectation",
            );
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     */
    public function testTerApiErrorHandling(): void
    {
        // Test with malformed extension key - TER API handles this gracefully now
        $malformedKey = 'malformed/extension/key';
        $typo3Version = new Version('12.4.0');

        // With proper authentication, TER API should return false instead of throwing
        $hasVersion = $this->terApiClient->hasVersionFor($malformedKey, $typo3Version);
        $this->assertFalse($hasVersion, 'Malformed extension key should return false');
    }

    /**
     * @group performance
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     */
    public function testTerApiPerformance(): void
    {
        $extensions = ['news', 'extension_builder', 'bootstrap_package'];
        $typo3Version = new Version('12.4.0');
        $totalStartTime = microtime(true);

        foreach ($extensions as $extensionKey) {
            $startTime = microtime(true);

            try {
                $this->terApiClient->hasVersionFor($extensionKey, $typo3Version);
                $requestTime = microtime(true) - $startTime;

                $this->assertLessThan(
                    10.0,
                    $requestTime,
                    "TER API request for {$extensionKey} took too long: {$requestTime}s",
                );
            } catch (ExternalToolException $e) {
                // Some extensions might not exist, that's okay for performance testing
                if (!str_contains($e->getMessage(), 'not found')) {
                    throw $e;
                }
            }
        }

        $totalTime = microtime(true) - $totalStartTime;
        $this->assertLessThan(30.0, $totalTime, "Total TER API testing time exceeded limit: {$totalTime}s");
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient
     *
     * @throws TransportExceptionInterface
     */
    public function testTerApiResponseStructure(): void
    {
        // This test validates that the TER API returns expected data structure
        $extensionKey = 'news';

        // Use the TER token from the base class
        $headers = [];
        if ($this->getTerToken()) {
            $headers['Authorization'] = 'Bearer ' . $this->getTerToken();
        }

        // Make a direct API call to inspect response structure
        $response = $this->createHttpClientService()->request('GET', 'https://extensions.typo3.org/api/v1/extension/' . $extensionKey, ['headers' => $headers]);

        // Handle TER API server instability
        if (500 === $response->getStatusCode()) {
            $this->markTestSkipped('TER API server error (500) - skipping structure test');
        }

        $this->assertEquals(200, $response->getStatusCode());

        try {
            $data = $response->toArray();
        } catch (ClientExceptionInterface $e) {
            $this->fail('Failed to get TER API response: 4xx' . $e->getMessage());
        } catch (DecodingExceptionInterface $e) {
            $this->fail('Failed to decode TER API response.');
        } catch (RedirectionExceptionInterface $e) {
            $this->fail('Failed to get TER API response: 3xx. ' . $e->getMessage());
        } catch (ServerExceptionInterface $e) {
            $this->fail('Failed to get TER API response: 5xx. ') . $e->getMessage();
        } catch (TransportExceptionInterface $e) {
            $this->fail('Failed to get TER API response: Error at the transport level. ' . $e->getMessage());
        }

        // TER API returns an array with extension data as the first element
        $this->assertArrayHasKey(0, $data);

        $extensionData = $data[0];
        $this->assertArrayHasKey('key', $extensionData);
        $this->assertArrayHasKey('downloads', $extensionData);
        $this->assertArrayHasKey('verified', $extensionData);
        $this->assertArrayHasKey('version_count', $extensionData);
        $this->assertArrayHasKey('meta', $extensionData);
        $this->assertArrayHasKey('current_version', $extensionData);

        // Validate current version structure
        $currentVersion = $extensionData['current_version'];
        $this->assertArrayHasKey('number', $currentVersion); // TER uses 'number' not 'version'
        $this->assertArrayHasKey('typo3_versions', $currentVersion);
        $this->assertIsArray($currentVersion['typo3_versions']);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     */
    public function testSystemExtensionHandling(): void
    {
        // System extensions are not in TER, should handle gracefully
        $systemExtensionKey = 'core';
        $typo3Version = new Version('12.4.0');

        try {
            $hasVersion = $this->terApiClient->hasVersionFor($systemExtensionKey, $typo3Version);
            $this->assertFalse($hasVersion, 'System extensions should not be found in TER');
        } catch (ExternalToolException $e) {
            // This is also acceptable - system extensions are not in TER
            $this->assertStringContainsString('Failed to check TER', $e->getMessage());
        }
    }
}
