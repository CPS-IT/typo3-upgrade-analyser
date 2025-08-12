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

use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test interface definition and contract validation.
 */
class AnalyzerInterfaceTest extends TestCase
{
    public function testInterfaceDefinesRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(AnalyzerInterface::class);

        self::assertTrue($reflection->isInterface());

        $expectedMethods = [
            'getName',
            'getDescription',
            'supports',
            'analyze',
            'getRequiredTools',
            'hasRequiredTools',
        ];

        foreach ($expectedMethods as $methodName) {
            self::assertTrue(
                $reflection->hasMethod($methodName),
                \sprintf('Method %s should be defined in AnalyzerInterface', $methodName),
            );
        }
    }

    public function testGetNameMethodSignature(): void
    {
        $reflection = new \ReflectionClass(AnalyzerInterface::class);
        $method = $reflection->getMethod('getName');

        self::assertTrue($method->isPublic());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('string', $returnType->getName());
        self::assertCount(0, $method->getParameters());
    }

    public function testGetDescriptionMethodSignature(): void
    {
        $reflection = new \ReflectionClass(AnalyzerInterface::class);
        $method = $reflection->getMethod('getDescription');

        self::assertTrue($method->isPublic());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('string', $returnType->getName());
        self::assertCount(0, $method->getParameters());
    }

    public function testSupportsMethodSignature(): void
    {
        $reflection = new \ReflectionClass(AnalyzerInterface::class);
        $method = $reflection->getMethod('supports');

        self::assertTrue($method->isPublic());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('bool', $returnType->getName());
        self::assertCount(1, $method->getParameters());

        $parameter = $method->getParameters()[0];
        self::assertSame('extension', $parameter->getName());
        $parameterType = $parameter->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $parameterType);
        self::assertSame(Extension::class, $parameterType->getName());
    }

    public function testAnalyzeMethodSignature(): void
    {
        $reflection = new \ReflectionClass(AnalyzerInterface::class);
        $method = $reflection->getMethod('analyze');

        self::assertTrue($method->isPublic());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(AnalysisResult::class, $returnType->getName());
        self::assertCount(2, $method->getParameters());

        $extensionParam = $method->getParameters()[0];
        self::assertSame('extension', $extensionParam->getName());
        $extensionParamType = $extensionParam->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $extensionParamType);
        self::assertSame(Extension::class, $extensionParamType->getName());

        $contextParam = $method->getParameters()[1];
        self::assertSame('context', $contextParam->getName());
        $contextParamType = $contextParam->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $contextParamType);
        self::assertSame(AnalysisContext::class, $contextParamType->getName());
    }

    public function testGetRequiredToolsMethodSignature(): void
    {
        $reflection = new \ReflectionClass(AnalyzerInterface::class);
        $method = $reflection->getMethod('getRequiredTools');

        self::assertTrue($method->isPublic());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
        self::assertCount(0, $method->getParameters());
    }

    public function testHasRequiredToolsMethodSignature(): void
    {
        $reflection = new \ReflectionClass(AnalyzerInterface::class);
        $method = $reflection->getMethod('hasRequiredTools');

        self::assertTrue($method->isPublic());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('bool', $returnType->getName());
        self::assertCount(0, $method->getParameters());
    }

    public function testAnalyzeMethodDocumentedToThrowAnalyzerException(): void
    {
        $reflection = new \ReflectionClass(AnalyzerInterface::class);
        $method = $reflection->getMethod('analyze');
        $docComment = $method->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('@throws', $docComment);
        self::assertStringContainsString('AnalyzerException', $docComment);
    }

    public function testGetRequiredToolsMethodDocumentedReturnType(): void
    {
        $reflection = new \ReflectionClass(AnalyzerInterface::class);
        $method = $reflection->getMethod('getRequiredTools');
        $docComment = $method->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('@return', $docComment);
        self::assertStringContainsString('array<string>', $docComment);
    }

    public function testInterfaceDocumentation(): void
    {
        $reflection = new \ReflectionClass(AnalyzerInterface::class);
        $docComment = $reflection->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('Interface for all analyzers', $docComment);
    }

    public function testCanCreateMockImplementation(): void
    {
        $mock = $this->createMock(AnalyzerInterface::class);

        self::assertInstanceOf(AnalyzerInterface::class, $mock);
    }
}
