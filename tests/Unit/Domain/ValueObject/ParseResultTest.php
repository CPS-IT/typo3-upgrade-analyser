<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\ValueObject;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ParseResult;
use PHPUnit\Framework\TestCase;

/**
 * Test case for ParseResult value object.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Domain\ValueObject\ParseResult
 */
class ParseResultTest extends TestCase
{
    private array $sampleData;
    private array $sampleMetadata;
    private array $sampleErrors;
    private array $sampleWarnings;

    protected function setUp(): void
    {
        $this->sampleData = [
            'database' => [
                'connections' => [
                    'default' => [
                        'host' => 'localhost',
                        'dbname' => 'test',
                    ],
                ],
            ],
            'features' => [
                'debug' => true,
            ],
        ];

        $this->sampleMetadata = [
            'parser_version' => '1.0.0',
            'parse_time_ms' => 150,
            'file_size' => 2048,
        ];

        $this->sampleErrors = [
            'Syntax error at line 15',
            'Invalid configuration key "invalid_key"',
        ];

        $this->sampleWarnings = [
            'Deprecated configuration option found',
            'Consider updating to newer syntax',
        ];
    }

    public function testSuccessfulResultCreation(): void
    {
        $result = ParseResult::success(
            $this->sampleData,
            'php',
            '/path/to/config.php',
            $this->sampleWarnings,
            $this->sampleMetadata,
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame($this->sampleData, $result->getData());
        self::assertSame('php', $result->getFormat());
        self::assertSame('/path/to/config.php', $result->getSourcePath());
        self::assertSame([], $result->getErrors());
        self::assertSame($this->sampleWarnings, $result->getWarnings());
        self::assertSame($this->sampleMetadata, $result->getMetadata());
        self::assertInstanceOf(\DateTimeImmutable::class, $result->getParsedAt());
        self::assertFalse($result->hasErrors());
        self::assertTrue($result->hasWarnings());
    }

    public function testSuccessfulResultWithoutOptionalData(): void
    {
        $result = ParseResult::success($this->sampleData, 'yaml', '/path/to/config.yaml');

        self::assertTrue($result->isSuccessful());
        self::assertSame($this->sampleData, $result->getData());
        self::assertSame('yaml', $result->getFormat());
        self::assertSame('/path/to/config.yaml', $result->getSourcePath());
        self::assertSame([], $result->getErrors());
        self::assertSame([], $result->getWarnings());
        self::assertSame([], $result->getMetadata());
        self::assertFalse($result->hasErrors());
        self::assertFalse($result->hasWarnings());
    }

    public function testFailedResultCreation(): void
    {
        $result = ParseResult::failure(
            $this->sampleErrors,
            'php',
            '/path/to/invalid.php',
            $this->sampleWarnings,
            $this->sampleMetadata,
        );

        self::assertFalse($result->isSuccessful());
        self::assertSame([], $result->getData());
        self::assertSame('php', $result->getFormat());
        self::assertSame('/path/to/invalid.php', $result->getSourcePath());
        self::assertSame($this->sampleErrors, $result->getErrors());
        self::assertSame($this->sampleWarnings, $result->getWarnings());
        self::assertSame($this->sampleMetadata, $result->getMetadata());
        self::assertTrue($result->hasErrors());
        self::assertTrue($result->hasWarnings());
    }

    public function testFailedResultWithoutOptionalData(): void
    {
        $result = ParseResult::failure($this->sampleErrors, 'yaml', '/path/to/invalid.yaml');

        self::assertFalse($result->isSuccessful());
        self::assertSame([], $result->getData());
        self::assertSame('yaml', $result->getFormat());
        self::assertSame('/path/to/invalid.yaml', $result->getSourcePath());
        self::assertSame($this->sampleErrors, $result->getErrors());
        self::assertSame([], $result->getWarnings());
        self::assertSame([], $result->getMetadata());
        self::assertTrue($result->hasErrors());
        self::assertFalse($result->hasWarnings());
    }

    public function testGetValueWithSimpleKey(): void
    {
        $result = ParseResult::success($this->sampleData, 'php', '/path/to/config.php');

        self::assertTrue($result->getValue('features.debug'));
        self::assertSame('localhost', $result->getValue('database.connections.default.host'));
        self::assertSame('default_value', $result->getValue('nonexistent.key', 'default_value'));
        self::assertNull($result->getValue('nonexistent.key'));
    }

    public function testGetValueWithDotNotation(): void
    {
        $result = ParseResult::success($this->sampleData, 'php', '/path/to/config.php');

        self::assertSame(['host' => 'localhost', 'dbname' => 'test'], $result->getValue('database.connections.default'));
        self::assertSame('test', $result->getValue('database.connections.default.dbname'));
        self::assertSame(['debug' => true], $result->getValue('features'));
    }

    public function testGetValueWithNonArrayIntermediate(): void
    {
        $data = [
            'scalar_value' => 'test',
            'nested' => [
                'value' => 'nested_test',
            ],
        ];

        $result = ParseResult::success($data, 'php', '/path/to/config.php');

        self::assertSame('default', $result->getValue('scalar_value.nested', 'default'));
        self::assertSame('nested_test', $result->getValue('nested.value'));
    }

    public function testGetValueOnFailedResult(): void
    {
        $result = ParseResult::failure($this->sampleErrors, 'php', '/path/to/invalid.php');

        self::assertNull($result->getValue('any.key'));
        self::assertSame('default', $result->getValue('any.key', 'default'));
    }

    public function testHasValueWithValidPaths(): void
    {
        $result = ParseResult::success($this->sampleData, 'php', '/path/to/config.php');

        self::assertTrue($result->hasValue('database'));
        self::assertTrue($result->hasValue('database.connections'));
        self::assertTrue($result->hasValue('database.connections.default'));
        self::assertTrue($result->hasValue('database.connections.default.host'));
        self::assertTrue($result->hasValue('features.debug'));
        self::assertFalse($result->hasValue('nonexistent'));
        self::assertFalse($result->hasValue('database.nonexistent'));
        self::assertFalse($result->hasValue('database.connections.default.nonexistent'));
    }

    public function testHasValueOnFailedResult(): void
    {
        $result = ParseResult::failure($this->sampleErrors, 'php', '/path/to/invalid.php');

        self::assertFalse($result->hasValue('any.key'));
    }

    public function testGetMetadataValue(): void
    {
        $result = ParseResult::success([], 'php', '/path/to/config.php', [], $this->sampleMetadata);

        self::assertSame('1.0.0', $result->getMetadataValue('parser_version'));
        self::assertSame(150, $result->getMetadataValue('parse_time_ms'));
        self::assertSame('default', $result->getMetadataValue('nonexistent', 'default'));
        self::assertNull($result->getMetadataValue('nonexistent'));
    }

    public function testGetFirstError(): void
    {
        $result = ParseResult::failure($this->sampleErrors, 'php', '/path/to/invalid.php');

        self::assertSame('Syntax error at line 15', $result->getFirstError());
    }

    public function testGetFirstErrorOnSuccessfulResult(): void
    {
        $result = ParseResult::success($this->sampleData, 'php', '/path/to/config.php');

        self::assertNull($result->getFirstError());
    }

    public function testGetFirstErrorOnEmptyErrors(): void
    {
        $result = ParseResult::failure([], 'php', '/path/to/invalid.php');

        self::assertNull($result->getFirstError());
    }

    public function testGetFirstWarning(): void
    {
        $result = ParseResult::success([], 'php', '/path/to/config.php', $this->sampleWarnings);

        self::assertSame('Deprecated configuration option found', $result->getFirstWarning());
    }

    public function testGetFirstWarningOnEmptyWarnings(): void
    {
        $result = ParseResult::success($this->sampleData, 'php', '/path/to/config.php');

        self::assertNull($result->getFirstWarning());
    }

    public function testGetSummaryForSuccessfulResult(): void
    {
        $result = ParseResult::success([], 'php', '/path/to/LocalConfiguration.php');

        $summary = $result->getSummary();
        self::assertStringContainsString('Php configuration parsed successfully', $summary);
        self::assertStringContainsString('LocalConfiguration.php', $summary);
    }

    public function testGetSummaryForSuccessfulResultWithWarnings(): void
    {
        $result = ParseResult::success([], 'yaml', '/path/to/Services.yaml', $this->sampleWarnings);

        $summary = $result->getSummary();
        self::assertStringContainsString('Yaml configuration parsed successfully', $summary);
        self::assertStringContainsString('Services.yaml', $summary);
        self::assertStringContainsString('(2 warnings)', $summary);
    }

    public function testGetSummaryForSuccessfulResultWithSingleWarning(): void
    {
        $warnings = ['Single warning'];
        $result = ParseResult::success([], 'yaml', '/path/to/config.yaml', $warnings);

        $summary = $result->getSummary();
        self::assertStringContainsString('(1 warning)', $summary);
    }

    public function testGetSummaryForFailedResult(): void
    {
        $result = ParseResult::failure($this->sampleErrors, 'php', '/path/to/invalid.php');

        $summary = $result->getSummary();
        self::assertStringContainsString('Php configuration parsing failed', $summary);
        self::assertStringContainsString('Syntax error at line 15', $summary);
        self::assertStringContainsString('(2 errors)', $summary);
    }

    public function testGetSummaryForFailedResultWithSingleError(): void
    {
        $errors = ['Single error'];
        $result = ParseResult::failure($errors, 'yaml', '/path/to/invalid.yaml');

        $summary = $result->getSummary();
        self::assertStringContainsString('(1 error)', $summary);
    }

    public function testGetSummaryForFailedResultWithEmptyErrors(): void
    {
        $result = ParseResult::failure([], 'php', '/path/to/invalid.php');

        $summary = $result->getSummary();
        self::assertStringContainsString('Unknown error', $summary);
        self::assertStringContainsString('(0 errors)', $summary);
    }

    public function testToArrayForSuccessfulResult(): void
    {
        $result = ParseResult::success(
            $this->sampleData,
            'php',
            '/path/to/config.php',
            $this->sampleWarnings,
            $this->sampleMetadata,
        );

        $array = $result->toArray();

        self::assertTrue($array['successful']);
        self::assertSame($this->sampleData, $array['data']);
        self::assertSame('php', $array['format']);
        self::assertSame('/path/to/config.php', $array['source_path']);
        self::assertSame([], $array['errors']);
        self::assertSame($this->sampleWarnings, $array['warnings']);
        self::assertSame($this->sampleMetadata, $array['metadata']);
        self::assertIsString($array['parsed_at']);
        self::assertStringContainsString('successfully', $array['summary']);

        // Test statistics
        self::assertArrayHasKey('statistics', $array);
        self::assertSame(0, $array['statistics']['error_count']);
        self::assertSame(2, $array['statistics']['warning_count']);
        self::assertSame(2, $array['statistics']['data_keys']);
        self::assertTrue($array['statistics']['has_nested_data']);
    }

    public function testToArrayForFailedResult(): void
    {
        $result = ParseResult::failure($this->sampleErrors, 'yaml', '/path/to/invalid.yaml');

        $array = $result->toArray();

        self::assertFalse($array['successful']);
        self::assertSame([], $array['data']);
        self::assertSame('yaml', $array['format']);
        self::assertSame('/path/to/invalid.yaml', $array['source_path']);
        self::assertSame($this->sampleErrors, $array['errors']);
        self::assertSame([], $array['warnings']);
        self::assertSame([], $array['metadata']);
        self::assertStringContainsString('failed', $array['summary']);

        // Test statistics
        self::assertSame(2, $array['statistics']['error_count']);
        self::assertSame(0, $array['statistics']['warning_count']);
        self::assertSame(0, $array['statistics']['data_keys']);
        self::assertFalse($array['statistics']['has_nested_data']);
    }

    public function testHasNestedDataWithFlatData(): void
    {
        $flatData = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 123,
        ];

        $result = ParseResult::success($flatData, 'php', '/path/to/config.php');
        $array = $result->toArray();

        self::assertFalse($array['statistics']['has_nested_data']);
    }

    public function testHasNestedDataWithNestedData(): void
    {
        $nestedData = [
            'key1' => 'value1',
            'nested' => [
                'subkey' => 'subvalue',
            ],
        ];

        $result = ParseResult::success($nestedData, 'php', '/path/to/config.php');
        $array = $result->toArray();

        self::assertTrue($array['statistics']['has_nested_data']);
    }

    public function testHasNestedDataWithEmptyData(): void
    {
        $result = ParseResult::success([], 'php', '/path/to/config.php');
        $array = $result->toArray();

        self::assertFalse($array['statistics']['has_nested_data']);
    }

    public function testHasNestedDataOnFailedResult(): void
    {
        $result = ParseResult::failure($this->sampleErrors, 'php', '/path/to/invalid.php');
        $array = $result->toArray();

        self::assertFalse($array['statistics']['has_nested_data']);
    }

    public function testParsedAtTimestampIsRecent(): void
    {
        $before = new \DateTimeImmutable();
        $result = ParseResult::success($this->sampleData, 'php', '/path/to/config.php');
        $after = new \DateTimeImmutable();

        $parsedAt = $result->getParsedAt();
        self::assertGreaterThanOrEqual($before, $parsedAt);
        self::assertLessThanOrEqual($after, $parsedAt);
    }

    public function testParsedAtFormatInToArray(): void
    {
        $result = ParseResult::success($this->sampleData, 'php', '/path/to/config.php');
        $array = $result->toArray();

        $parsedAt = $array['parsed_at'];
        self::assertIsString($parsedAt);

        // Verify it's a valid ISO 8601 timestamp
        $dateTime = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $parsedAt);
        self::assertInstanceOf(\DateTimeImmutable::class, $dateTime);
    }

    public function testImmutabilityOfResult(): void
    {
        $originalData = ['key' => 'value'];
        $originalErrors = ['error1'];
        $originalWarnings = ['warning1'];
        $originalMetadata = ['meta' => 'value'];

        $result = ParseResult::success($originalData, 'php', '/path/to/config.php', $originalWarnings, $originalMetadata);

        // Modify original arrays
        $originalData['key'] = 'modified';
        $originalErrors[] = 'new_error';
        $originalWarnings[] = 'new_warning';
        $originalMetadata['meta'] = 'modified';

        // Verify result is not affected
        self::assertSame('value', $result->getData()['key']);
        self::assertSame(['warning1'], $result->getWarnings());
        self::assertSame(['meta' => 'value'], $result->getMetadata());
    }

    /**
     * @dataProvider specialKeyPathProvider
     */
    public function testGetValueWithSpecialKeyPaths(string $keyPath, $expectedValue, bool $shouldExist): void
    {
        $data = [
            '' => 'empty_key_value',
            'key.with.dots' => 'dotted_value',
            'normal_key' => [
                '' => 'nested_empty_key',
                'sub' => 'nested_value',
            ],
        ];

        $result = ParseResult::success($data, 'php', '/path/to/config.php');

        if ($shouldExist) {
            self::assertTrue($result->hasValue($keyPath));
            self::assertSame($expectedValue, $result->getValue($keyPath));
        } else {
            self::assertFalse($result->hasValue($keyPath));
            self::assertNull($result->getValue($keyPath));
        }
    }

    /**
     * @return array<string, array{string, mixed, bool}>
     */
    public function specialKeyPathProvider(): array
    {
        return [
            'empty key' => ['', 'empty_key_value', true],
            'key with dots lookup fails (dot notation)' => ['key.with.dots', null, false], // Dot notation treats this as nested path
            'nested empty key' => ['normal_key.', 'nested_empty_key', true],
            'nested sub key' => ['normal_key.sub', 'nested_value', true],
            'nonexistent' => ['nonexistent', null, false],
        ];
    }
}
