<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Http;

use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientServiceInterface
 */
class HttpClientServiceInterfaceTest extends TestCase
{
    public function testInterfaceDefinesRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(HttpClientServiceInterface::class);
        
        self::assertTrue($reflection->isInterface());
        
        $expectedMethods = [
            'request',
            'get',
            'post',
            'makeAuthenticatedRequest',
            'makeRateLimitedRequest'
        ];
        
        foreach ($expectedMethods as $methodName) {
            self::assertTrue(
                $reflection->hasMethod($methodName),
                sprintf('Method %s should be defined in HttpClientServiceInterface', $methodName)
            );
        }
    }

    public function testRequestMethodSignature(): void
    {
        $reflection = new \ReflectionClass(HttpClientServiceInterface::class);
        $method = $reflection->getMethod('request');
        
        self::assertTrue($method->isPublic());
        self::assertSame(ResponseInterface::class, $method->getReturnType()?->getName());
        self::assertCount(3, $method->getParameters());
        
        $parameters = $method->getParameters();
        
        $methodParam = $parameters[0];
        self::assertSame('method', $methodParam->getName());
        self::assertSame('string', $methodParam->getType()?->getName());
        
        $urlParam = $parameters[1];
        self::assertSame('url', $urlParam->getName());
        self::assertSame('string', $urlParam->getType()?->getName());
        
        $optionsParam = $parameters[2];
        self::assertSame('options', $optionsParam->getName());
        self::assertSame('array', $optionsParam->getType()?->getName());
        self::assertTrue($optionsParam->isDefaultValueAvailable());
        self::assertSame([], $optionsParam->getDefaultValue());
    }

    public function testGetMethodSignature(): void
    {
        $reflection = new \ReflectionClass(HttpClientServiceInterface::class);
        $method = $reflection->getMethod('get');
        
        self::assertTrue($method->isPublic());
        self::assertSame(ResponseInterface::class, $method->getReturnType()?->getName());
        self::assertCount(2, $method->getParameters());
        
        $parameters = $method->getParameters();
        
        $urlParam = $parameters[0];
        self::assertSame('url', $urlParam->getName());
        self::assertSame('string', $urlParam->getType()?->getName());
        
        $optionsParam = $parameters[1];
        self::assertSame('options', $optionsParam->getName());
        self::assertSame('array', $optionsParam->getType()?->getName());
        self::assertTrue($optionsParam->isDefaultValueAvailable());
    }

    public function testPostMethodSignature(): void
    {
        $reflection = new \ReflectionClass(HttpClientServiceInterface::class);
        $method = $reflection->getMethod('post');
        
        self::assertTrue($method->isPublic());
        self::assertSame(ResponseInterface::class, $method->getReturnType()?->getName());
        self::assertCount(2, $method->getParameters());
    }

    public function testMakeAuthenticatedRequestMethodSignature(): void
    {
        $reflection = new \ReflectionClass(HttpClientServiceInterface::class);
        $method = $reflection->getMethod('makeAuthenticatedRequest');
        
        self::assertTrue($method->isPublic());
        self::assertSame(ResponseInterface::class, $method->getReturnType()?->getName());
        self::assertCount(4, $method->getParameters());
        
        $parameters = $method->getParameters();
        
        $methodParam = $parameters[0];
        self::assertSame('method', $methodParam->getName());
        self::assertSame('string', $methodParam->getType()?->getName());
        
        $urlParam = $parameters[1];
        self::assertSame('url', $urlParam->getName());
        self::assertSame('string', $urlParam->getType()?->getName());
        
        $tokenParam = $parameters[2];
        self::assertSame('token', $tokenParam->getName());
        self::assertTrue($tokenParam->allowsNull());
        self::assertTrue($tokenParam->isDefaultValueAvailable());
        self::assertNull($tokenParam->getDefaultValue());
        
        $optionsParam = $parameters[3];
        self::assertSame('options', $optionsParam->getName());
        self::assertSame('array', $optionsParam->getType()?->getName());
        self::assertTrue($optionsParam->isDefaultValueAvailable());
    }

    public function testMakeRateLimitedRequestMethodSignature(): void
    {
        $reflection = new \ReflectionClass(HttpClientServiceInterface::class);
        $method = $reflection->getMethod('makeRateLimitedRequest');
        
        self::assertTrue($method->isPublic());
        self::assertSame(ResponseInterface::class, $method->getReturnType()?->getName());
        self::assertCount(5, $method->getParameters());
        
        $parameters = $method->getParameters();
        
        $methodParam = $parameters[0];
        self::assertSame('method', $methodParam->getName());
        self::assertSame('string', $methodParam->getType()?->getName());
        
        $urlParam = $parameters[1];
        self::assertSame('url', $urlParam->getName());
        self::assertSame('string', $urlParam->getType()?->getName());
        
        $optionsParam = $parameters[2];
        self::assertSame('options', $optionsParam->getName());
        self::assertSame('array', $optionsParam->getType()?->getName());
        self::assertTrue($optionsParam->isDefaultValueAvailable());
        
        $maxRetriesParam = $parameters[3];
        self::assertSame('maxRetries', $maxRetriesParam->getName());
        self::assertSame('int', $maxRetriesParam->getType()?->getName());
        self::assertTrue($maxRetriesParam->isDefaultValueAvailable());
        self::assertSame(3, $maxRetriesParam->getDefaultValue());
        
        $retryDelayParam = $parameters[4];
        self::assertSame('retryDelay', $retryDelayParam->getName());
        self::assertSame('int', $retryDelayParam->getType()?->getName());
        self::assertTrue($retryDelayParam->isDefaultValueAvailable());
        self::assertSame(1, $retryDelayParam->getDefaultValue());
    }

    public function testInterfaceDocumentation(): void
    {
        $reflection = new \ReflectionClass(HttpClientServiceInterface::class);
        $docComment = $reflection->getDocComment();
        
        self::assertIsString($docComment);
        self::assertStringContainsString('Interface for unified HTTP client service', $docComment);
    }

    public function testMethodsAreDocumented(): void
    {
        $reflection = new \ReflectionClass(HttpClientServiceInterface::class);
        
        $methods = ['request', 'get', 'post', 'makeAuthenticatedRequest', 'makeRateLimitedRequest'];
        
        foreach ($methods as $methodName) {
            $method = $reflection->getMethod($methodName);
            $docComment = $method->getDocComment();
            
            self::assertIsString($docComment, sprintf('Method %s should have documentation', $methodName));
            self::assertNotEmpty(trim($docComment), sprintf('Method %s documentation should not be empty', $methodName));
        }
    }

    public function testCanCreateMockImplementation(): void
    {
        $mock = $this->createMock(HttpClientServiceInterface::class);
        
        self::assertInstanceOf(HttpClientServiceInterface::class, $mock);
    }

    public function testInterfaceNamespaceIsCorrect(): void
    {
        $reflection = new \ReflectionClass(HttpClientServiceInterface::class);
        
        self::assertSame(
            'CPSIT\UpgradeAnalyzer\Infrastructure\Http',
            $reflection->getNamespaceName()
        );
    }
}