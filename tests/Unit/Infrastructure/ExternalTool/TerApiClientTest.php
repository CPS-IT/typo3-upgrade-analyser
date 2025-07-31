<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ExternalToolException;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Test case for the TerApiClient
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient
 */
class TerApiClientTest extends TestCase
{
    private TerApiClient $client;
    private MockObject&HttpClientInterface $httpClient;
    private MockObject&LoggerInterface $logger;
    private MockObject&ResponseInterface $response;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        
        $this->client = new TerApiClient($this->httpClient, $this->logger);
    }

    public function testHasVersionForWithCompatibleVersion(): void
    {
        // Arrange
        $extensionKey = 'news';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'versions' => [
                [
                    'version' => '8.7.0',
                    'typo3_versions' => ['12.0', '12.*']
                ],
                [
                    'version' => '8.6.0',
                    'typo3_versions' => ['11.0', '11.*']
                ]
            ]
        ];

        $this->response->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(200);
            
        $this->response->expects(self::once())
            ->method('toArray')
            ->willReturn($responseData);

        $this->httpClient->expects(self::once())
            ->method('request')
            ->with('GET', 'https://extensions.typo3.org/api/v1/extension/news')
            ->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($extensionKey, $typo3Version);

        // Assert
        self::assertTrue($result);
    }

    public function testHasVersionForWithIncompatibleVersion(): void
    {
        // Arrange
        $extensionKey = 'news';
        $typo3Version = new Version('13.0.0');
        
        $responseData = [
            'versions' => [
                [
                    'version' => '8.7.0',
                    'typo3_versions' => ['12.0', '12.*']
                ],
                [
                    'version' => '8.6.0',
                    'typo3_versions' => ['11.0', '11.*']
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($extensionKey, $typo3Version);

        // Assert
        self::assertFalse($result);
    }

    public function testHasVersionForWithWildcardSupport(): void
    {
        // Arrange
        $extensionKey = 'universal_ext';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'versions' => [
                [
                    'version' => '1.0.0',
                    'typo3_versions' => ['*']
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($extensionKey, $typo3Version);

        // Assert
        self::assertTrue($result);
    }

    public function testHasVersionForWith404Response(): void
    {
        // Arrange
        $extensionKey = 'non_existent';
        $typo3Version = new Version('12.4.0');

        $this->response->method('getStatusCode')->willReturn(404);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($extensionKey, $typo3Version);

        // Assert
        self::assertFalse($result);
    }

    public function testHasVersionForWithNetworkError(): void
    {
        // Arrange
        $extensionKey = 'news';
        $typo3Version = new Version('12.4.0');
        
        $this->httpClient->expects(self::once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Network error'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with('TER API request failed', self::isType('array'));

        // Assert
        $this->expectException(ExternalToolException::class);
        $this->expectExceptionMessage('Failed to check TER for extension "news"');

        // Act
        $this->client->hasVersionFor($extensionKey, $typo3Version);
    }

    public function testGetLatestVersionWithMultipleVersions(): void
    {
        // Arrange
        $extensionKey = 'news';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'versions' => [
                [
                    'version' => '8.5.0',
                    'typo3_versions' => ['12.0', '12.*']
                ],
                [
                    'version' => '8.7.0',
                    'typo3_versions' => ['12.0', '12.*']
                ],
                [
                    'version' => '8.6.0',
                    'typo3_versions' => ['12.0', '12.*']
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($extensionKey, $typo3Version);

        // Assert
        self::assertEquals('8.7.0', $result);
    }

    public function testGetLatestVersionWithNoCompatibleVersions(): void
    {
        // Arrange
        $extensionKey = 'news';
        $typo3Version = new Version('13.0.0');
        
        $responseData = [
            'versions' => [
                [
                    'version' => '8.7.0',
                    'typo3_versions' => ['12.0', '12.*']
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($extensionKey, $typo3Version);

        // Assert
        self::assertNull($result);
    }

    public function testGetLatestVersionWithEmptyVersionsArray(): void
    {
        // Arrange
        $extensionKey = 'empty_ext';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'versions' => []
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($extensionKey, $typo3Version);

        // Assert
        self::assertNull($result);
    }

    public function testGetLatestVersionWithMalformedResponse(): void
    {
        // Arrange
        $extensionKey = 'malformed_ext';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'invalid' => 'structure'
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($extensionKey, $typo3Version);

        // Assert
        self::assertNull($result);
    }

    public function testVersionCompatibilityWithDifferentPatterns(): void
    {
        $testCases = [
            // [typo3_versions_array, target_version, expected_result]
            [['12.0'], '12.4.0', true],
            [['12.*'], '12.4.0', true],
            [['*'], '12.4.0', true],
            [['11.0'], '12.4.0', false],
            [['11.*'], '12.4.0', false],
            [['12.0', '13.0'], '12.4.0', true],
            [['13.0'], '12.4.0', false],
        ];

        foreach ($testCases as [$typo3Versions, $targetVersionString, $expected]) {
            // Arrange - create fresh mocks for each iteration
            $response = $this->createMock(ResponseInterface::class);
            $httpClient = $this->createMock(HttpClientInterface::class);
            $client = new TerApiClient($httpClient, $this->logger);
            
            $extensionKey = 'test_ext';
            $targetVersion = new Version($targetVersionString);
            
            $responseData = [
                'versions' => [
                    [
                        'version' => '1.0.0',
                        'typo3_versions' => $typo3Versions
                    ]
                ]
            ];

            $response->method('getStatusCode')->willReturn(200);
            $response->method('toArray')->willReturn($responseData);
            $httpClient->method('request')->willReturn($response);

            // Act
            $result = $client->hasVersionFor($extensionKey, $targetVersion);

            // Assert
            self::assertEquals($expected, $result, 
                sprintf('Failed for TYPO3 versions %s with target %s', 
                    implode(',', $typo3Versions), $targetVersionString));
        }
    }

    public function testExceptionContainsCorrectToolName(): void
    {
        // Arrange
        $extensionKey = 'news';
        $typo3Version = new Version('12.4.0');
        
        $this->httpClient->method('request')
            ->willThrowException(new \RuntimeException('Network error'));

        try {
            // Act
            $this->client->hasVersionFor($extensionKey, $typo3Version);
            self::fail('Expected ExternalToolException was not thrown');
        } catch (ExternalToolException $e) {
            // Assert
            self::assertEquals('ter_api', $e->getToolName());
        }
    }
}