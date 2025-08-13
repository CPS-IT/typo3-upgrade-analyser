<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Parser;

use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\AbstractConfigurationParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\ConfigurationParserInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception\ParseException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;


#[CoversClass(AbstractConfigurationParser::class)]
class AbstractConfigurationParserTest extends TestCase
{
    private MockObject&LoggerInterface $logger;
    private TestableAbstractParser $parser;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->parser = new TestableAbstractParser($this->logger);
        $this->tempFile = tempnam(sys_get_temp_dir(), 'parser_test_') . '.test';

        // Create the temp file so it exists for file accessibility tests
        touch($this->tempFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testImplementsConfigurationParserInterface(): void
    {
        self::assertInstanceOf(ConfigurationParserInterface::class, $this->parser);
    }

    public function testParseFileWithValidFile(): void
    {
        $content = 'test content';
        $data = ['key' => 'value'];
        file_put_contents($this->tempFile, $content);

        $this->parser->setParseResult($data);

        $result = $this->parser->parseFile($this->tempFile);

        self::assertTrue($result->isSuccessful());
        self::assertSame($data, $result->getData());
        self::assertSame('test', $result->getFormat());
        self::assertSame($this->tempFile, $result->getSourcePath());
    }

    public function testParseFileWithNonExistentFile(): void
    {
        $nonExistentFile = '/path/to/nonexistent/file.test';

        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::matchesRegularExpression('/not accessible/'));

        $result = $this->parser->parseFile($nonExistentFile);

        self::assertFalse($result->isSuccessful());
        self::assertTrue($result->hasErrors());
        $firstError = $result->getFirstError();
        self::assertNotNull($firstError, 'Expected an error message');
        self::assertStringContainsString('not accessible', $firstError);
        self::assertSame('test', $result->getFormat());
        self::assertSame($nonExistentFile, $result->getSourcePath());
    }

    public function testParseFileWithUnsupportedFile(): void
    {
        $unsupportedFile = tempnam(sys_get_temp_dir(), 'unsupported_') . '.unsupported';
        file_put_contents($unsupportedFile, 'content');

        try {
            $this->logger->expects(self::once())
                ->method('warning')
                ->with(self::matchesRegularExpression('/does not support file/'));

            $result = $this->parser->parseFile($unsupportedFile);

            self::assertFalse($result->isSuccessful());
            self::assertTrue($result->hasErrors());
            $firstError = $result->getFirstError();
            self::assertNotNull($firstError, 'Expected an error message');
            self::assertStringContainsString('does not support file', $firstError);
        } finally {
            unlink($unsupportedFile);
        }
    }

    public function testParseFileWithParseException(): void
    {
        file_put_contents($this->tempFile, 'content');
        $parseException = new ParseException('Parse error', $this->tempFile, 'test', 10, 5);
        $this->parser->setThrowException($parseException);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                'Configuration parsing failed',
                self::callback(function ($context): bool {
                    return isset($context['error'], $context['line'], $context['column']);
                }),
            );

        $result = $this->parser->parseFile($this->tempFile);

        self::assertFalse($result->isSuccessful());
        self::assertTrue($result->hasErrors());
        $firstError = $result->getFirstError();
        self::assertNotNull($firstError, 'Expected an error message');
        self::assertStringContainsString('Parse error', $firstError);
        self::assertSame(10, $result->getMetadataValue('line'));
        self::assertSame(5, $result->getMetadataValue('column'));
    }

    public function testParseFileWithUnexpectedException(): void
    {
        file_put_contents($this->tempFile, 'content');
        $exception = new \RuntimeException('Unexpected error');
        $this->parser->setThrowException($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                'Configuration parsing failed',
                self::callback(function ($context): bool {
                    return isset($context['error'], $context['file_path'], $context['parser']);
                }),
            );

        $result = $this->parser->parseFile($this->tempFile);

        self::assertFalse($result->isSuccessful());
        self::assertTrue($result->hasErrors());
        $firstError = $result->getFirstError();
        self::assertNotNull($firstError, 'Expected an error message');
        self::assertStringContainsString('Unexpected error', $firstError);

        // Additional assertion to ensure test is not risky
        self::assertSame('test', $result->getFormat());
    }

    public function testParseContentWithValidContent(): void
    {
        $content = 'valid content';
        $data = ['parsed' => 'data'];
        $sourcePath = '/path/to/source.test';

        $this->parser->setParseResult($data);

        $result = $this->parser->parseContent($content, $sourcePath);

        self::assertTrue($result->isSuccessful());
        self::assertSame($data, $result->getData());
        self::assertSame($sourcePath, $result->getSourcePath());
        self::assertSame('test', $result->getFormat());
    }

    public function testParseContentWithEmptyContent(): void
    {
        $result = $this->parser->parseContent('   ', '/path/to/empty.test');

        self::assertTrue($result->isSuccessful());
        self::assertSame([], $result->getData());
        self::assertTrue($result->hasWarnings());
        $firstWarning = $result->getFirstWarning();
        self::assertNotNull($firstWarning, 'Expected a warning message');
        self::assertStringContainsString('empty', $firstWarning);
        self::assertSame(0, $result->getMetadataValue('content_length'));
    }

    public function testParseContentWithParseException(): void
    {
        $content = 'invalid content';
        $exception = new ParseException('Parse failed', '/source', 'test');
        $this->parser->setThrowException($exception);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Parse failed');

        $this->parser->parseContent($content);
    }

    public function testParseContentWithUnexpectedException(): void
    {
        $content = 'content';
        $exception = new \InvalidArgumentException('Unexpected error');
        $this->parser->setThrowException($exception);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unexpected error');

        $this->parser->parseContent($content);
    }

    public function testParseContentWithValidationErrors(): void
    {
        $content = 'content';
        $data = ['invalid' => 'data'];
        $this->parser->setParseResult($data);
        $this->parser->setValidationResult([
            'valid' => false,
            'errors' => ['Validation error 1', 'Validation error 2'],
            'warnings' => ['Validation warning'],
        ]);

        $result = $this->parser->parseContent($content);

        self::assertFalse($result->isSuccessful());
        self::assertTrue($result->hasErrors());
        self::assertTrue($result->hasWarnings());
        self::assertSame(['Validation error 1', 'Validation error 2'], $result->getErrors());
        self::assertSame(['Validation warning'], $result->getWarnings());
    }

    public function testParseContentWithPostProcessing(): void
    {
        $content = 'content';
        $originalData = ['original' => 'data'];
        $processedData = ['processed' => 'data'];

        $this->parser->setParseResult($originalData);
        $this->parser->setPostProcessedData($processedData);

        $result = $this->parser->parseContent($content);

        self::assertTrue($result->isSuccessful());
        self::assertSame($processedData, $result->getData());
    }

    public function testSupportsWithValidExtension(): void
    {
        self::assertTrue($this->parser->supports('/path/to/file.test'));
        self::assertTrue($this->parser->supports('/path/to/file.TEST')); // Case insensitive
    }

    public function testSupportsWithInvalidExtension(): void
    {
        self::assertFalse($this->parser->supports('/path/to/file.invalid'));
        self::assertFalse($this->parser->supports('/path/to/file'));
        self::assertFalse($this->parser->supports('/path/to/file.'));
    }

    public function testSupportsWithSpecificCheck(): void
    {
        $this->parser->setSupportsSpecific(false);
        self::assertFalse($this->parser->supports('/path/to/file.test'));
    }

    public function testGetPriority(): void
    {
        self::assertSame(50, $this->parser->getPriority());
    }

    public function testGetRequiredDependencies(): void
    {
        self::assertSame([], $this->parser->getRequiredDependencies());
    }

    public function testIsReadyWithNoDependencies(): void
    {
        self::assertTrue($this->parser->isReady());
    }

    public function testIsReadyWithExistingClassDependency(): void
    {
        $this->parser->setRequiredDependencies([\stdClass::class]);
        self::assertTrue($this->parser->isReady());
    }

    public function testIsReadyWithExistingInterfaceDependency(): void
    {
        $this->parser->setRequiredDependencies([\Traversable::class]);
        self::assertTrue($this->parser->isReady());
    }

    public function testIsReadyWithPackageDependency(): void
    {
        $this->parser->setRequiredDependencies(['vendor/package']);
        self::assertTrue($this->parser->isReady()); // Packages are assumed available
    }

    public function testIsReadyWithMissingClassDependency(): void
    {
        $this->parser->setRequiredDependencies(['NonExistentClass']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required dependency not available: NonExistentClass');

        $this->parser->isReady();
    }

    public function testParserOptionsManagement(): void
    {
        self::assertSame([], $this->parser->getParserOptions());

        $options = ['option1' => 'value1', 'option2' => 'value2'];
        $this->parser->setParserOptions($options);

        self::assertSame($options, $this->parser->getParserOptions());

        // Test merging
        $additionalOptions = ['option2' => 'new_value2', 'option3' => 'value3'];
        $this->parser->setParserOptions($additionalOptions);

        $expectedOptions = ['option1' => 'value1', 'option2' => 'new_value2', 'option3' => 'value3'];
        self::assertSame($expectedOptions, $this->parser->getParserOptions());
    }

    public function testProtectedOptionMethods(): void
    {
        $this->parser->testSetOption('test_key', 'test_value');
        self::assertSame('test_value', $this->parser->testGetOption('test_key'));
        self::assertSame('default', $this->parser->testGetOption('nonexistent', 'default'));
        self::assertNull($this->parser->testGetOption('nonexistent'));
    }

    public function testIsFileAccessible(): void
    {
        self::assertTrue($this->parser->testIsFileAccessible($this->tempFile));
        self::assertFalse($this->parser->testIsFileAccessible('/nonexistent/file.test'));
    }

    public function testReadFileContent(): void
    {
        $content = 'test file content';
        file_put_contents($this->tempFile, $content);

        self::assertSame($content, $this->parser->testReadFileContent($this->tempFile));
    }

    public function testReadFileContentWithNonExistentFile(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Cannot access configuration file');

        $this->parser->testReadFileContent('/nonexistent/file.test');
    }

    public function testValidateParsedData(): void
    {
        $data = ['key' => 'value'];
        $result = $this->parser->testValidateParsedData($data, '/path/to/source');

        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);
        self::assertSame([], $result['warnings']);
    }

    public function testPostProcessData(): void
    {
        $data = ['original' => 'data'];
        $result = $this->parser->testPostProcessData($data, '/path/to/source');

        self::assertSame($data, $result); // Default implementation returns unchanged data
    }

    public function testHasNestedArrays(): void
    {
        $flatData = ['key1' => 'value1', 'key2' => 'value2'];
        $nestedData = ['key1' => 'value1', 'key2' => ['nested' => 'value']];
        $emptyData = [];

        self::assertFalse($this->parser->testHasNestedArrays($flatData));
        self::assertTrue($this->parser->testHasNestedArrays($nestedData));
        self::assertFalse($this->parser->testHasNestedArrays($emptyData));
    }

    public function testParseContentMetadata(): void
    {
        $content = 'test content';
        $data = [
            'key1' => 'value1',
            'key2' => ['nested' => 'value'],
        ];
        $this->parser->setParseResult($data);

        $result = $this->parser->parseContent($content, '/path/to/test.test');

        self::assertTrue($result->isSuccessful());
        self::assertSame('TestParser', $result->getMetadataValue('parser'));
        self::assertSame(\strlen($content), $result->getMetadataValue('content_length'));
        self::assertSame(2, $result->getMetadataValue('data_keys'));
        self::assertTrue($result->getMetadataValue('has_nested_data'));
    }

    public function testLoggerIntegration(): void
    {
        $content = 'test content';
        $data = ['key' => 'value'];
        file_put_contents($this->tempFile, $content);
        $this->parser->setParseResult($data);

        // Expect debug log for start
        $this->logger->expects(self::exactly(2))
            ->method('debug')
            ->willReturnCallback(function ($message, $context): void {
                static $callCount = 0;
                ++$callCount;

                if (1 === $callCount) {
                    self::assertSame('Starting configuration file parsing', $message);
                    self::assertIsArray($context);
                    self::assertArrayHasKey('file_path', $context);
                    self::assertArrayHasKey('parser', $context);
                    self::assertArrayHasKey('format', $context);
                } elseif (2 === $callCount) {
                    self::assertSame('Starting content parsing', $message);
                    self::assertIsArray($context);
                    self::assertArrayHasKey('source_path', $context);
                    self::assertArrayHasKey('parser', $context);
                    self::assertArrayHasKey('content_length', $context);
                }
            });

        // Expect info log for success
        $this->logger->expects(self::once())
            ->method('info')
            ->with(
                'Configuration file parsed successfully',
                self::callback(function ($context): bool {
                    return isset($context['file_path'], $context['parser'], $context['data_keys'], $context['warnings']);
                }),
            );

        $this->parser->parseFile($this->tempFile);
    }

    public function testCompleteParsingFlow(): void
    {
        $content = 'complex test content';
        $originalData = ['original' => 'data', 'complex' => ['nested' => 'structure']];
        $processedData = ['processed' => 'data', 'complex' => ['nested' => 'structure']];
        $warnings = ['Processing warning'];

        file_put_contents($this->tempFile, $content);

        $this->parser->setParseResult($originalData);
        $this->parser->setPostProcessedData($processedData);
        $this->parser->setValidationResult([
            'valid' => true,
            'errors' => [],
            'warnings' => $warnings,
        ]);

        $result = $this->parser->parseFile($this->tempFile);

        self::assertTrue($result->isSuccessful());
        self::assertSame($processedData, $result->getData());
        self::assertSame($warnings, $result->getWarnings());
        self::assertSame($this->tempFile, $result->getSourcePath());
        self::assertSame('test', $result->getFormat());

        // Verify metadata
        self::assertSame('TestParser', $result->getMetadataValue('parser'));
        self::assertSame(\strlen($content), $result->getMetadataValue('content_length'));
        self::assertSame(2, $result->getMetadataValue('data_keys'));
        self::assertTrue($result->getMetadataValue('has_nested_data'));
    }
}

/**
 * Testable implementation of AbstractConfigurationParser for testing.
 */
class TestableAbstractParser extends AbstractConfigurationParser
{
    private array $parseResult = [];
    private array $postProcessedData = [];
    /** @var array{valid: bool, errors: array<string>, warnings: array<string>}|null */
    private ?array $validationResult = null;
    private ?\Throwable $throwException = null;
    private array $requiredDependencies = [];
    private bool $supportsSpecificFiles = true;

    public function getFormat(): string
    {
        return 'test';
    }

    public function getName(): string
    {
        return 'TestParser';
    }

    public function getSupportedExtensions(): array
    {
        return ['test'];
    }

    public function setParseResult(array $result): void
    {
        $this->parseResult = $result;
    }

    public function setPostProcessedData(array $data): void
    {
        $this->postProcessedData = $data;
    }

    /**
     * @param array{valid: bool, errors: array<string>, warnings: array<string>} $result
     */
    public function setValidationResult(array $result): void
    {
        $this->validationResult = $result;
    }

    public function setThrowException(\Throwable $exception): void
    {
        $this->throwException = $exception;
    }

    public function setRequiredDependencies(array $dependencies): void
    {
        $this->requiredDependencies = $dependencies;
    }

    public function setSupportsSpecific(bool $supports): void
    {
        $this->supportsSpecificFiles = $supports;
    }

    // Test accessors for protected methods
    public function testGetOption(string $key, mixed $default = null): mixed
    {
        return $this->getOption($key, $default);
    }

    public function testSetOption(string $key, mixed $value): void
    {
        $this->setOption($key, $value);
    }

    public function testIsFileAccessible(string $filePath): bool
    {
        return $this->isFileAccessible($filePath);
    }

    public function testReadFileContent(string $filePath): string
    {
        return $this->readFileContent($filePath);
    }

    public function testValidateParsedData(array $data, string $sourcePath): array
    {
        return $this->validateParsedData($data, $sourcePath);
    }

    public function testPostProcessData(array $data, string $sourcePath): array
    {
        return $this->postProcessData($data, $sourcePath);
    }

    public function testHasNestedArrays(array $data): bool
    {
        return $this->hasNestedArrays($data);
    }

    protected function doParse(string $content, string $sourcePath): array
    {
        if (null !== $this->throwException) {
            throw $this->throwException;
        }

        return $this->parseResult;
    }

    /**
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     */
    protected function validateParsedData(array $data, string $sourcePath): array
    {
        if (null !== $this->validationResult) {
            return $this->validationResult;
        }

        return parent::validateParsedData($data, $sourcePath);
    }

    protected function postProcessData(array $data, string $sourcePath): array
    {
        return $this->postProcessedData ?: parent::postProcessData($data, $sourcePath);
    }

    protected function supportsSpecific(string $filePath): bool
    {
        return $this->supportsSpecificFiles;
    }

    public function getRequiredDependencies(): array
    {
        return $this->requiredDependencies;
    }
}
