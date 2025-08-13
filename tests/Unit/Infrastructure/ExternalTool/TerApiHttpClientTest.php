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

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiHttpClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;


#[CoversClass(TerApiHttpClient::class)]
class TerApiHttpClientTest extends TestCase
{
    private HttpClientServiceInterface&\PHPUnit\Framework\MockObject\MockObject $mockHttpClient;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $mockLogger;
    private TerApiHttpClient $httpClient;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClientServiceInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->httpClient = new TerApiHttpClient($this->mockHttpClient, $this->mockLogger);
    }

    public function testGetExtensionDataWithSuccessfulResponse(): void
    {
        $expectedData = [['key' => 'news', 'name' => 'News']];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn($expectedData);

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://extensions.typo3.org/api/v1/extension/news', ['headers' => []])
            ->willReturn($mockResponse);

        $result = $this->httpClient->getExtensionData('news');

        self::assertSame($expectedData, $result);
    }

    public function testGetExtensionDataWithServerError(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(500);

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('TER API server error (500)', ['status_code' => 500]);

        $result = $this->httpClient->getExtensionData('news');

        self::assertNull($result);
    }

    public function testGetExtensionDataWithNotFoundError(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(400);

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $this->mockLogger
            ->expects(self::never())
            ->method('warning');

        $result = $this->httpClient->getExtensionData('non_existent');

        self::assertNull($result);
    }

    public function testGetVersionsDataWithSuccessfulResponse(): void
    {
        $expectedData = [['number' => '1.0.0', 'typo3_versions' => [12]]];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn($expectedData);

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://extensions.typo3.org/api/v1/extension/news/versions', ['headers' => []])
            ->willReturn($mockResponse);

        $result = $this->httpClient->getVersionsData('news');

        self::assertSame($expectedData, $result);
    }

    public function testGetExtensionWithVersionsWithBothDataAvailable(): void
    {
        $extensionData = [['key' => 'news', 'name' => 'News']];
        $versionsData = [['number' => '1.0.0', 'typo3_versions' => [12]]];

        $extensionResponse = $this->createMock(ResponseInterface::class);
        $extensionResponse->method('getStatusCode')->willReturn(200);
        $extensionResponse->method('toArray')->willReturn($extensionData);

        $versionsResponse = $this->createMock(ResponseInterface::class);
        $versionsResponse->method('getStatusCode')->willReturn(200);
        $versionsResponse->method('toArray')->willReturn($versionsData);

        $this->mockHttpClient
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturnMap([
                ['GET', 'https://extensions.typo3.org/api/v1/extension/news', ['headers' => []], $extensionResponse],
                ['GET', 'https://extensions.typo3.org/api/v1/extension/news/versions', ['headers' => []], $versionsResponse],
            ]);

        $result = $this->httpClient->getExtensionWithVersions('news');

        self::assertSame($extensionData, $result['extension']);
        self::assertSame($versionsData, $result['versions']);
    }

    public function testGetExtensionWithVersionsWithExtensionNotFound(): void
    {
        $extensionResponse = $this->createMock(ResponseInterface::class);
        $extensionResponse->method('getStatusCode')->willReturn(400);

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://extensions.typo3.org/api/v1/extension/non_existent', ['headers' => []])
            ->willReturn($extensionResponse);

        $result = $this->httpClient->getExtensionWithVersions('non_existent');

        self::assertNull($result['extension']);
        self::assertNull($result['versions']);
    }

    public function testGetExtensionWithVersionsWithVersionsFailure(): void
    {
        $extensionData = [['key' => 'news', 'name' => 'News']];

        $extensionResponse = $this->createMock(ResponseInterface::class);
        $extensionResponse->method('getStatusCode')->willReturn(200);
        $extensionResponse->method('toArray')->willReturn($extensionData);

        $versionsResponse = $this->createMock(ResponseInterface::class);
        $versionsResponse->method('getStatusCode')->willReturn(500);

        $this->mockHttpClient
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturnMap([
                ['GET', 'https://extensions.typo3.org/api/v1/extension/news', ['headers' => []], $extensionResponse],
                ['GET', 'https://extensions.typo3.org/api/v1/extension/news/versions', ['headers' => []], $versionsResponse],
            ]);

        $this->mockLogger
            ->expects(self::once())
            ->method('warning')
            ->with('TER API server error (500)', ['status_code' => 500]);

        $result = $this->httpClient->getExtensionWithVersions('news');

        self::assertSame($extensionData, $result['extension']);
        self::assertNull($result['versions']);
    }

    public function testConstructorWithTerToken(): void
    {
        $httpClientWithToken = new TerApiHttpClient($this->mockHttpClient, $this->mockLogger, 'test-token');

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn([]);

        $this->mockHttpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://extensions.typo3.org/api/v1/extension/news', [
                'headers' => ['Authorization' => 'Bearer test-token'],
            ])
            ->willReturn($mockResponse);

        $httpClientWithToken->getExtensionData('news');
    }
}
