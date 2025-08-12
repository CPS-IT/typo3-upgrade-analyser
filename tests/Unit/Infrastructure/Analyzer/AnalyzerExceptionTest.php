<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerException
 */
class AnalyzerExceptionTest extends TestCase
{
    public function testExceptionExtendsBaseException(): void
    {
        $exception = new AnalyzerException('Test message', 'test_analyzer');

        self::assertInstanceOf(\Exception::class, $exception);
    }

    public function testConstructorSetsMessageAndAnalyzerName(): void
    {
        $message = 'Analysis failed';
        $analyzerName = 'version_availability';

        $exception = new AnalyzerException($message, $analyzerName);

        self::assertSame($message, $exception->getMessage());
        self::assertSame($analyzerName, $exception->getAnalyzerName());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }

    public function testConstructorWithPreviousException(): void
    {
        $message = 'Analysis failed';
        $analyzerName = 'test_analyzer';
        $previous = new \RuntimeException('Previous error');

        $exception = new AnalyzerException($message, $analyzerName, $previous);

        self::assertSame($message, $exception->getMessage());
        self::assertSame($analyzerName, $exception->getAnalyzerName());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testGetAnalyzerNameReturnsCorrectValue(): void
    {
        $analyzerName = 'lines_of_code';
        $exception = new AnalyzerException('Test', $analyzerName);

        self::assertSame($analyzerName, $exception->getAnalyzerName());
    }

    public function testExceptionWithEmptyAnalyzerName(): void
    {
        $exception = new AnalyzerException('Test message', '');

        self::assertSame('', $exception->getAnalyzerName());
    }

    public function testExceptionCanBeThrown(): void
    {
        $this->expectException(AnalyzerException::class);
        $this->expectExceptionMessage('Test error message');

        throw new AnalyzerException('Test error message', 'test_analyzer');
    }

    public function testExceptionChaining(): void
    {
        $rootCause = new \InvalidArgumentException('Invalid input');
        $analyzerException = new AnalyzerException('Processing failed', 'test_analyzer', $rootCause);

        try {
            throw $analyzerException;
        } catch (AnalyzerException $e) {
            self::assertSame('Processing failed', $e->getMessage());
            self::assertSame('test_analyzer', $e->getAnalyzerName());
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            self::assertSame('Invalid input', $e->getPrevious()->getMessage());
        }
    }
}
