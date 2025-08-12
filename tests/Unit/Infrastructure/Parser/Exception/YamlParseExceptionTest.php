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
use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception\YamlParseException;
use PHPUnit\Framework\TestCase;

/**
 * Test case for YamlParseException.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception\YamlParseException
 */
class YamlParseExceptionTest extends TestCase
{
    public function testBasicYamlParseExceptionCreation(): void
    {
        $message = 'YAML syntax error';
        $sourcePath = '/path/to/Services.yaml';

        $exception = new YamlParseException($message, $sourcePath);

        self::assertInstanceOf(ParseException::class, $exception);
        self::assertStringContainsString($message, $exception->getMessage());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame('yaml', $exception->getFormat());
        self::assertNull($exception->getYamlSnippet());
        self::assertNull($exception->getProblemMark());
        self::assertFalse($exception->hasYamlSnippet());
    }

    public function testYamlParseExceptionWithLocationAndSnippet(): void
    {
        $message = 'Indentation error';
        $sourcePath = '/path/to/config.yaml';
        $line = 25;
        $column = 8;
        $yamlSnippet = "services:\n  _defaults:\n    autowire: true\n   autoconfigure: true  # Wrong indentation";
        $problemMark = 'Problem at line 25, column 8';

        $exception = new YamlParseException($message, $sourcePath, $line, $column, $yamlSnippet, $problemMark);

        self::assertSame($line, $exception->getParseLine());
        self::assertSame($column, $exception->getParseColumn());
        self::assertSame($yamlSnippet, $exception->getYamlSnippet());
        self::assertSame($problemMark, $exception->getProblemMark());
        self::assertTrue($exception->hasYamlSnippet());
        self::assertTrue($exception->hasLocation());
    }

    public function testYamlParseExceptionWithContextAndPrevious(): void
    {
        $context = [
            'yaml_version' => '1.2',
            'parser_flags' => 'YAML_PARSE_STRICT',
        ];
        $code = 100;
        $previous = new \RuntimeException('Original error');

        $exception = new YamlParseException(
            'Context error',
            '/path/to/config.yaml',
            null,
            null,
            null,
            null,
            $context,
            $code,
            $previous,
        );

        self::assertSame($context, $exception->getContext());
        self::assertSame($code, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame('1.2', $exception->getContextValue('yaml_version'));
        self::assertSame('YAML_PARSE_STRICT', $exception->getContextValue('parser_flags'));
    }

    public function testFromSymfonyYamlExceptionBasic(): void
    {
        $originalMessage = 'Unable to parse at line 10';
        $originalCode = 42;
        $original = new \Exception($originalMessage, $originalCode);
        $sourcePath = '/path/to/Services.yaml';

        $exception = YamlParseException::fromSymfonyYamlException($original, $sourcePath);

        self::assertInstanceOf(YamlParseException::class, $exception);
        self::assertStringContainsString($originalMessage, $exception->getMessage());
        self::assertSame($originalCode, $exception->getCode());
        self::assertSame($original, $exception->getPrevious());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame('Exception', $exception->getContextValue('original_exception'));
    }

    public function testFromSymfonyYamlExceptionWithLineExtraction(): void
    {
        $originalMessage = 'YAML parse error at line 35: Invalid indentation';
        $original = new \Exception($originalMessage);
        $sourcePath = '/path/to/config.yaml';

        $exception = YamlParseException::fromSymfonyYamlException($original, $sourcePath);

        self::assertSame(35, $exception->getParseLine());
        self::assertTrue($exception->hasLocation());
    }

    public function testFromSymfonyYamlExceptionWithMockParsedException(): void
    {
        // Create a mock that simulates Symfony's ParseException methods
        $originalMessage = 'Mock YAML parse error';
        $mockException = new class($originalMessage, 500) extends \Exception {
            public function getParsedLine(): int
            {
                return 42;
            }

            public function getSnippet(): string
            {
                return "services:\n  invalid: syntax";
            }
        };

        $sourcePath = '/path/to/broken.yaml';

        $exception = YamlParseException::fromSymfonyYamlException($mockException, $sourcePath);

        self::assertStringContainsString($originalMessage, $exception->getMessage());
        self::assertSame(500, $exception->getCode());
        self::assertSame($mockException, $exception->getPrevious());
        self::assertSame($sourcePath, $exception->getSourcePath());
    }

    public function testIndentationErrorStaticMethod(): void
    {
        $sourcePath = '/path/to/indented.yaml';
        $line = 15;
        $expectedIndent = 4;
        $actualIndent = 2;

        $exception = YamlParseException::indentationError($sourcePath, $line, $expectedIndent, $actualIndent);

        self::assertInstanceOf(YamlParseException::class, $exception);
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($line, $exception->getParseLine());
        self::assertNull($exception->getParseColumn());
        self::assertStringContainsString('Indentation error', $exception->getMessage());
        self::assertStringContainsString('expected 4 spaces', $exception->getMessage());
        self::assertStringContainsString('got 2', $exception->getMessage());
        self::assertSame($expectedIndent, $exception->getContextValue('expected_indent'));
        self::assertSame($actualIndent, $exception->getContextValue('actual_indent'));
    }

    public function testInvalidStructureStaticMethod(): void
    {
        $message = 'Missing required services section';
        $sourcePath = '/path/to/invalid.yaml';
        $line = 1;
        $snippet = 'parameters:\n  locale: en';

        $exception = YamlParseException::invalidYamlStructure($message, $sourcePath, $line, $snippet);

        self::assertInstanceOf(YamlParseException::class, $exception);
        self::assertStringContainsString($message, $exception->getMessage());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($line, $exception->getParseLine());
        self::assertNull($exception->getParseColumn());
        self::assertSame($snippet, $exception->getYamlSnippet());
        self::assertTrue($exception->hasYamlSnippet());
    }

    public function testInvalidStructureWithoutOptionalParameters(): void
    {
        $message = 'Structure validation failed';
        $sourcePath = '/path/to/config.yaml';

        $exception = YamlParseException::invalidYamlStructure($message, $sourcePath);

        self::assertStringContainsString($message, $exception->getMessage());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertNull($exception->getParseLine());
        self::assertNull($exception->getParseColumn());
        self::assertNull($exception->getYamlSnippet());
        self::assertFalse($exception->hasYamlSnippet());
    }

    public function testUnsupportedFeatureStaticMethod(): void
    {
        $feature = 'custom tags';
        $sourcePath = '/path/to/advanced.yaml';
        $line = 50;

        $exception = YamlParseException::unsupportedFeature($feature, $sourcePath, $line);

        self::assertInstanceOf(YamlParseException::class, $exception);
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($line, $exception->getParseLine());
        self::assertStringContainsString('Unsupported YAML feature', $exception->getMessage());
        self::assertStringContainsString($feature, $exception->getMessage());
        self::assertSame($feature, $exception->getContextValue('feature'));
    }

    public function testUnsupportedFeatureWithoutLine(): void
    {
        $feature = 'advanced anchors';
        $sourcePath = '/path/to/config.yaml';

        $exception = YamlParseException::unsupportedFeature($feature, $sourcePath);

        self::assertSame($feature, $exception->getContextValue('feature'));
        self::assertNull($exception->getParseLine());
    }

    public function testDuplicateKeyStaticMethod(): void
    {
        $key = 'services._defaults';
        $sourcePath = '/path/to/duplicate.yaml';
        $line = 30;

        $exception = YamlParseException::duplicateKey($key, $sourcePath, $line);

        self::assertInstanceOf(YamlParseException::class, $exception);
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame($line, $exception->getParseLine());
        self::assertStringContainsString('Duplicate key found', $exception->getMessage());
        self::assertStringContainsString($key, $exception->getMessage());
        self::assertSame($key, $exception->getContextValue('duplicate_key'));
    }

    public function testDuplicateKeyWithoutLine(): void
    {
        $key = 'parameters.locale';
        $sourcePath = '/path/to/config.yaml';

        $exception = YamlParseException::duplicateKey($key, $sourcePath);

        self::assertSame($key, $exception->getContextValue('duplicate_key'));
        self::assertNull($exception->getParseLine());
    }

    public function testHasYamlSnippetWithNullSnippet(): void
    {
        $exception = new YamlParseException('Error', '/path/to/file.yaml', null, null, null);

        self::assertFalse($exception->hasYamlSnippet());
        self::assertNull($exception->getYamlSnippet());
    }

    public function testHasYamlSnippetWithEmptyStringSnippet(): void
    {
        $exception = new YamlParseException('Error', '/path/to/file.yaml', null, null, '');

        self::assertTrue($exception->hasYamlSnippet());
        self::assertSame('', $exception->getYamlSnippet());
    }

    public function testInheritanceFromParseException(): void
    {
        $exception = new YamlParseException('Test', '/path/to/file.yaml');

        self::assertInstanceOf(ParseException::class, $exception);
        self::assertSame('yaml', $exception->getFormat());
    }

    public function testCompleteYamlParseExceptionFlow(): void
    {
        // Create a comprehensive exception with all YAML-specific features
        $message = 'Complex YAML parsing error with multiple issues';
        $sourcePath = '/var/www/config/packages/framework.yaml';
        $line = 78;
        $column = 12;
        $yamlSnippet = <<<YAML
            framework:
              cache:
                app: cache.adapter.filesystem
                system: cache.adapter.system
                  default_redis_provider: redis://localhost  # Incorrect indentation
                pools:
                  cache.app:
                    adapter: cache.adapter.redis
                    default_lifetime: 3600
            YAML;
        $problemMark = 'found character that cannot start any token at line 78, column 12';
        $context = [
            'yaml_version' => '1.2',
            'parser_flags' => 'PARSE_EXCEPTION_ON_INVALID_TYPE',
            'file_encoding' => 'UTF-8',
            'validation_mode' => 'strict',
        ];
        $code = 2001;
        $previous = new \RuntimeException('Underlying YAML library error');

        $exception = new YamlParseException(
            $message,
            $sourcePath,
            $line,
            $column,
            $yamlSnippet,
            $problemMark,
            $context,
            $code,
            $previous,
        );

        // Verify all properties are correctly set
        self::assertStringContainsString($message, $exception->getMessage());
        self::assertSame($sourcePath, $exception->getSourcePath());
        self::assertSame('yaml', $exception->getFormat());
        self::assertSame($line, $exception->getParseLine());
        self::assertSame($column, $exception->getParseColumn());
        self::assertSame($yamlSnippet, $exception->getYamlSnippet());
        self::assertSame($problemMark, $exception->getProblemMark());
        self::assertSame($context, $exception->getContext());
        self::assertSame($code, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());

        // Verify derived properties
        self::assertTrue($exception->hasYamlSnippet());
        self::assertTrue($exception->hasLocation());
        self::assertSame('line 78, column 12', $exception->getLocationString());

        // Verify message enhancement includes YAML-specific information
        $enhancedMessage = $exception->getMessage();
        self::assertStringContainsString('Yaml parsing error', $enhancedMessage);
        self::assertStringContainsString('at line 78, column 12', $enhancedMessage);
        self::assertStringContainsString('in framework.yaml', $enhancedMessage);
        self::assertStringContainsString($message, $enhancedMessage);

        // Verify context access
        self::assertSame('1.2', $exception->getContextValue('yaml_version'));
        self::assertSame('PARSE_EXCEPTION_ON_INVALID_TYPE', $exception->getContextValue('parser_flags'));
        self::assertSame('UTF-8', $exception->getContextValue('file_encoding'));
        self::assertSame('strict', $exception->getContextValue('validation_mode'));
    }

    /**
     * @dataProvider lineExtractionProvider
     */
    public function testLineExtractionFromMessage(string $message, ?int $expectedLine): void
    {
        $original = new \Exception($message);
        $exception = YamlParseException::fromSymfonyYamlException($original, '/test.yaml');

        self::assertSame($expectedLine, $exception->getParseLine());
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public function lineExtractionProvider(): array
    {
        return [
            'no line number' => ['Simple error message', null],
            'line at end' => ['Parse error at line 42', 42],
            'line in middle' => ['Error at line 123 with additional info', 123],
            'multiple numbers' => ['Error at line 10 and also line 20', 10],
            'line with colon' => ['Parse error: at line 456', 456],
            'zero line' => ['Error at line 0', 0],
            'large line number' => ['Error at line 9999', 9999],
        ];
    }

    public function testYamlSnippetHandling(): void
    {
        $multiLineSnippet = <<<YAML
            services:
              _defaults:
                autowire: true
                autoconfigure: true

              App\Controller\:
                resource: '../src/Controller'
                tags: ['controller.service_arguments']

              # This is where the error occurs
              App\Service\BadService
                arguments: ['@doctrine.orm.entity_manager']  # Missing colon after service name
            YAML;

        $exception = new YamlParseException(
            'Missing colon after service name',
            '/path/to/services.yaml',
            45,
            25,
            $multiLineSnippet,
        );

        self::assertTrue($exception->hasYamlSnippet());
        self::assertSame($multiLineSnippet, $exception->getYamlSnippet());
        self::assertStringContainsString('App\Service\BadService', $exception->getYamlSnippet());
        self::assertStringContainsString('Missing colon', $exception->getYamlSnippet());
    }
}
