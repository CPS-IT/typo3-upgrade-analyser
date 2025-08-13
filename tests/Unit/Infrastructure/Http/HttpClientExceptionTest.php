<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Http;

use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpClientException::class)]
class HttpClientExceptionTest extends TestCase
{
    public function testExceptionExtendsBaseException(): void
    {
        $exception = new HttpClientException('Test message');

        self::assertInstanceOf(\Exception::class, $exception);
    }

    public function testConstructorWithMessage(): void
    {
        $message = 'HTTP request failed';
        $exception = new HttpClientException($message);

        self::assertSame($message, $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }

    public function testConstructorWithMessageAndCode(): void
    {
        $message = 'HTTP 404 error';
        $code = 404;
        $exception = new HttpClientException($message, $code);

        self::assertSame($message, $exception->getMessage());
        self::assertSame($code, $exception->getCode());
    }

    public function testConstructorWithPreviousException(): void
    {
        $message = 'HTTP client error';
        $code = 500;
        $previous = new \RuntimeException('Connection failed');

        $exception = new HttpClientException($message, $code, $previous);

        self::assertSame($message, $exception->getMessage());
        self::assertSame($code, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testCanBeThrown(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('Test HTTP error');
        $this->expectExceptionCode(503);

        throw new HttpClientException('Test HTTP error', 503);
    }

    public function testExceptionChaining(): void
    {
        $rootCause = new \InvalidArgumentException('Invalid URL');
        $httpException = new HttpClientException('Request failed', 400, $rootCause);

        try {
            throw $httpException;
        } catch (HttpClientException $e) {
            self::assertSame('Request failed', $e->getMessage());
            self::assertSame(400, $e->getCode());
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            self::assertSame('Invalid URL', $e->getPrevious()->getMessage());
        }
    }

    public function testEmptyMessage(): void
    {
        $exception = new HttpClientException('');

        self::assertSame('', $exception->getMessage());
    }

    public function testNegativeCode(): void
    {
        $exception = new HttpClientException('Error', -1);

        self::assertSame(-1, $exception->getCode());
    }
}
