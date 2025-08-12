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

use CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandlerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test interface definition and contract validation.
 */
class RepositoryUrlHandlerInterfaceTest extends TestCase
{
    private function getTypeName(?\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        self::fail('Expected ReflectionNamedType, got ' . ($type ? \get_class($type) : 'null'));
    }

    public function testInterfaceDefinesRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);

        self::assertTrue($reflection->isInterface());

        $expectedMethods = [
            'normalizeUrl',
            'isGitRepository',
            'extractRepositoryPath',
            'getProviderType',
            'isValidRepositoryUrl',
            'convertToApiUrl',
        ];

        foreach ($expectedMethods as $methodName) {
            self::assertTrue(
                $reflection->hasMethod($methodName),
                \sprintf('Method %s should be defined in RepositoryUrlHandlerInterface', $methodName),
            );
        }
    }

    public function testNormalizeUrlMethodSignature(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);
        $method = $reflection->getMethod('normalizeUrl');

        self::assertTrue($method->isPublic());
        self::assertSame('string', $this->getTypeName($method->getReturnType()));
        self::assertCount(1, $method->getParameters());

        $parameter = $method->getParameters()[0];
        self::assertSame('url', $parameter->getName());
        self::assertSame('string', $this->getTypeName($parameter->getType()));
    }

    public function testIsGitRepositoryMethodSignature(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);
        $method = $reflection->getMethod('isGitRepository');

        self::assertTrue($method->isPublic());
        self::assertSame('bool', $this->getTypeName($method->getReturnType()));
        self::assertCount(1, $method->getParameters());

        $parameter = $method->getParameters()[0];
        self::assertSame('url', $parameter->getName());
        self::assertSame('string', $this->getTypeName($parameter->getType()));
    }

    public function testExtractRepositoryPathMethodSignature(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);
        $method = $reflection->getMethod('extractRepositoryPath');

        self::assertTrue($method->isPublic());
        self::assertSame('array', $this->getTypeName($method->getReturnType()));
        self::assertCount(1, $method->getParameters());

        $parameter = $method->getParameters()[0];
        self::assertSame('url', $parameter->getName());
        self::assertSame('string', $this->getTypeName($parameter->getType()));
    }

    public function testGetProviderTypeMethodSignature(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);
        $method = $reflection->getMethod('getProviderType');

        self::assertTrue($method->isPublic());
        self::assertSame('string', $this->getTypeName($method->getReturnType()));
        self::assertCount(1, $method->getParameters());

        $parameter = $method->getParameters()[0];
        self::assertSame('url', $parameter->getName());
        self::assertSame('string', $this->getTypeName($parameter->getType()));
    }

    public function testIsValidRepositoryUrlMethodSignature(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);
        $method = $reflection->getMethod('isValidRepositoryUrl');

        self::assertTrue($method->isPublic());
        self::assertSame('bool', $this->getTypeName($method->getReturnType()));
        self::assertCount(1, $method->getParameters());

        $parameter = $method->getParameters()[0];
        self::assertSame('url', $parameter->getName());
        self::assertSame('string', $this->getTypeName($parameter->getType()));
    }

    public function testConvertToApiUrlMethodSignature(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);
        $method = $reflection->getMethod('convertToApiUrl');

        self::assertTrue($method->isPublic());
        self::assertSame('string', $this->getTypeName($method->getReturnType()));
        self::assertCount(2, $method->getParameters());

        $parameters = $method->getParameters();

        $urlParam = $parameters[0];
        self::assertSame('url', $urlParam->getName());
        self::assertSame('string', $this->getTypeName($urlParam->getType()));

        $apiTypeParam = $parameters[1];
        self::assertSame('apiType', $apiTypeParam->getName());
        self::assertSame('string', $this->getTypeName($apiTypeParam->getType()));
        self::assertTrue($apiTypeParam->isDefaultValueAvailable());
        self::assertSame('rest', $apiTypeParam->getDefaultValue());
    }

    public function testMethodsAreDocumented(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);

        $methods = [
            'normalizeUrl',
            'isGitRepository',
            'extractRepositoryPath',
            'getProviderType',
            'isValidRepositoryUrl',
            'convertToApiUrl',
        ];

        foreach ($methods as $methodName) {
            $method = $reflection->getMethod($methodName);
            $docComment = $method->getDocComment();

            self::assertIsString($docComment, \sprintf('Method %s should have documentation', $methodName));
            self::assertNotEmpty(trim($docComment), \sprintf('Method %s documentation should not be empty', $methodName));
        }
    }

    public function testInterfaceDocumentation(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);
        $docComment = $reflection->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('Interface for handling repository URLs', $docComment);
    }

    public function testCanCreateMockImplementation(): void
    {
        $mock = $this->createMock(RepositoryUrlHandlerInterface::class);

        self::assertInstanceOf(RepositoryUrlHandlerInterface::class, $mock);
    }

    public function testInterfaceNamespaceIsCorrect(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);

        self::assertSame(
            'CPSIT\UpgradeAnalyzer\Infrastructure\Repository',
            $reflection->getNamespaceName(),
        );
    }

    public function testAllMethodsArePublic(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);
        $methods = $reflection->getMethods();

        foreach ($methods as $method) {
            self::assertTrue(
                $method->isPublic(),
                \sprintf('Method %s should be public', $method->getName()),
            );
        }
    }

    public function testInterfaceHasNoConstants(): void
    {
        $reflection = new \ReflectionClass(RepositoryUrlHandlerInterface::class);
        $constants = $reflection->getConstants();

        self::assertEmpty($constants, 'Interface should not define constants');
    }
}
