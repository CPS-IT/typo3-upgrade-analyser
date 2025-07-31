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
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ExternalToolException;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Test case for the PackagistClient
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient
 */
class PackagistClientTest extends TestCase
{
    private PackagistClient $client;
    private MockObject&HttpClientInterface $httpClient;
    private MockObject&LoggerInterface $logger;
    private MockObject&ResponseInterface $response;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        
        $this->client = new PackagistClient($this->httpClient, $this->logger);
    }

    public function testHasVersionForWithCompatibleVersion(): void
    {
        // Arrange
        $packageName = 'georgringer/news';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'package' => [
                'versions' => [
                    '8.7.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ],
                    '8.6.0' => [
                        'require' => [
                            'typo3/cms-core' => '^11.0'
                        ]
                    ]
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
            ->with('GET', 'https://packagist.org/packages/georgringer/news.json')
            ->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($packageName, $typo3Version);

        // Assert
        self::assertTrue($result);
    }

    public function testHasVersionForWithIncompatibleVersion(): void
    {
        // Arrange
        $packageName = 'georgringer/news';
        $typo3Version = new Version('13.0.0');
        
        $responseData = [
            'package' => [
                'versions' => [
                    '8.7.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ],
                    '8.6.0' => [
                        'require' => [
                            'typo3/cms-core' => '^11.0'
                        ]
                    ]
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($packageName, $typo3Version);

        // Assert
        self::assertFalse($result);
    }

    public function testHasVersionForWithUniversalCompatibility(): void
    {
        // Arrange
        $packageName = 'vendor/universal-package';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'package' => [
                'versions' => [
                    '1.0.0' => [
                        'require' => [
                            'typo3/cms-core' => '*'
                        ]
                    ]
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($packageName, $typo3Version);

        // Assert
        self::assertTrue($result);
    }

    public function testHasVersionForWithNoTypo3Requirement(): void
    {
        // Arrange
        $packageName = 'vendor/generic-package';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'package' => [
                'versions' => [
                    '1.0.0' => [
                        'require' => [
                            'php' => '^8.1'
                        ]
                    ]
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($packageName, $typo3Version);

        // Assert
        self::assertTrue($result); // Should assume compatible if no TYPO3 requirement
    }

    public function testHasVersionForWith404Response(): void
    {
        // Arrange
        $packageName = 'vendor/non-existent';
        $typo3Version = new Version('12.4.0');

        $this->response->method('getStatusCode')->willReturn(404);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($packageName, $typo3Version);

        // Assert
        self::assertFalse($result);
    }

    public function testHasVersionForWithNetworkError(): void
    {
        // Arrange
        $packageName = 'georgringer/news';
        $typo3Version = new Version('12.4.0');
        
        $this->httpClient->expects(self::once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Network error'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Packagist API request failed', self::isType('array'));

        // Assert
        $this->expectException(ExternalToolException::class);
        $this->expectExceptionMessage('Failed to check Packagist for package "georgringer/news"');

        // Act
        $this->client->hasVersionFor($packageName, $typo3Version);
    }

    public function testGetLatestVersionWithMultipleVersions(): void
    {
        // Arrange
        $packageName = 'georgringer/news';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'package' => [
                'versions' => [
                    '8.5.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ],
                    '8.7.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ],
                    '8.6.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ]
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($packageName, $typo3Version);

        // Assert
        self::assertEquals('8.7.0', $result);
    }

    public function testGetLatestVersionWithNoCompatibleVersions(): void
    {
        // Arrange
        $packageName = 'georgringer/news';
        $typo3Version = new Version('13.0.0');
        
        $responseData = [
            'package' => [
                'versions' => [
                    '8.7.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ]
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($packageName, $typo3Version);

        // Assert
        self::assertNull($result);
    }

    public function testGetLatestVersionPreferringStableVersions(): void
    {
        // Arrange
        $packageName = 'vendor/test-package';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'package' => [
                'versions' => [
                    '1.0.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ],
                    '1.1.0-dev' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ],
                    '1.2.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ]
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($packageName, $typo3Version);

        // Assert
        self::assertEquals('1.2.0', $result); // Should prefer stable over dev versions
    }

    public function testGetLatestVersionFallbackToDevVersions(): void
    {
        // Arrange
        $packageName = 'vendor/dev-only-package';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'package' => [
                'versions' => [
                    '1.0.0-dev' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ],
                    '1.1.0-dev' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0'
                        ]
                    ]
                ]
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($packageName, $typo3Version);

        // Assert
        self::assertEquals('1.1.0-dev', $result); // Should fall back to dev versions
    }

    public function testGetLatestVersionWithEmptyVersionsArray(): void
    {
        // Arrange
        $packageName = 'vendor/empty-package';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'package' => [
                'versions' => []
            ]
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($packageName, $typo3Version);

        // Assert
        self::assertNull($result);
    }

    public function testGetLatestVersionWithMalformedResponse(): void
    {
        // Arrange
        $packageName = 'vendor/malformed-package';
        $typo3Version = new Version('12.4.0');
        
        $responseData = [
            'invalid' => 'structure'
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('request')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($packageName, $typo3Version);

        // Assert
        self::assertNull($result);
    }

    public function testConstraintCompatibilityWithDifferentPatterns(): void
    {
        $testCases = [
            // [constraint, target_version, expected_result]
            ['^12.0', '12.4.0', true],
            ['^11.0', '12.4.0', false],
            ['*', '12.4.0', true],
            ['^12.4', '12.4.0', true],
            ['^13.0', '12.4.0', false],
        ];

        foreach ($testCases as [$constraint, $targetVersionString, $expected]) {
            // Arrange
            $packageName = 'vendor/test-package';
            $targetVersion = new Version($targetVersionString);
            
            $responseData = [
                'package' => [
                    'versions' => [
                        '1.0.0' => [
                            'require' => [
                                'typo3/cms-core' => $constraint
                            ]
                        ]
                    ]
                ]
            ];

            $this->response->method('getStatusCode')->willReturn(200);
            $this->response->method('toArray')->willReturn($responseData);
            $this->httpClient->method('request')->willReturn($this->response);

            // Act
            $result = $this->client->hasVersionFor($packageName, $targetVersion);

            // Assert
            self::assertEquals($expected, $result, 
                sprintf('Failed for constraint "%s" with target %s', $constraint, $targetVersionString));
        }
    }

    public function testExceptionContainsCorrectToolName(): void
    {
        // Arrange
        $packageName = 'georgringer/news';
        $typo3Version = new Version('12.4.0');
        
        $this->httpClient->method('request')
            ->willThrowException(new \RuntimeException('Network error'));

        try {
            // Act
            $this->client->hasVersionFor($packageName, $typo3Version);
            self::fail('Expected ExternalToolException was not thrown');
        } catch (ExternalToolException $e) {
            // Assert
            self::assertEquals('packagist_api', $e->getToolName());
        }
    }

    public function testHandlesDifferentTypo3RequirementKeys(): void
    {
        $requirementKeys = [
            'typo3/cms-core',
            'typo3/cms',
            'typo3/minimal'
        ];

        foreach ($requirementKeys as $requirementKey) {
            // Arrange
            $packageName = 'vendor/test-package';
            $typo3Version = new Version('12.4.0');
            
            $responseData = [
                'package' => [
                    'versions' => [
                        '1.0.0' => [
                            'require' => [
                                $requirementKey => '^12.0'
                            ]
                        ]
                    ]
                ]
            ];

            $this->response->method('getStatusCode')->willReturn(200);
            $this->response->method('toArray')->willReturn($responseData);
            $this->httpClient->method('request')->willReturn($this->response);

            // Act
            $result = $this->client->hasVersionFor($packageName, $typo3Version);

            // Assert
            self::assertTrue($result, 
                sprintf('Failed to recognize TYPO3 requirement key "%s"', $requirementKey));
        }
    }
}