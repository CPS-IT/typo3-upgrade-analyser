<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Cache;

use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\SerializableInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test interface definition and contract validation.
 */
final class SerializableInterfaceTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(SerializableInterface::class));
    }

    public function testInterfaceCanBeImplemented(): void
    {
        // Create an anonymous class implementing the interface to test it's properly defined
        $implementation = new class implements SerializableInterface {
            private string $value;

            public function __construct(string $value = 'test')
            {
                $this->value = $value;
            }

            public function toArray(): array
            {
                return ['value' => $this->value];
            }

            public static function fromArray(array $data): static
            {
                return new static($data['value'] ?? 'default');
            }

            public function getValue(): string
            {
                return $this->value;
            }
        };

        // Test toArray method
        $array = $implementation->toArray();
        $this->assertArrayHasKey('value', $array);
        $this->assertSame('test', $array['value']);

        // Test fromArray method
        $recreated = $implementation::fromArray(['value' => 'recreated']);
        $this->assertSame('recreated', $recreated->getValue());
    }

    public function testSerializationRoundTrip(): void
    {
        // Test complete serialization/deserialization cycle
        $implementation = new class implements SerializableInterface {
            public function __construct(
                private readonly string $name = 'default',
                private readonly array $config = [],
            ) {
            }

            public function toArray(): array
            {
                return [
                    'name' => $this->name,
                    'config' => $this->config,
                ];
            }

            public static function fromArray(array $data): static
            {
                return new static(
                    $data['name'] ?? 'default',
                    $data['config'] ?? [],
                );
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getConfig(): array
            {
                return $this->config;
            }
        };

        $original = new $implementation('test_name', ['key' => 'value', 'nested' => ['data' => true]]);

        // Serialize to array
        $serialized = $original->toArray();

        // Deserialize from array
        $deserialized = $implementation::fromArray($serialized);

        // Verify data integrity
        $this->assertSame($original->getName(), $deserialized->getName());
        $this->assertSame($original->getConfig(), $deserialized->getConfig());
        $this->assertSame($original->toArray(), $deserialized->toArray());
    }
}
