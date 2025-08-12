<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\ValueObject;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationData;
use PHPUnit\Framework\TestCase;

/**
 * Test case for ConfigurationData value object.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationData
 */
class ConfigurationDataTest extends TestCase
{
    private array $sampleData;
    private array $sampleErrors;
    private array $sampleWarnings;
    private array $sampleMetadata;
    private \DateTimeImmutable $sampleTimestamp;

    protected function setUp(): void
    {
        $this->sampleData = [
            'database' => [
                'connections' => [
                    'default' => [
                        'host' => 'localhost',
                        'dbname' => 'test_db',
                        'port' => 3306,
                    ],
                ],
            ],
            'system' => [
                'sitename' => 'Test Site',
                'debug' => true,
                'features' => [
                    'feature1' => 'enabled',
                    'feature2' => 'disabled',
                ],
            ],
            'mail' => [
                'transport' => 'sendmail',
                'enabled' => 'true',
            ],
        ];

        $this->sampleErrors = [
            'Missing required database password',
            'Invalid mail configuration',
        ];

        $this->sampleWarnings = [
            'Debug mode enabled in production',
            'Deprecated configuration key used',
        ];

        $this->sampleMetadata = [
            'parser_version' => '1.0.0',
            'file_size' => 2048,
            'parse_time_ms' => 150,
        ];

        $this->sampleTimestamp = new \DateTimeImmutable('2023-01-01 12:00:00');
    }

    public function testBasicConfigurationDataCreation(): void
    {
        $config = new ConfigurationData(
            $this->sampleData,
            'php',
            '/path/to/config.php',
        );

        self::assertSame($this->sampleData, $config->getData());
        self::assertSame('php', $config->getFormat());
        self::assertSame('/path/to/config.php', $config->getSource());
        self::assertSame([], $config->getValidationErrors());
        self::assertSame([], $config->getValidationWarnings());
        self::assertSame([], $config->getMetadata());
        self::assertFalse($config->hasValidationErrors());
        self::assertFalse($config->hasValidationWarnings());
        self::assertTrue($config->isValid());
    }

    public function testConfigurationDataWithAllParameters(): void
    {
        $config = new ConfigurationData(
            $this->sampleData,
            'yaml',
            '/path/to/config.yaml',
            $this->sampleErrors,
            $this->sampleWarnings,
            $this->sampleTimestamp,
            $this->sampleMetadata,
        );

        self::assertSame($this->sampleData, $config->getData());
        self::assertSame('yaml', $config->getFormat());
        self::assertSame('/path/to/config.yaml', $config->getSource());
        self::assertSame($this->sampleErrors, $config->getValidationErrors());
        self::assertSame($this->sampleWarnings, $config->getValidationWarnings());
        self::assertSame($this->sampleTimestamp, $config->getLoadedAt());
        self::assertSame($this->sampleMetadata, $config->getMetadata());
        self::assertTrue($config->hasValidationErrors());
        self::assertTrue($config->hasValidationWarnings());
        self::assertFalse($config->isValid());
    }

    public function testGetLoadedAtWithDefaultTimestamp(): void
    {
        $before = new \DateTimeImmutable();
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');
        $after = new \DateTimeImmutable();

        $loadedAt = $config->getLoadedAt();
        self::assertGreaterThanOrEqual($before, $loadedAt);
        self::assertLessThanOrEqual($after, $loadedAt);
    }

    public function testGetValueWithSimpleKey(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        self::assertSame('Test Site', $config->getValue('system.sitename'));
        self::assertTrue($config->getValue('system.debug'));
        self::assertSame('sendmail', $config->getValue('mail.transport'));
        self::assertNull($config->getValue('nonexistent'));
        self::assertSame('default_value', $config->getValue('nonexistent', 'default_value'));
    }

    public function testGetValueWithDotNotation(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        self::assertSame('localhost', $config->getValue('database.connections.default.host'));
        self::assertSame(3306, $config->getValue('database.connections.default.port'));
        self::assertSame(['feature1' => 'enabled', 'feature2' => 'disabled'], $config->getValue('system.features'));
        self::assertSame('enabled', $config->getValue('system.features.feature1'));
    }

    public function testGetValueWithNonArrayIntermediate(): void
    {
        $data = [
            'scalar_value' => 'test',
            'nested' => [
                'value' => 'nested_test',
            ],
        ];

        $config = new ConfigurationData($data, 'php', '/path/to/config.php');

        self::assertSame('default', $config->getValue('scalar_value.nested', 'default'));
        self::assertSame('nested_test', $config->getValue('nested.value'));
    }

    public function testHasValueWithValidPaths(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        self::assertTrue($config->hasValue('database'));
        self::assertTrue($config->hasValue('database.connections'));
        self::assertTrue($config->hasValue('database.connections.default'));
        self::assertTrue($config->hasValue('database.connections.default.host'));
        self::assertTrue($config->hasValue('system.features.feature1'));
        self::assertFalse($config->hasValue('nonexistent'));
        self::assertFalse($config->hasValue('database.nonexistent'));
        self::assertFalse($config->hasValue('database.connections.default.nonexistent'));
    }

    public function testGetStringWithTypeChecking(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        self::assertSame('Test Site', $config->getString('system.sitename'));
        self::assertSame('sendmail', $config->getString('mail.transport'));
        self::assertSame('', $config->getString('system.debug')); // Boolean, returns default
        self::assertSame('default', $config->getString('nonexistent', 'default'));
        self::assertSame('', $config->getString('nonexistent'));
    }

    public function testGetIntWithTypeChecking(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        self::assertSame(3306, $config->getInt('database.connections.default.port'));
        self::assertSame(0, $config->getInt('system.sitename')); // String, returns default
        self::assertSame(42, $config->getInt('nonexistent', 42));
        self::assertSame(0, $config->getInt('nonexistent'));
    }

    public function testGetIntWithNumericStringConversion(): void
    {
        $data = [
            'string_number' => '123',
            'float_string' => '45.67',
            'non_numeric' => 'abc',
        ];

        $config = new ConfigurationData($data, 'php', '/path/to/config.php');

        self::assertSame(123, $config->getInt('string_number'));
        self::assertSame(45, $config->getInt('float_string'));
        self::assertSame(0, $config->getInt('non_numeric'));
        self::assertSame(999, $config->getInt('non_numeric', 999));
    }

    public function testGetBoolWithTypeChecking(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        self::assertTrue($config->getBool('system.debug'));
        self::assertFalse($config->getBool('system.sitename')); // String, returns default
        self::assertTrue($config->getBool('nonexistent', true));
        self::assertFalse($config->getBool('nonexistent'));
    }

    public function testGetBoolWithStringConversion(): void
    {
        $data = [
            'true_string' => 'true',
            'false_string' => 'false',
            'one_string' => '1',
            'zero_string' => '0',
            'yes_string' => 'YES',
            'no_string' => 'no',
            'on_string' => 'on',
            'off_string' => 'OFF',
            'empty_string' => '',
            'other_string' => 'maybe',
        ];

        $config = new ConfigurationData($data, 'php', '/path/to/config.php');

        self::assertTrue($config->getBool('true_string'));
        self::assertFalse($config->getBool('false_string'));
        self::assertTrue($config->getBool('one_string'));
        self::assertFalse($config->getBool('zero_string'));
        self::assertTrue($config->getBool('yes_string'));
        self::assertFalse($config->getBool('no_string'));
        self::assertTrue($config->getBool('on_string'));
        self::assertFalse($config->getBool('off_string'));
        self::assertFalse($config->getBool('empty_string'));
        self::assertFalse($config->getBool('other_string')); // Unknown string, returns default
        self::assertTrue($config->getBool('other_string', true)); // Unknown string, returns provided default
    }

    public function testGetArrayWithTypeChecking(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        self::assertSame(['feature1' => 'enabled', 'feature2' => 'disabled'], $config->getArray('system.features'));
        self::assertSame(['host' => 'localhost', 'dbname' => 'test_db', 'port' => 3306], $config->getArray('database.connections.default'));
        self::assertSame([], $config->getArray('system.sitename')); // String, returns default
        self::assertSame(['default' => 'value'], $config->getArray('nonexistent', ['default' => 'value']));
        self::assertSame([], $config->getArray('nonexistent'));
    }

    public function testGetMetadataValue(): void
    {
        $config = new ConfigurationData([], 'php', '/path/to/config.php', [], [], null, $this->sampleMetadata);

        self::assertSame('1.0.0', $config->getMetadataValue('parser_version'));
        self::assertSame(2048, $config->getMetadataValue('file_size'));
        self::assertSame('default', $config->getMetadataValue('nonexistent', 'default'));
        self::assertNull($config->getMetadataValue('nonexistent'));
    }

    public function testGetKeys(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        $keys = $config->getKeys();
        self::assertSame(['database', 'system', 'mail'], $keys);
    }

    public function testGetSection(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php', [], [], null, $this->sampleMetadata);

        $systemSection = $config->getSection('system');

        self::assertInstanceOf(ConfigurationData::class, $systemSection);
        self::assertSame($this->sampleData['system'], $systemSection->getData());
        self::assertSame('php', $systemSection->getFormat());
        self::assertSame('/path/to/config.php.system', $systemSection->getSource());
        self::assertSame('system', $systemSection->getMetadataValue('section'));

        // Original metadata should be preserved
        self::assertSame('1.0.0', $systemSection->getMetadataValue('parser_version'));
    }

    public function testGetSectionWithNonExistentSection(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Configuration section 'nonexistent' does not exist");

        $config->getSection('nonexistent');
    }

    public function testGetSectionWithNonArraySection(): void
    {
        $data = ['scalar_section' => 'not an array'];
        $config = new ConfigurationData($data, 'php', '/path/to/config.php');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Configuration section 'scalar_section' is not an array");

        $config->getSection('scalar_section');
    }

    public function testIsEmpty(): void
    {
        $emptyConfig = new ConfigurationData([], 'php', '/path/to/empty.php');
        $nonEmptyConfig = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        self::assertTrue($emptyConfig->isEmpty());
        self::assertFalse($nonEmptyConfig->isEmpty());
    }

    public function testCount(): void
    {
        $emptyConfig = new ConfigurationData([], 'php', '/path/to/empty.php');
        $nonEmptyConfig = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        self::assertSame(0, $emptyConfig->count());
        self::assertSame(3, $nonEmptyConfig->count()); // database, system, mail
    }

    public function testWithMetadata(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php', [], [], null, $this->sampleMetadata);

        $additionalMetadata = [
            'new_key' => 'new_value',
            'parser_version' => '2.0.0', // Override existing
        ];

        $newConfig = $config->withMetadata($additionalMetadata);

        // Original should be unchanged
        self::assertSame('1.0.0', $config->getMetadataValue('parser_version'));
        self::assertNull($config->getMetadataValue('new_key'));

        // New config should have merged metadata
        self::assertSame('2.0.0', $newConfig->getMetadataValue('parser_version')); // overridden
        self::assertSame('new_value', $newConfig->getMetadataValue('new_key')); // added
        self::assertSame(2048, $newConfig->getMetadataValue('file_size')); // preserved

        // Other properties should be the same
        self::assertSame($this->sampleData, $newConfig->getData());
        self::assertSame('php', $newConfig->getFormat());
        self::assertSame('/path/to/config.php', $newConfig->getSource());
    }

    public function testWithValidation(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php', ['existing_error'], ['existing_warning']);

        $additionalErrors = ['new_error_1', 'new_error_2'];
        $additionalWarnings = ['new_warning'];

        $newConfig = $config->withValidation($additionalErrors, $additionalWarnings);

        // Original should be unchanged
        self::assertSame(['existing_error'], $config->getValidationErrors());
        self::assertSame(['existing_warning'], $config->getValidationWarnings());

        // New config should have merged validation issues
        self::assertSame(['existing_error', 'new_error_1', 'new_error_2'], $newConfig->getValidationErrors());
        self::assertSame(['existing_warning', 'new_warning'], $newConfig->getValidationWarnings());
        self::assertTrue($newConfig->hasValidationErrors());
        self::assertTrue($newConfig->hasValidationWarnings());
        self::assertFalse($newConfig->isValid());

        // Other properties should be the same
        self::assertSame($this->sampleData, $newConfig->getData());
        self::assertSame('php', $newConfig->getFormat());
        self::assertSame('/path/to/config.php', $newConfig->getSource());
    }

    public function testWithValidationWithErrorsOnly(): void
    {
        $config = new ConfigurationData($this->sampleData, 'php', '/path/to/config.php');

        $errors = ['validation_error'];
        $newConfig = $config->withValidation($errors);

        self::assertSame(['validation_error'], $newConfig->getValidationErrors());
        self::assertSame([], $newConfig->getValidationWarnings());
        self::assertTrue($newConfig->hasValidationErrors());
        self::assertFalse($newConfig->hasValidationWarnings());
        self::assertFalse($newConfig->isValid());
    }

    public function testToArrayWithBasicData(): void
    {
        $config = new ConfigurationData(
            $this->sampleData,
            'yaml',
            '/path/to/config.yaml',
            $this->sampleErrors,
            $this->sampleWarnings,
            $this->sampleTimestamp,
            $this->sampleMetadata,
        );

        $array = $config->toArray();

        self::assertSame($this->sampleData, $array['data']);
        self::assertSame('yaml', $array['format']);
        self::assertSame('/path/to/config.yaml', $array['source']);
        self::assertSame($this->sampleErrors, $array['validation_errors']);
        self::assertSame($this->sampleWarnings, $array['validation_warnings']);
        self::assertSame($this->sampleTimestamp->format(\DateTimeInterface::ATOM), $array['loaded_at']);
        self::assertSame($this->sampleMetadata, $array['metadata']);

        // Test statistics
        self::assertArrayHasKey('statistics', $array);
        self::assertSame(3, $array['statistics']['key_count']);
        self::assertFalse($array['statistics']['is_empty']);
        self::assertFalse($array['statistics']['is_valid']); // Has errors
        self::assertTrue($array['statistics']['has_warnings']);
        self::assertTrue($array['statistics']['has_nested_data']);
    }

    public function testToArrayWithEmptyData(): void
    {
        $config = new ConfigurationData([], 'php', '/path/to/empty.php');

        $array = $config->toArray();

        self::assertSame([], $array['data']);
        self::assertSame([], $array['validation_errors']);
        self::assertSame([], $array['validation_warnings']);
        self::assertSame([], $array['metadata']);

        // Test statistics for empty data
        self::assertSame(0, $array['statistics']['key_count']);
        self::assertTrue($array['statistics']['is_empty']);
        self::assertTrue($array['statistics']['is_valid']); // No errors
        self::assertFalse($array['statistics']['has_warnings']);
        self::assertFalse($array['statistics']['has_nested_data']);
    }

    public function testToArrayWithFlatData(): void
    {
        $flatData = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 123,
        ];

        $config = new ConfigurationData($flatData, 'php', '/path/to/flat.php');
        $array = $config->toArray();

        self::assertFalse($array['statistics']['has_nested_data']);
    }

    public function testImmutabilityOfConfigurationData(): void
    {
        $originalData = ['key' => ['nested' => 'value']];
        $originalErrors = ['error1'];
        $originalWarnings = ['warning1'];
        $originalMetadata = ['meta' => 'value'];

        $config = new ConfigurationData($originalData, 'php', '/path', $originalErrors, $originalWarnings, null, $originalMetadata);

        // Modify original arrays
        $originalData['key']['nested'] = 'modified';
        $originalErrors[] = 'new_error';
        $originalWarnings[] = 'new_warning';
        $originalMetadata['meta'] = 'modified';

        // Verify config is not affected
        self::assertSame('value', $config->getValue('key.nested'));
        self::assertSame(['error1'], $config->getValidationErrors());
        self::assertSame(['warning1'], $config->getValidationWarnings());
        self::assertSame(['meta' => 'value'], $config->getMetadata());
    }

    /**
     * @dataProvider specialKeyPathProvider
     */
    public function testGetValueWithSpecialKeyPaths(string $keyPath, ?string $expectedValue, bool $shouldExist): void
    {
        $data = [
            '' => 'empty_key_value',
            'key.with.dots' => 'dotted_value',
            'normal_key' => [
                '' => 'nested_empty_key',
                'sub' => 'nested_value',
            ],
        ];

        $config = new ConfigurationData($data, 'php', '/path/to/config.php');

        if ($shouldExist) {
            self::assertTrue($config->hasValue($keyPath));
            self::assertSame($expectedValue, $config->getValue($keyPath));
        } else {
            self::assertFalse($config->hasValue($keyPath));
            self::assertNull($config->getValue($keyPath));
        }
    }

    /**
     * @return array<string, array{string, mixed, bool}>
     */
    public function specialKeyPathProvider(): array
    {
        return [
            'empty key' => ['', 'empty_key_value', true],
            'key with dots' => ['key.with.dots', 'dotted_value', true],
            'nested empty key' => ['normal_key.', 'nested_empty_key', true],
            'nested sub key' => ['normal_key.sub', 'nested_value', true],
            'nonexistent' => ['nonexistent', null, false],
        ];
    }

    public function testComplexNestedStructure(): void
    {
        $complexData = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'deep_value' => 'found_it',
                            'deep_array' => [1, 2, 3],
                            'deep_bool' => true,
                        ],
                    ],
                ],
            ],
            'services' => [
                'service1' => [
                    'class' => 'App\\Service\\Service1',
                    'arguments' => ['@dependency1', '%param%'],
                    'tags' => [
                        ['name' => 'app.service', 'priority' => 100],
                        ['name' => 'kernel.event_listener'],
                    ],
                ],
            ],
        ];

        $config = new ConfigurationData($complexData, 'yaml', '/complex.yaml');

        // Test deep nesting access
        self::assertSame('found_it', $config->getValue('level1.level2.level3.level4.deep_value'));
        self::assertSame([1, 2, 3], $config->getValue('level1.level2.level3.level4.deep_array'));
        self::assertTrue($config->getValue('level1.level2.level3.level4.deep_bool'));

        // Test service configuration access
        self::assertSame('App\\Service\\Service1', $config->getValue('services.service1.class'));
        self::assertSame(['@dependency1', '%param%'], $config->getValue('services.service1.arguments'));
        self::assertSame('app.service', $config->getValue('services.service1.tags.0.name'));
        self::assertSame(100, $config->getValue('services.service1.tags.0.priority'));

        // Test type-safe accessors with complex data
        self::assertSame('App\\Service\\Service1', $config->getString('services.service1.class'));
        self::assertSame(100, $config->getInt('services.service1.tags.0.priority'));
        self::assertTrue($config->getBool('level1.level2.level3.level4.deep_bool'));
        self::assertSame(['@dependency1', '%param%'], $config->getArray('services.service1.arguments'));

        // Test section extraction
        $serviceSection = $config->getSection('services');
        self::assertSame('App\\Service\\Service1', $serviceSection->getValue('service1.class'));

        $service1Section = $serviceSection->getSection('service1');
        self::assertSame('App\\Service\\Service1', $service1Section->getValue('class'));
        self::assertSame(['@dependency1', '%param%'], $service1Section->getValue('arguments'));
    }
}
