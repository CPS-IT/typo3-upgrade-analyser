<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Repository;

use CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlException
 */
class RepositoryUrlExceptionTest extends TestCase
{
    public function testExceptionExtendsBaseException(): void
    {
        $exception = new RepositoryUrlException('Test message');

        self::assertInstanceOf(\Exception::class, $exception);
    }

    public function testConstructorWithMessage(): void
    {
        $message = 'Invalid repository URL';
        $exception = new RepositoryUrlException($message);

        self::assertSame($message, $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }

    public function testConstructorWithMessageAndCode(): void
    {
        $message = 'Repository not found';
        $code = 404;
        $exception = new RepositoryUrlException($message, $code);

        self::assertSame($message, $exception->getMessage());
        self::assertSame($code, $exception->getCode());
    }

    public function testConstructorWithPreviousException(): void
    {
        $message = 'Repository processing failed';
        $code = 500;
        $previous = new \RuntimeException('Network error');

        $exception = new RepositoryUrlException($message, $code, $previous);

        self::assertSame($message, $exception->getMessage());
        self::assertSame($code, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testCanBeThrown(): void
    {
        $this->expectException(RepositoryUrlException::class);
        $this->expectExceptionMessage('Test repository error');
        $this->expectExceptionCode(400);

        throw new RepositoryUrlException('Test repository error', 400);
    }

    public function testExceptionChaining(): void
    {
        $rootCause = new \InvalidArgumentException('Invalid URL format');
        $repositoryException = new RepositoryUrlException('URL processing failed', 400, $rootCause);

        try {
            throw $repositoryException;
        } catch (RepositoryUrlException $e) {
            self::assertSame('URL processing failed', $e->getMessage());
            self::assertSame(400, $e->getCode());
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            self::assertSame('Invalid URL format', $e->getPrevious()->getMessage());
        }
    }

    public function testEmptyMessage(): void
    {
        $exception = new RepositoryUrlException('');

        self::assertSame('', $exception->getMessage());
    }

    public function testNegativeCode(): void
    {
        $exception = new RepositoryUrlException('Error', -1);

        self::assertSame(-1, $exception->getCode());
    }
}
