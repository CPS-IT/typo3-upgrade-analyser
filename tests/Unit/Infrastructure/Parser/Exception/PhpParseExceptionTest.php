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

use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception\PhpParseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpParseException::class)]
class PhpParseExceptionTest extends TestCase
{
    public function testBasicPhpParseExceptionCreation(): void
    {
        $message = 'PHP syntax error';
        $sourcePath = '/path/to/LocalConfiguration.php';

        $exception = new PhpParseException($message, $sourcePath);

        self::assertStringContainsString($message, $exception->getMessage());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame('php', $exception->getFormat());
        self::assertSame([], $exception->getAstErrors());
        self::assertSame(PHP_VERSION, $exception->getPhpVersion());
        self::assertFalse($exception->hasAstErrors());
        self::assertNull($exception->getFirstAstError());
    }

    public function testPhpParseExceptionWithAstErrors(): void
    {
        $message = 'Multiple syntax errors';
        $sourcePath = '/path/to/broken.php';
        $astErrors = [
            'Syntax error, unexpected T_STRING on line 15',
            'Parse error: syntax error, unexpected \'}\' on line 25',
            'Fatal error: Unclosed bracket on line 30',
        ];

        $exception = new PhpParseException($message, $sourcePath, $astErrors);

        self::assertSame($astErrors, $exception->getAstErrors());
        self::assertTrue($exception->hasAstErrors());
        self::assertSame('Syntax error, unexpected T_STRING on line 15', $exception->getFirstAstError());
    }

    public function testPhpParseExceptionWithCustomPhpVersion(): void
    {
        $customPhpVersion = '8.1.0';
        $exception = new PhpParseException(
            'Version-specific error',
            '/path/to/config.php',
            [],
            $customPhpVersion,
        );

        self::assertSame($customPhpVersion, $exception->getPhpVersion());
    }

    public function testPhpParseExceptionWithLocationAndContext(): void
    {
        $message = 'Context error';
        $sourcePath = '/path/to/config.php';
        $astErrors = ['AST parsing failed'];
        $phpVersion = '8.2.0';
        $line = 42;
        $column = 15;
        $context = [
            'parser_mode' => 'strict',
            'error_type' => 'syntax',
        ];

        $exception = new PhpParseException(
            $message,
            $sourcePath,
            $astErrors,
            $phpVersion,
            $line,
            $column,
            $context,
        );

        self::assertSame($line, $exception->getParseLine());
        self::assertSame($column, $exception->getParseColumn());
        self::assertSame($context, $exception->getContext());
        self::assertSame($astErrors, $exception->getAstErrors());
        self::assertSame($phpVersion, $exception->getPhpVersion());
        self::assertTrue($exception->hasLocation());
    }

    public function testPhpParseExceptionWithCodeAndPrevious(): void
    {
        $code = 500;
        $previous = new \RuntimeException('Previous error');

        $exception = new PhpParseException(
            'Error with previous',
            '/path/to/config.php',
            [],
            null,
            null,
            null,
            [],
            $code,
            $previous,
        );

        self::assertSame($code, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testFromAstErrorsStaticMethod(): void
    {
        $astErrors = [
            'Parse error: syntax error, unexpected T_FUNCTION on line 10',
            'Fatal error: Cannot redeclare function on line 20',
        ];
        $sourcePath = '/path/to/broken.php';
        $line = 10;
        $column = 5;

        $exception = PhpParseException::fromAstErrors($astErrors, $sourcePath, $line, $column);

        self::assertSame($astErrors, $exception->getAstErrors());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($line, $exception->getParseLine());
        self::assertSame($column, $exception->getParseColumn());
        self::assertStringContainsString('Parse error: syntax error, unexpected T_FUNCTION on line 10', $exception->getMessage());
        self::assertTrue($exception->hasAstErrors());
        self::assertSame('Parse error: syntax error, unexpected T_FUNCTION on line 10', $exception->getFirstAstError());
    }

    public function testFromAstErrorsWithEmptyErrors(): void
    {
        $astErrors = [];
        $sourcePath = '/path/to/config.php';

        $exception = PhpParseException::fromAstErrors($astErrors, $sourcePath);

        self::assertStringContainsString('PHP syntax error', $exception->getMessage());
        self::assertSame([], $exception->getAstErrors());
        self::assertFalse($exception->hasAstErrors());
        self::assertNull($exception->getFirstAstError());
    }

    public function testFromAstErrorsWithLocationOnly(): void
    {
        $astErrors = ['Syntax error at specified location'];
        $sourcePath = '/path/to/config.php';
        $line = 25;

        $exception = PhpParseException::fromAstErrors($astErrors, $sourcePath, $line);

        self::assertSame($line, $exception->getParseLine());
        self::assertNull($exception->getParseColumn());
        self::assertTrue($exception->hasLocation());
    }

    public function testUnsupportedConstructStaticMethod(): void
    {
        $construct = 'eval()';
        $sourcePath = '/path/to/dangerous.php';
        $line = 100;

        $exception = PhpParseException::unsupportedConstruct($construct, $sourcePath, $line);

        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($line, $exception->getParseLine());
        self::assertNull($exception->getParseColumn());
        self::assertStringContainsString('Unsupported PHP construct', $exception->getMessage());
        self::assertStringContainsString($construct, $exception->getMessage());
        self::assertSame($construct, $exception->getContextValue('construct'));
    }

    public function testInvalidConfigurationStructureStaticMethod(): void
    {
        $expectedStructure = 'array with return statement';
        $sourcePath = '/path/to/malformed.php';
        $context = [
            'found_structure' => 'plain assignment',
            'validation_rule' => 'typo3_config',
        ];

        $exception = PhpParseException::invalidConfigurationStructure($expectedStructure, $sourcePath, $context);

        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertStringContainsString('Invalid configuration structure', $exception->getMessage());
        self::assertStringContainsString($expectedStructure, $exception->getMessage());
        self::assertSame($context, $exception->getContext());
        self::assertSame('plain assignment', $exception->getContextValue('found_structure'));
        self::assertSame('typo3_config', $exception->getContextValue('validation_rule'));
    }

    public function testMissingRequiredKeysStaticMethod(): void
    {
        $missingKeys = ['DB', 'SYS', 'EXTENSIONS'];
        $sourcePath = '/path/to/incomplete.php';

        $exception = PhpParseException::missingRequiredKeys($missingKeys, $sourcePath);

        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertStringContainsString('Missing required configuration keys', $exception->getMessage());
        self::assertStringContainsString('DB, SYS, EXTENSIONS', $exception->getMessage());
        self::assertSame($missingKeys, $exception->getContextValue('missing_keys'));
    }

    public function testMissingRequiredKeysWithSingleKey(): void
    {
        $missingKeys = ['SYS'];
        $sourcePath = '/path/to/incomplete.php';

        $exception = PhpParseException::missingRequiredKeys($missingKeys, $sourcePath);

        self::assertStringContainsString('SYS', $exception->getMessage());
        self::assertStringNotContainsString(',', $exception->getMessage());
    }

    public function testEmptyAstErrorsHandling(): void
    {
        $exception = new PhpParseException('Error', '/path/to/config.php', []);

        self::assertFalse($exception->hasAstErrors());
        self::assertNull($exception->getFirstAstError());
        self::assertSame([], $exception->getAstErrors());
    }

    public function testDefaultPhpVersionHandling(): void
    {
        $exception = new PhpParseException('Error', '/path/to/config.php');

        self::assertSame(PHP_VERSION, $exception->getPhpVersion());
    }

    public function testNullPhpVersionHandling(): void
    {
        $exception = new PhpParseException('Error', '/path/to/config.php', [], null);

        self::assertSame(PHP_VERSION, $exception->getPhpVersion());
    }

    public function testInheritanceFromParseException(): void
    {
        $exception = new PhpParseException('Test', '/path/to/file.php');

        self::assertSame('php', $exception->getFormat());
    }

    public function testCompletePhpParseExceptionFlow(): void
    {
        // Create a comprehensive exception with all features
        $message = 'Complex PHP parsing error';
        $sourcePath = '/var/www/typo3conf/LocalConfiguration.php';
        $astErrors = [
            'Parse error: syntax error, unexpected T_CONSTANT_ENCAPSED_STRING, expecting \',\' or \')\' in LocalConfiguration.php on line 42',
            'Fatal error: Cannot use \'return\' when not in a function in LocalConfiguration.php on line 45',
        ];
        $phpVersion = '8.1.15';
        $line = 42;
        $column = 25;
        $context = [
            'parser_type' => 'nikic/php-parser',
            'parsing_mode' => 'safe_mode',
            'file_size' => 15360,
            'encoding' => 'UTF-8',
        ];
        $code = 1001;
        $previous = new \ParseError('Original parse error');

        $exception = new PhpParseException(
            $message,
            $sourcePath,
            $astErrors,
            $phpVersion,
            $line,
            $column,
            $context,
            $code,
            $previous,
        );

        // Verify all properties are correctly set
        self::assertStringContainsString($message, $exception->getMessage());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame('php', $exception->getFormat());
        self::assertSame($astErrors, $exception->getAstErrors());
        self::assertSame($phpVersion, $exception->getPhpVersion());
        self::assertSame($line, $exception->getParseLine());
        self::assertSame($column, $exception->getParseColumn());
        self::assertSame($context, $exception->getContext());
        self::assertSame($code, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());

        // Verify derived properties
        self::assertTrue($exception->hasAstErrors());
        $firstAstError = $exception->getFirstAstError();
        self::assertNotNull($firstAstError, 'Expected an AST error message');
        self::assertStringContainsString('Parse error: syntax error', $firstAstError);
        self::assertTrue($exception->hasLocation());
        self::assertSame('line 42, column 25', $exception->getLocationString());

        // Verify message enhancement includes PHP-specific information
        $enhancedMessage = $exception->getMessage();
        self::assertStringContainsString('Php parsing error', $enhancedMessage);
        self::assertStringContainsString('at line 42, column 25', $enhancedMessage);
        self::assertStringContainsString('in LocalConfiguration.php', $enhancedMessage);
        self::assertStringContainsString($message, $enhancedMessage);

        // Verify context access
        self::assertSame('nikic/php-parser', $exception->getContextValue('parser_type'));
        self::assertSame('safe_mode', $exception->getContextValue('parsing_mode'));
        self::assertSame(15360, $exception->getContextValue('file_size'));
        self::assertSame('UTF-8', $exception->getContextValue('encoding'));
    }

    #[DataProvider('astErrorsProvider')]
    public function testAstErrorsHandling(array $astErrors, bool $expectedHasErrors, ?string $expectedFirstError): void
    {
        $exception = new PhpParseException('Test', '/path/to/file.php', $astErrors);

        self::assertSame($expectedHasErrors, $exception->hasAstErrors());
        self::assertSame($expectedFirstError, $exception->getFirstAstError());
        self::assertSame($astErrors, $exception->getAstErrors());
    }

    /**
     * @return array<string, array{array<string>, bool, string|null}>
     */
    public static function astErrorsProvider(): array
    {
        return [
            'empty array' => [[], false, null],
            'single error' => [['Single error'], true, 'Single error'],
            'multiple errors' => [
                ['First error', 'Second error', 'Third error'],
                true,
                'First error',
            ],
            'realistic errors' => [
                [
                    'Parse error: syntax error, unexpected T_STRING on line 10',
                    'Fatal error: Cannot redeclare function foo() on line 15',
                ],
                true,
                'Parse error: syntax error, unexpected T_STRING on line 10',
            ],
        ];
    }
}
