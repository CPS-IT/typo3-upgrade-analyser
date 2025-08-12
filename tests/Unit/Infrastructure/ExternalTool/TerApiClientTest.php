<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test case for the refactored TerApiClient.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient
 */
class TerApiClientTest extends TestCase
{
    private TerApiClient $client;
    private MockObject&HttpClientServiceInterface $httpClientService;
    private MockObject&LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->httpClientService = $this->createMock(HttpClientServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->client = new TerApiClient($this->httpClientService, $this->logger);
    }

    public function testHasVersionForWithCompatibleVersion(): void
    {
        $extensionKey = 'news';
        $typo3Version = new Version('12.4.0');

        $extensionData = [['key' => 'news', 'name' => 'News']];
        $versionsData = [
            [
                [
                    'number' => '8.7.0',
                    'typo3_versions' => [11, 12],
                ],
            ],
        ];

        $this->mockSuccessfulApiCalls($extensionKey, $extensionData, $versionsData);

        $result = $this->client->hasVersionFor($extensionKey, $typo3Version);

        self::assertTrue($result);
    }

    public function testHasVersionForWithIncompatibleVersion(): void
    {
        $extensionKey = 'news';
        $typo3Version = new Version('13.0.0');

        $extensionData = [['key' => 'news', 'name' => 'News']];
        $versionsData = [
            [
                [
                    'number' => '8.7.0',
                    'typo3_versions' => [11, 12],
                ],
            ],
        ];

        $this->mockSuccessfulApiCalls($extensionKey, $extensionData, $versionsData);

        $result = $this->client->hasVersionFor($extensionKey, $typo3Version);

        self::assertFalse($result);
    }

    public function testHasVersionForWithNonExistentExtension(): void
    {
        $extensionKey = 'non_existent';
        $typo3Version = new Version('12.4.0');

        $this->mockExtensionNotFound($extensionKey);

        $result = $this->client->hasVersionFor($extensionKey, $typo3Version);

        self::assertFalse($result);
    }

    public function testHasVersionForWithApiException(): void
    {
        $extensionKey = 'news';
        $typo3Version = new Version('12.4.0');

        $this->httpClientService
            ->expects(self::once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Network error'));

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with('TER API request failed', self::isType('array'));

        $this->expectException(TerApiException::class);
        $this->expectExceptionMessage('Failed to check TER for extension "news"');

        $this->client->hasVersionFor($extensionKey, $typo3Version);
    }

    public function testGetLatestVersionWithMultipleVersions(): void
    {
        $extensionKey = 'news';
        $typo3Version = new Version('12.4.0');

        $extensionData = [['key' => 'news', 'name' => 'News']];
        $versionsData = [
            [
                [
                    'number' => '8.5.0',
                    'typo3_versions' => [12],
                ],
                [
                    'number' => '8.7.0',
                    'typo3_versions' => [12],
                ],
                [
                    'number' => '8.6.0',
                    'typo3_versions' => [12],
                ],
            ],
        ];

        $this->mockSuccessfulApiCalls($extensionKey, $extensionData, $versionsData);

        $result = $this->client->getLatestVersion($extensionKey, $typo3Version);

        self::assertSame('8.7.0', $result);
    }

    public function testGetLatestVersionWithNoCompatibleVersions(): void
    {
        $extensionKey = 'news';
        $typo3Version = new Version('14.0.0');

        $extensionData = [['key' => 'news', 'name' => 'News']];
        $versionsData = [
            [
                [
                    'number' => '8.7.0',
                    'typo3_versions' => [11, 12],
                ],
            ],
        ];

        $this->mockSuccessfulApiCalls($extensionKey, $extensionData, $versionsData);

        $result = $this->client->getLatestVersion($extensionKey, $typo3Version);

        self::assertNull($result);
    }

    public function testGetLatestVersionWithNonExistentExtension(): void
    {
        $extensionKey = 'non_existent';
        $typo3Version = new Version('12.4.0');

        $this->mockExtensionNotFound($extensionKey);

        $result = $this->client->getLatestVersion($extensionKey, $typo3Version);

        self::assertNull($result);
    }

    public function testGetLatestVersionWithVersionsApiFailure(): void
    {
        $extensionKey = 'news';
        $typo3Version = new Version('12.4.0');

        $extensionData = [['key' => 'news', 'name' => 'News']];

        $this->mockApiCallsWithVersionsFailure($extensionKey, $extensionData);

        $result = $this->client->getLatestVersion($extensionKey, $typo3Version);

        self::assertNull($result);
    }

    public function testHasVersionForWithUniversalCompatibility(): void
    {
        $extensionKey = 'universal_ext';
        $typo3Version = new Version('12.4.0');

        $extensionData = [['key' => 'universal_ext', 'name' => 'Universal Extension']];
        $versionsData = [
            [
                [
                    'number' => '1.0.0',
                    'typo3_versions' => ['*'],
                ],
            ],
        ];

        $this->mockSuccessfulApiCalls($extensionKey, $extensionData, $versionsData);

        $result = $this->client->hasVersionFor($extensionKey, $typo3Version);

        self::assertTrue($result);
    }

    public function testVersionCompatibilityWithMixedVersionTypes(): void
    {
        $extensionKey = 'mixed_ext';
        $typo3Version = new Version('12.4.0');

        $extensionData = [['key' => 'mixed_ext', 'name' => 'Mixed Extension']];
        $versionsData = [
            [
                [
                    'number' => '1.0.0',
                    'typo3_versions' => [11, '12.*', '13.0'],
                ],
            ],
        ];

        $this->mockSuccessfulApiCalls($extensionKey, $extensionData, $versionsData);

        $result = $this->client->hasVersionFor($extensionKey, $typo3Version);

        self::assertTrue($result);
    }

    private function mockSuccessfulApiCalls(string $extensionKey, array $extensionData, array $versionsData): void
    {
        $extensionResponse = $this->createMock(ResponseInterface::class);
        $versionsResponse = $this->createMock(ResponseInterface::class);

        $extensionResponse->method('getStatusCode')->willReturn(200);
        $extensionResponse->method('toArray')->willReturn($extensionData);

        $versionsResponse->method('getStatusCode')->willReturn(200);
        $versionsResponse->method('toArray')->willReturn($versionsData);

        $this->httpClientService
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(
                fn (string $method, string $url): MockObject => match ($url) {
                    "https://extensions.typo3.org/api/v1/extension/{$extensionKey}" => $extensionResponse,
                    "https://extensions.typo3.org/api/v1/extension/{$extensionKey}/versions" => $versionsResponse,
                    default => throw new \InvalidArgumentException('Unexpected URL: ' . $url)
                },
            );
    }

    private function mockExtensionNotFound(string $extensionKey): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);

        $this->httpClientService
            ->expects(self::once())
            ->method('request')
            ->with('GET', "https://extensions.typo3.org/api/v1/extension/{$extensionKey}")
            ->willReturn($response);
    }

    private function mockApiCallsWithVersionsFailure(string $extensionKey, array $extensionData): void
    {
        $extensionResponse = $this->createMock(ResponseInterface::class);
        $versionsResponse = $this->createMock(ResponseInterface::class);

        $extensionResponse->method('getStatusCode')->willReturn(200);
        $extensionResponse->method('toArray')->willReturn($extensionData);

        $versionsResponse->method('getStatusCode')->willReturn(500);

        $this->httpClientService
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(
                fn (string $method, string $url): MockObject => match ($url) {
                    "https://extensions.typo3.org/api/v1/extension/{$extensionKey}" => $extensionResponse,
                    "https://extensions.typo3.org/api/v1/extension/{$extensionKey}/versions" => $versionsResponse,
                    default => throw new \InvalidArgumentException('Unexpected URL: ' . $url)
                },
            );
    }
}
