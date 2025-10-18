<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Parser\Exception;

use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception\ParseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParseException::class)]
class ParseExceptionTest extends TestCase
{
    public function testBasicExceptionCreation(): void
    {
        $message = 'Test error message';
        $sourcePath = '/path/to/config.php';
        $format = 'php';

        $exception = new ParseException($message, $sourcePath, $format);

        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($format, $exception->getFormat());
        self::assertNull($exception->getParseLine());
        self::assertNull($exception->getParseColumn());
        self::assertSame([], $exception->getContext());
        self::assertFalse($exception->hasLocation());
        self::assertSame('', $exception->getLocationString());
    }

    public function testExceptionWithLocationInformation(): void
    {
        $message = 'Syntax error';
        $sourcePath = '/path/to/config.yaml';
        $format = 'yaml';
        $line = 15;
        $column = 23;

        $exception = new ParseException($message, $sourcePath, $format, $line, $column);

        self::assertSame($line, $exception->getParseLine());
        self::assertSame($column, $exception->getParseColumn());
        self::assertTrue($exception->hasLocation());
        self::assertSame('line 15, column 23', $exception->getLocationString());
    }

    public function testExceptionWithLineOnly(): void
    {
        $exception = new ParseException('Error', '/path/to/file', 'php', 10);

        self::assertSame(10, $exception->getParseLine());
        self::assertNull($exception->getParseColumn());
        self::assertTrue($exception->hasLocation());
        self::assertSame('line 10', $exception->getLocationString());
    }

    public function testExceptionWithContext(): void
    {
        $context = [
            'parser_version' => '2.0.0',
            'attempted_key' => 'invalid_key',
            'surrounding_code' => 'code snippet',
        ];

        $exception = new ParseException(
            'Context error',
            '/path/to/file',
            'php',
            null,
            null,
            $context,
        );

        self::assertSame($context, $exception->getContext());
        self::assertSame('2.0.0', $exception->getContextValue('parser_version'));
        self::assertSame('invalid_key', $exception->getContextValue('attempted_key'));
        self::assertSame('default', $exception->getContextValue('nonexistent', 'default'));
        self::assertNull($exception->getContextValue('nonexistent'));
    }

    public function testExceptionWithErrorCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('Previous error');
        $code = 1234;

        $exception = new ParseException(
            'Error with code',
            '/path/to/file',
            'yaml',
            null,
            null,
            [],
            $code,
            $previous,
        );

        self::assertSame($code, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testEnhancedMessageWithoutLocation(): void
    {
        $message = 'Original error message';
        $exception = new ParseException($message, '/path/to/LocalConfiguration.php', 'php');

        $enhancedMessage = $exception->getMessage();
        self::assertStringContainsString('Php parsing error', $enhancedMessage);
        self::assertStringContainsString('in LocalConfiguration.php', $enhancedMessage);
        self::assertStringContainsString($message, $enhancedMessage);
        self::assertStringNotContainsString('at line', $enhancedMessage);
    }

    public function testEnhancedMessageWithLocation(): void
    {
        $message = 'Syntax error found';
        $exception = new ParseException($message, '/path/to/Services.yaml', 'yaml', 42, 15);

        $enhancedMessage = $exception->getMessage();
        self::assertStringContainsString('Yaml parsing error', $enhancedMessage);
        self::assertStringContainsString('at line 42, column 15', $enhancedMessage);
        self::assertStringContainsString('in Services.yaml', $enhancedMessage);
        self::assertStringContainsString($message, $enhancedMessage);
    }

    public function testSyntaxErrorStaticMethod(): void
    {
        $message = 'Unexpected token';
        $sourcePath = '/path/to/broken.php';
        $format = 'php';
        $line = 25;
        $column = 10;
        $context = ['token' => 'UNEXPECTED_TOKEN'];

        $exception = ParseException::syntaxError($message, $sourcePath, $format, $line, $column, $context);

        self::assertStringContainsString($message, $exception->getMessage());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($format, $exception->getFormat());
        self::assertSame($line, $exception->getParseLine());
        self::assertSame($column, $exception->getParseColumn());
        self::assertSame($context, $exception->getContext());
    }

    public function testFileAccessErrorStaticMethod(): void
    {
        $sourcePath = '/path/to/missing.yaml';
        $format = 'yaml';
        $reason = 'File not found';

        $exception = ParseException::fileAccessError($sourcePath, $format, $reason);

        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($format, $exception->getFormat());
        self::assertNull($exception->getParseLine());
        self::assertNull($exception->getParseColumn());
        self::assertStringContainsString('Cannot access configuration file', $exception->getMessage());
        self::assertStringContainsString($sourcePath, $exception->getMessage());
        self::assertStringContainsString($reason, $exception->getMessage());
    }

    public function testFileAccessErrorWithoutReason(): void
    {
        $sourcePath = '/path/to/inaccessible.php';
        $format = 'php';

        $exception = ParseException::fileAccessError($sourcePath, $format);

        self::assertStringContainsString('Cannot access configuration file', $exception->getMessage());
        self::assertStringContainsString($sourcePath, $exception->getMessage());
        self::assertStringNotContainsString('(', $exception->getMessage());
    }

    public function testUnsupportedFormatStaticMethod(): void
    {
        $sourcePath = '/path/to/config.xml';
        $format = 'xml';

        $exception = ParseException::unsupportedFormat($sourcePath, $format);

        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($format, $exception->getFormat());
        self::assertStringContainsString('Unsupported configuration format', $exception->getMessage());
        self::assertStringContainsString($format, $exception->getMessage());
    }

    public function testInvalidStructureStaticMethod(): void
    {
        $message = 'Missing required section';
        $sourcePath = '/path/to/invalid.yaml';
        $format = 'yaml';
        $context = ['expected_section' => 'services'];

        $exception = ParseException::invalidStructure($message, $sourcePath, $format, $context);

        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($format, $exception->getFormat());
        self::assertNull($exception->getParseLine());
        self::assertNull($exception->getParseColumn());
        self::assertSame($context, $exception->getContext());
        self::assertStringContainsString($message, $exception->getMessage());
    }

    public function testFromThrowableStaticMethod(): void
    {
        $originalMessage = 'Original exception message';
        $originalCode = 5678;
        $original = new \InvalidArgumentException($originalMessage, $originalCode);
        $sourcePath = '/path/to/problematic.php';
        $format = 'php';

        $exception = ParseException::fromThrowable($original, $sourcePath, $format);

        self::assertStringContainsString($originalMessage, $exception->getMessage());
        self::assertSame($originalCode, $exception->getCode());
        self::assertSame($original, $exception->getPrevious());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($format, $exception->getFormat());
        self::assertSame('InvalidArgumentException', $exception->getContextValue('original_exception'));
    }

    public function testGetLocationStringWithoutLocation(): void
    {
        $exception = new ParseException('Error', '/path/to/file', 'php');

        self::assertFalse($exception->hasLocation());
        self::assertSame('', $exception->getLocationString());
    }

    public function testCompleteExceptionFlow(): void
    {
        // Test complete exception creation with all parameters
        $message = 'Complex parsing error';
        $sourcePath = '/complex/path/to/configuration.yaml';
        $format = 'yaml';
        $line = 128;
        $column = 45;
        $context = [
            'parser_mode' => 'strict',
            'validation_level' => 'high',
            'attempted_value' => 'invalid_yaml_structure',
        ];
        $code = 9999;
        $previous = new \RuntimeException('Underlying issue');

        $exception = new ParseException(
            $message,
            $sourcePath,
            $format,
            $line,
            $column,
            $context,
            $code,
            $previous,
        );

        // Verify all properties
        self::assertStringContainsString($message, $exception->getMessage());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($format, $exception->getFormat());
        self::assertSame($line, $exception->getParseLine());
        self::assertSame($column, $exception->getParseColumn());
        self::assertSame($context, $exception->getContext());
        self::assertSame($code, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertTrue($exception->hasLocation());
        self::assertSame('line 128, column 45', $exception->getLocationString());

        // Verify enhanced message contains all expected parts
        $enhancedMessage = $exception->getMessage();
        self::assertStringContainsString('Yaml parsing error', $enhancedMessage);
        self::assertStringContainsString('at line 128, column 45', $enhancedMessage);
        self::assertStringContainsString('in configuration.yaml', $enhancedMessage);
        self::assertStringContainsString($message, $enhancedMessage);

        // Verify context access
        self::assertSame('strict', $exception->getContextValue('parser_mode'));
        self::assertSame('high', $exception->getContextValue('validation_level'));
        self::assertSame('invalid_yaml_structure', $exception->getContextValue('attempted_value'));
        self::assertNull($exception->getContextValue('nonexistent_key'));
        self::assertSame('default', $exception->getContextValue('nonexistent_key', 'default'));
    }

    #[DataProvider('formatProvider')]
    public function testFormatCapitalizationInMessage(string $format, string $expectedCapitalized): void
    {
        $exception = new ParseException('Test error', '/path/to/file', $format);
        $message = $exception->getMessage();

        self::assertStringContainsString($expectedCapitalized . ' parsing error', $message);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function formatProvider(): array
    {
        return [
            'php format' => ['php', 'Php'],
            'yaml format' => ['yaml', 'Yaml'],
            'json format' => ['json', 'Json'],
            'xml format' => ['xml', 'Xml'],
            'packagestates format' => ['packagestates', 'Packagestates'],
        ];
    }
}
