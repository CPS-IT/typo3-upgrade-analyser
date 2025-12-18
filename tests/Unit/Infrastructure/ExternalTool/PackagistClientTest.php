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
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ExternalToolException;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandlerInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(PackagistClient::class)]
class PackagistClientTest extends TestCase
{
    private PackagistClient $client;
    private MockObject&HttpClientServiceInterface $httpClient;
    private MockObject&LoggerInterface $logger;
    private MockObject&ComposerConstraintCheckerInterface $constraintChecker;
    private MockObject&RepositoryUrlHandlerInterface $urlHandler;
    private MockObject&ResponseInterface $response;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->constraintChecker = $this->createMock(ComposerConstraintCheckerInterface::class);
        $this->urlHandler = $this->createMock(RepositoryUrlHandlerInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);

        $this->client = new PackagistClient(
            $this->httpClient,
            $this->logger,
            $this->constraintChecker,
            $this->urlHandler,
        );
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
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                    '8.6.0' => [
                        'require' => [
                            'typo3/cms-core' => '^11.0',
                        ],
                    ],
                ],
            ],
        ];

        $this->response->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(200);

        $this->response->expects(self::once())
            ->method('toArray')
            ->willReturn($responseData);

        $this->httpClient->expects(self::once())
            ->method('get')
            ->with('https://packagist.org/packages/georgringer/news.json')
            ->willReturn($this->response);

        $this->constraintChecker->method('findTypo3Requirements')
            ->willReturnCallback(function (array $requirements): array {
                return $requirements; // Return the requirements as-is for this test
            });

        $this->constraintChecker->method('isConstraintCompatible')
            ->willReturnCallback(function (string $constraint, Version $targetVersion): bool {
                // Mock logic: ^12.0 is compatible with 12.4.0, ^11.0 is not
                return '^12.0' === $constraint;
            });

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
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                    '8.6.0' => [
                        'require' => [
                            'typo3/cms-core' => '^11.0',
                        ],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        $this->constraintChecker->method('findTypo3Requirements')
            ->willReturnCallback(function (): array {
                static $callCount = 0;

                return match (++$callCount) {
                    1 => ['typo3/cms-core' => '^12.0'],
                    2 => ['typo3/cms-core' => '^11.0'],
                    default => throw new \LogicException('Unexpected call')
                };
            });

        $this->constraintChecker->method('isConstraintCompatible')
            ->willReturn(false); // All constraints incompatible

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
                            'typo3/cms-core' => '*',
                        ],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        $this->constraintChecker->method('findTypo3Requirements')
            ->willReturn(['typo3/cms-core' => '*']);

        $this->constraintChecker->method('isConstraintCompatible')
            ->with('*', $typo3Version)
            ->willReturn(true);

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
                            'php' => '^8.1',
                        ],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        $this->constraintChecker->method('findTypo3Requirements')
            ->willReturn([]); // No TYPO3 requirements

        // Act
        $result = $this->client->hasVersionFor($packageName, $typo3Version);

        // Assert
        // Note: PackagistClient is conservative - assumes incompatible when no TYPO3 requirement found
        self::assertFalse($result);
    }

    public function testHasVersionForWith404Response(): void
    {
        // Arrange
        $packageName = 'vendor/non-existent';
        $typo3Version = new Version('12.4.0');

        $this->response->method('getStatusCode')->willReturn(404);
        $this->httpClient->method('get')->willReturn($this->response);

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
            ->method('get')
            ->willThrowException(new HttpClientException('Network error'));

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
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                    '8.7.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                    '8.6.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        $this->constraintChecker->method('findTypo3Requirements')
            ->willReturn(['typo3/cms-core' => '^12.0']);

        $this->constraintChecker->method('isConstraintCompatible')
            ->with('^12.0', $typo3Version)
            ->willReturn(true);

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
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        $this->constraintChecker->method('findTypo3Requirements')
            ->willReturn(['typo3/cms-core' => '^12.0']);

        $this->constraintChecker->method('isConstraintCompatible')
            ->with('^12.0', $typo3Version)
            ->willReturn(false);

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
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                    '1.1.0-dev' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                    '1.2.0' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        $this->constraintChecker->method('findTypo3Requirements')
            ->willReturn(['typo3/cms-core' => '^12.0']);

        $this->constraintChecker->method('isConstraintCompatible')
            ->with('^12.0', $typo3Version)
            ->willReturn(true);

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
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                    '1.1.0-dev' => [
                        'require' => [
                            'typo3/cms-core' => '^12.0',
                        ],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        $this->constraintChecker->method('findTypo3Requirements')
            ->willReturn(['typo3/cms-core' => '^12.0']);

        $this->constraintChecker->method('isConstraintCompatible')
            ->with('^12.0', $typo3Version)
            ->willReturn(true);

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
                'versions' => [],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

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
            'invalid' => 'structure',
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        // Act
        $result = $this->client->getLatestVersion($packageName, $typo3Version);

        // Assert
        self::assertNull($result);
    }

    public function testHandlesDifferentTypo3RequirementKeys(): void
    {
        $requirementKeys = [
            'typo3/cms-core',
            'typo3/cms',
            'typo3/minimal',
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
                                $requirementKey => '^12.0',
                            ],
                        ],
                    ],
                ],
            ];

            $this->response->method('getStatusCode')->willReturn(200);
            $this->response->method('toArray')->willReturn($responseData);
            $this->httpClient->method('get')->willReturn($this->response);

            $this->constraintChecker->method('findTypo3Requirements')
                ->willReturn([$requirementKey => '^12.0']);

            $this->constraintChecker->method('isConstraintCompatible')
                ->with('^12.0', $typo3Version)
                ->willReturn(true);

            // Act
            $result = $this->client->hasVersionFor($packageName, $typo3Version);

            // Assert
            self::assertTrue(
                $result,
                \sprintf('Failed to recognize TYPO3 requirement key "%s"', $requirementKey),
            );
        }
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
            // Arrange - create fresh mocks for each iteration
            $response = $this->createMock(ResponseInterface::class);
            $httpClient = $this->createMock(HttpClientServiceInterface::class);
            $constraintChecker = $this->createMock(ComposerConstraintCheckerInterface::class);
            $urlHandler = $this->createMock(RepositoryUrlHandlerInterface::class);
            $client = new PackagistClient($httpClient, $this->logger, $constraintChecker, $urlHandler);

            $packageName = 'vendor/test-package';
            $targetVersion = new Version($targetVersionString);

            $responseData = [
                'package' => [
                    'versions' => [
                        '1.0.0' => [
                            'require' => [
                                'typo3/cms-core' => $constraint,
                            ],
                        ],
                    ],
                ],
            ];

            $response->method('getStatusCode')->willReturn(200);
            $response->method('toArray')->willReturn($responseData);
            $httpClient->method('get')->willReturn($response);

            $constraintChecker->method('findTypo3Requirements')
                ->willReturn(['typo3/cms-core' => $constraint]);

            $constraintChecker->method('isConstraintCompatible')
                ->with($constraint, $targetVersion)
                ->willReturn($expected);

            // Act
            $result = $client->hasVersionFor($packageName, $targetVersion);

            // Assert
            self::assertEquals(
                $expected,
                $result,
                \sprintf('Failed for constraint "%s" with target %s', $constraint, $targetVersionString),
            );
        }
    }

    public function testExceptionContainsCorrectToolName(): void
    {
        // Arrange
        $packageName = 'georgringer/news';
        $typo3Version = new Version('12.4.0');

        $this->httpClient->method('get')
            ->willThrowException(new HttpClientException('Network error'));

        try {
            // Act
            $this->client->hasVersionFor($packageName, $typo3Version);
            self::fail('Expected ExternalToolException was not thrown');
        } catch (ExternalToolException $e) {
            // Assert
            self::assertEquals('packagist_api', $e->getToolName());
        }
    }

    public function testGetRepositoryUrlReturnsNormalizedUrl(): void
    {
        // Arrange
        $packageName = 'georgringer/news';
        $repositoryUrl = 'git@github.com:georgringer/news.git';
        $normalizedUrl = 'https://github.com/georgringer/news';

        $responseData = [
            'package' => [
                'repository' => $repositoryUrl,
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        $this->urlHandler->expects(self::once())
            ->method('normalizeUrl')
            ->with($repositoryUrl)
            ->willReturn($normalizedUrl);

        // Act
        $result = $this->client->getRepositoryUrl($packageName);

        // Assert
        self::assertEquals($normalizedUrl, $result);
    }

    public function testGetRepositoryUrlReturnsNullForMissingRepository(): void
    {
        // Arrange
        $packageName = 'vendor/no-repo';

        $responseData = [
            'package' => [
                'name' => $packageName,
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        // Act
        $result = $this->client->getRepositoryUrl($packageName);

        // Assert
        self::assertNull($result);
    }

    public function testGetRepositoryUrlWithNonStringRepository(): void
    {
        // Arrange
        $packageName = 'vendor/invalid-repo-type';

        $responseData = [
            'package' => [
                'repository' => ['type' => 'git'], // Array instead of string
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        // Act
        $result = $this->client->getRepositoryUrl($packageName);

        // Assert
        self::assertNull($result);
    }

    public function testGetRepositoryUrlWith404Response(): void
    {
        // Arrange
        $packageName = 'vendor/non-existent';

        $this->response->method('getStatusCode')->willReturn(404);
        $this->httpClient->method('get')->willReturn($this->response);

        // Act
        $result = $this->client->getRepositoryUrl($packageName);

        // Assert
        self::assertNull($result);
    }

    public function testGetRepositoryUrlWithHttpClientException(): void
    {
        // Arrange
        $packageName = 'vendor/error-package';

        $this->httpClient->method('get')
            ->willThrowException(new HttpClientException('Connection timeout'));

        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Failed to get repository URL from Packagist', [
                'package_name' => $packageName,
                'error' => 'Connection timeout',
            ]);

        // Act
        $result = $this->client->getRepositoryUrl($packageName);

        // Assert
        self::assertNull($result);
    }

    public function testGetLatestVersionWithHttpClientException(): void
    {
        // Arrange
        $packageName = 'vendor/network-error';
        $typo3Version = new Version('12.4.0');

        $this->httpClient->method('get')
            ->willThrowException(new HttpClientException('DNS resolution failed'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Packagist API request failed', [
                'package_name' => $packageName,
                'error' => 'DNS resolution failed',
            ]);

        $this->expectException(ExternalToolException::class);
        $this->expectExceptionMessage('Failed to get latest version from Packagist for package "vendor/network-error": DNS resolution failed');

        // Act
        $this->client->getLatestVersion($packageName, $typo3Version);
    }

    public function testIsVersionCompatibleWithTypo3CorePackage(): void
    {
        // Test TYPO3 core package version compatibility
        $packageName = 'typo3/cms-core';
        $typo3Version = new Version('12.4.5');

        $responseData = [
            'package' => [
                'versions' => [
                    '12.4.0' => [
                        'name' => 'typo3/cms-core',
                        'version' => '12.4.0',
                        'require' => [],
                    ],
                    '12.4.5' => [
                        'name' => 'typo3/cms-core',
                        'version' => '12.4.5',
                        'require' => [],
                    ],
                    '12.4.10' => [
                        'name' => 'typo3/cms-core',
                        'version' => '12.4.10',
                        'require' => [],
                    ],
                    '11.5.0' => [
                        'name' => 'typo3/cms-core',
                        'version' => '11.5.0',
                        'require' => [],
                    ],
                    'v12.4.0-dev' => [
                        'name' => 'typo3/cms-core',
                        'version' => 'v12.4.0-dev',
                        'require' => [],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($packageName, $typo3Version);

        // Assert
        self::assertTrue($result);

        // Test getting latest version
        $latestVersion = $this->client->getLatestVersion($packageName, $typo3Version);
        self::assertEquals('12.4.10', $latestVersion);
    }

    public function testIsVersionCompatibleWithTypo3CorePackageIncompatible(): void
    {
        // Test TYPO3 core package with incompatible versions
        $packageName = 'typo3/cms-core';
        $typo3Version = new Version('12.4.5');

        $responseData = [
            'package' => [
                'versions' => [
                    '11.5.0' => [
                        'name' => 'typo3/cms-core',
                        'version' => '11.5.0',
                        'require' => [],
                    ],
                    '13.0.0' => [
                        'name' => 'typo3/cms-core',
                        'version' => '13.0.0',
                        'require' => [],
                    ],
                    '12.3.0' => [ // Lower patch version
                        'name' => 'typo3/cms-core',
                        'version' => '12.3.0',
                        'require' => [],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($packageName, $typo3Version);

        // Assert
        self::assertFalse($result);
    }

    public function testIsVersionCompatibleWithVersionsWithoutRequirements(): void
    {
        // Arrange
        $packageName = 'vendor/no-require';
        $typo3Version = new Version('12.4.0');

        $responseData = [
            'package' => [
                'versions' => [
                    '1.0.0' => [
                        // No 'require' key
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($packageName, $typo3Version);

        // Assert
        self::assertFalse($result);
    }

    public function testVersionSortingInFindLatestCompatibleVersion(): void
    {
        // Test that versions are properly sorted
        $packageName = 'vendor/version-sorting';
        $typo3Version = new Version('12.4.0');

        $responseData = [
            'package' => [
                'versions' => [
                    '1.0.0' => [
                        'require' => ['typo3/cms-core' => '^12.0'],
                    ],
                    '1.10.0' => [ // Should be higher than 1.2.0 numerically
                        'require' => ['typo3/cms-core' => '^12.0'],
                    ],
                    '1.2.0' => [
                        'require' => ['typo3/cms-core' => '^12.0'],
                    ],
                    '2.0.0-beta1' => [ // Dev version, should be excluded from stable
                        'require' => ['typo3/cms-core' => '^12.0'],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        $this->constraintChecker->method('findTypo3Requirements')
            ->willReturn(['typo3/cms-core' => '^12.0']);

        $this->constraintChecker->method('isConstraintCompatible')
            ->with('^12.0', $typo3Version)
            ->willReturn(true);

        // Act
        $result = $this->client->getLatestVersion($packageName, $typo3Version);

        // Assert
        self::assertEquals('1.10.0', $result);
    }

    public function testIsCoreVersionCompatibleWithEdgeCases(): void
    {
        // Test edge cases for core version compatibility through integration
        $packageName = 'typo3/cms-core';
        $typo3Version = new Version('12.4.0');

        $responseData = [
            'package' => [
                'versions' => [
                    '12' => [ // Missing minor version
                        'name' => 'typo3/cms-core',
                        'version' => '12',
                        'require' => [],
                    ],
                    '' => [ // Empty version
                        'name' => 'typo3/cms-core',
                        'version' => '',
                        'require' => [],
                    ],
                    'v12.4.0' => [ // With 'v' prefix
                        'name' => 'typo3/cms-core',
                        'version' => 'v12.4.0',
                        'require' => [],
                    ],
                ],
            ],
        ];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->response->method('toArray')->willReturn($responseData);
        $this->httpClient->method('get')->willReturn($this->response);

        // Act
        $result = $this->client->hasVersionFor($packageName, $typo3Version);

        // Assert
        self::assertTrue($result); // Should find v12.4.0 as compatible
    }
}
