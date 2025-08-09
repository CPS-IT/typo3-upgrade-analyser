<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Http;

use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientService
 */
class HttpClientServiceTest extends TestCase
{
    private HttpClientService $subject;
    private \PHPUnit\Framework\MockObject\MockObject $httpClient;
    private \PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = new HttpClientService($this->httpClient, $this->logger);
    }

    public function testImplementsHttpClientServiceInterface(): void
    {
        self::assertInstanceOf(HttpClientServiceInterface::class, $this->subject);
    }

    public function testRequestSuccessful(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://example.com', ['timeout' => 30])
            ->willReturn($response);

        $this->logger
            ->expects(self::exactly(2))
            ->method('debug');

        $result = $this->subject->request('GET', 'https://example.com', ['timeout' => 30]);

        self::assertSame($response, $result);
    }

    public function testRequestLogsDebugInformation(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $this->logger
            ->expects(self::exactly(2))
            ->method('debug');

        $this->subject->request('POST', 'https://api.example.com', [
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    public function testRequestLogsResponseInformation(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);

        $this->httpClient->method('request')->willReturn($response);

        $this->logger
            ->expects(self::exactly(2))
            ->method('debug');

        $this->subject->request('POST', 'https://api.example.com');
    }

    public function testRequestThrowsHttpClientExceptionOnFailure(): void
    {
        $transportException = new class('Network error') extends \Exception implements TransportExceptionInterface {};

        $this->httpClient
            ->method('request')
            ->willThrowException($transportException);

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with('HTTP request failed', [
                'method' => 'GET',
                'url' => 'https://example.com',
                'error' => 'Network error',
            ]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('HTTP GET request to https://example.com failed: Network error');

        $this->subject->request('GET', 'https://example.com');
    }

    public function testGetMethodCallsRequestWithGetMethod(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://example.com', ['timeout' => 10])
            ->willReturn($response);

        $result = $this->subject->get('https://example.com', ['timeout' => 10]);

        self::assertSame($response, $result);
    }

    public function testPostMethodCallsRequestWithPostMethod(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('POST', 'https://api.example.com', ['body' => 'data'])
            ->willReturn($response);

        $result = $this->subject->post('https://api.example.com', ['body' => 'data']);

        self::assertSame($response, $result);
    }

    public function testMakeAuthenticatedRequestAddsAuthorizationHeader(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $expectedOptions = [
            'headers' => [
                'Authorization' => 'Bearer test-token',
                'Content-Type' => 'application/json',
            ],
        ];

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com', $expectedOptions)
            ->willReturn($response);

        $result = $this->subject->makeAuthenticatedRequest(
            'GET',
            'https://api.example.com',
            'test-token',
            ['headers' => ['Content-Type' => 'application/json']],
        );

        self::assertSame($response, $result);
    }

    public function testMakeAuthenticatedRequestWithoutToken(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $originalOptions = ['headers' => ['Content-Type' => 'application/json']];

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com', $originalOptions)
            ->willReturn($response);

        $result = $this->subject->makeAuthenticatedRequest(
            'GET',
            'https://api.example.com',
            null,
            $originalOptions,
        );

        self::assertSame($response, $result);
    }

    public function testMakeRateLimitedRequestSuccessful(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($response);

        $result = $this->subject->makeRateLimitedRequest('GET', 'https://api.example.com');

        self::assertSame($response, $result);
    }

    public function testMakeRateLimitedRequestRetriesOnRateLimit(): void
    {
        $rateLimitResponse = $this->createMock(ResponseInterface::class);
        $rateLimitResponse->method('getStatusCode')->willReturn(429);

        $successResponse = $this->createMock(ResponseInterface::class);
        $successResponse->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($rateLimitResponse, $successResponse);

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('Rate limited, retrying', self::callback(function ($context) {
                return 1 === $context['attempt'] && 1 === $context['delay'];
            }));

        $result = $this->subject->makeRateLimitedRequest('GET', 'https://api.example.com', [], 2, 1);

        self::assertSame($successResponse, $result);
    }

    public function testMakeRateLimitedRequestExceedsMaxRetries(): void
    {
        $rateLimitResponse = $this->createMock(ResponseInterface::class);
        $rateLimitResponse->method('getStatusCode')->willReturn(429);

        $this->httpClient
            ->method('request')
            ->willReturn($rateLimitResponse);

        $this->logger
            ->expects(self::exactly(2))
            ->method('warning');

        $result = $this->subject->makeRateLimitedRequest('GET', 'https://api.example.com', [], 2, 1);

        // Should return the last rate-limited response
        self::assertSame($rateLimitResponse, $result);
    }

    public function testMakeRateLimitedRequestRetriesOnHttpException(): void
    {
        $exception = new HttpClientException('HTTP 429 Too Many Requests', 429);
        $successResponse = $this->createMock(ResponseInterface::class);
        $successResponse->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(function () use (&$exception, $successResponse) {
                if ($exception) {
                    $temp = $exception;
                    $exception = null;
                    throw $temp;
                }

                return $successResponse;
            });

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('HTTP error, retrying', self::isType('array'));

        $result = $this->subject->makeRateLimitedRequest('GET', 'https://api.example.com', [], 2, 1);

        self::assertSame($successResponse, $result);
    }

    public function testMakeRateLimitedRequestThrowsOnNonRetriableException(): void
    {
        $exception = new HttpClientException('HTTP 404 Not Found', 404);

        $this->httpClient
            ->method('request')
            ->willThrowException($exception);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('HTTP 404 Not Found');

        $this->subject->makeRateLimitedRequest('GET', 'https://api.example.com');
    }

    public function testSanitizeOptionsRedactsAuthorizationHeaders(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $this->logger
            ->expects(self::exactly(2))
            ->method('debug');

        $this->subject->request('GET', 'https://api.example.com', [
            'headers' => [
                'Authorization' => 'Bearer secret-token',
                'X-API-Token' => 'secret-key',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function testSanitizeOptionsRedactsRequestBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $this->logger
            ->expects(self::exactly(2))
            ->method('debug');

        $this->subject->request('POST', 'https://api.example.com', [
            'body' => 'sensitive data',
            'json' => ['password' => 'secret'],
        ]);
    }

    public function testMakeRateLimitedRequestWithExponentialBackoff(): void
    {
        $rateLimitResponse = $this->createMock(ResponseInterface::class);
        $rateLimitResponse->method('getStatusCode')->willReturn(429);

        $successResponse = $this->createMock(ResponseInterface::class);
        $successResponse->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects(self::exactly(3))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $rateLimitResponse,
                $rateLimitResponse,
                $successResponse,
            );

        $this->logger
            ->expects(self::exactly(2))
            ->method('warning');

        $result = $this->subject->makeRateLimitedRequest('GET', 'https://api.example.com', [], 3, 2);

        self::assertSame($successResponse, $result);
    }
}
