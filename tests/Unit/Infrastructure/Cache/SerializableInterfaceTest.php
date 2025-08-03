<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Cache;

use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\SerializableInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test for SerializableInterface contract
 * 
 * This test verifies that the interface exists and has the expected method signatures.
 * Actual implementation testing is done in the concrete class tests.
 */
final class SerializableInterfaceTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(SerializableInterface::class));
    }

    public function testInterfaceHasToArrayMethod(): void
    {
        $reflection = new \ReflectionClass(SerializableInterface::class);
        
        $this->assertTrue($reflection->hasMethod('toArray'));
        
        $method = $reflection->getMethod('toArray');
        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());
        $this->assertSame('array', $method->getReturnType()?->getName());
    }

    public function testInterfaceHasFromArrayMethod(): void
    {
        $reflection = new \ReflectionClass(SerializableInterface::class);
        
        $this->assertTrue($reflection->hasMethod('fromArray'));
        
        $method = $reflection->getMethod('fromArray');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
        
        // Check parameter
        $parameters = $method->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertSame('data', $parameters[0]->getName());
        $this->assertSame('array', $parameters[0]->getType()?->getName());
        
        // Check return type is static
        $returnType = $method->getReturnType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType);
        $this->assertSame('static', $returnType->getName());
    }

    public function testInterfaceMethodDocumentation(): void
    {
        $reflection = new \ReflectionClass(SerializableInterface::class);
        
        // Test toArray method documentation
        $toArrayMethod = $reflection->getMethod('toArray');
        $docComment = $toArrayMethod->getDocComment();
        $this->assertIsString($docComment);
        $this->assertStringContainsString('Convert object to array for serialization', $docComment);
        $this->assertStringContainsString('@return array<string, mixed>', $docComment);
        
        // Test fromArray method documentation
        $fromArrayMethod = $reflection->getMethod('fromArray');
        $docComment = $fromArrayMethod->getDocComment();
        $this->assertIsString($docComment);
        $this->assertStringContainsString('Create object from array data', $docComment);
        $this->assertStringContainsString('@param array<string, mixed>', $docComment);
        $this->assertStringContainsString('@return static', $docComment);
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

        $this->assertInstanceOf(SerializableInterface::class, $implementation);
        
        // Test toArray method
        $array = $implementation->toArray();
        $this->assertIsArray($array);
        $this->assertArrayHasKey('value', $array);
        $this->assertSame('test', $array['value']);
        
        // Test fromArray method
        $recreated = $implementation::fromArray(['value' => 'recreated']);
        $this->assertInstanceOf(SerializableInterface::class, $recreated);
        $this->assertSame('recreated', $recreated->getValue());
    }

    public function testSerializationRoundTrip(): void
    {
        // Test complete serialization/deserialization cycle
        $implementation = new class implements SerializableInterface {
            public function __construct(
                private readonly string $name = 'default',
                private readonly array $config = []
            ) {}

            public function toArray(): array
            {
                return [
                    'name' => $this->name,
                    'config' => $this->config
                ];
            }

            public static function fromArray(array $data): static
            {
                return new static(
                    $data['name'] ?? 'default',
                    $data['config'] ?? []
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