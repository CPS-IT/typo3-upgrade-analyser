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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AbstractCachedAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(AbstractCachedAnalyzer::class)]
class AbstractCachedAnalyzerTest extends TestCase
{
    private MockObject&CacheService $cacheService;
    private MockObject&LoggerInterface $logger;
    private TestCachedAnalyzer $analyzer;
    private Extension $extension;
    private AnalysisContext $context;

    /**
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    protected function setUp(): void
    {
        $this->cacheService = $this->createMock(CacheService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->analyzer = new TestCachedAnalyzer($this->cacheService, $this->logger);

        $this->extension = new Extension(
            'test_extension',
            'Test Extension',
            new Version('1.0.0'),
            'local',
            'vendor/test-extension',
        );

        $this->context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );
    }

    public function testAnalyzeWithCacheDisabled(): void
    {
        // Set cache disabled in context
        $contextData = ['resultCache' => ['enabled' => false]];
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
            [],
            $contextData,
        );

        // Cache service should not be called
        $this->cacheService->expects($this->never())->method('get');
        $this->cacheService->expects($this->never())->method('set');

        // Set expectation for fresh analysis
        $expectedResult = new AnalysisResult('test_cached_analyzer', $this->extension);
        $expectedResult->addMetric('test_metric', 'test_value');
        $this->analyzer->setAnalysisResult($expectedResult);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Performing fresh analysis', [
                'analyzer' => 'test_cached_analyzer',
                'extension' => 'test_extension',
            ]);

        $result = $this->analyzer->analyze($this->extension, $context);

        $this->assertSame($expectedResult, $result);
        $this->assertTrue($this->analyzer->wasDoAnalyzeCalled());
    }

    public function testAnalyzeWithValidCachedResult(): void
    {
        $json = json_encode([
            'analyzer' => 'test_cached_analyzer',
            'extension_key' => 'test_extension',
            'extension_version' => '1.0.0',
            'target_version' => '12.4.0',
            'current_version' => '11.5.0',
            'extension_type' => 'local',
            'composer_name' => 'vendor/test-extension',
        ], JSON_THROW_ON_ERROR);

        if (!$json) {
            $this->fail('Failed to serialize test extension');
        }
        $cacheKey = 'analysis_test_cached_analyzer_' . hash('sha256', $json);

        $cachedData = [
            'analyzer_name' => 'test_cached_analyzer',
            'extension_key' => 'test_extension',
            'metrics' => ['cached_metric' => 'cached_value'],
            'risk_score' => 5.5,
            'recommendations' => ['Use cached result'],
            'successful' => true,
            'cached_at' => time(),
            'cache_ttl' => 3600,
        ];

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($cacheKey)
            ->willReturn($cachedData);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Using cached analysis result', [
                'analyzer' => 'test_cached_analyzer',
                'extension' => 'test_extension',
                'cache_key' => $cacheKey,
            ]);

        $result = $this->analyzer->analyze($this->extension, $this->context);

        $this->assertEquals('test_cached_analyzer', $result->getAnalyzerName());
        $this->assertEquals(['cached_metric' => 'cached_value'], $result->getMetrics());
        $this->assertEquals(5.5, $result->getRiskScore());
        $this->assertEquals(['Use cached result'], $result->getRecommendations());
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($this->analyzer->wasDoAnalyzeCalled());
    }

    public function testAnalyzeWithExpiredCachedResult(): void
    {
        $cachedData = [
            'analyzer_name' => 'test_cached_analyzer',
            'cached_at' => time() - 7200, // 2 hours ago
            'cache_ttl' => 3600, // 1 hour TTL
        ];

        $this->cacheService->expects($this->once())
            ->method('get')
            ->willReturn($cachedData);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Cached result expired', $this->callback(function ($context): bool {
                return 'test_cached_analyzer' === $context['analyzer']
                       && 'test_extension' === $context['extension']
                       && isset($context['age'])
                       && $context['age'] > 3600;
            }));

        // Should perform fresh analysis
        $expectedResult = new AnalysisResult('test_cached_analyzer', $this->extension);
        $this->analyzer->setAnalysisResult($expectedResult);

        $result = $this->analyzer->analyze($this->extension, $this->context);

        $this->assertSame($expectedResult, $result);
        $this->assertTrue($this->analyzer->wasDoAnalyzeCalled());
    }

    public function testAnalyzeWithSuccessfulResultCaching(): void
    {
        $this->cacheService->expects($this->once())
            ->method('get')
            ->willReturn(null); // No cached result

        $analysisResult = new AnalysisResult('test_cached_analyzer', $this->extension);
        $analysisResult->addMetric('test_metric', 'test_value');
        $analysisResult->setRiskScore(3.5);
        $analysisResult->addRecommendation('Test recommendation');
        $this->analyzer->setAnalysisResult($analysisResult);

        $this->cacheService->expects($this->once())
            ->method('set')
            ->with($this->isType('string'), $this->callback(function ($data): bool {
                return 'test_cached_analyzer' === $data['analyzer_name']
                       && 'test_extension' === $data['extension_key']
                       && 'test_value' === $data['metrics']['test_metric']
                       && 3.5 === $data['risk_score']
                       && $data['recommendations'] === ['Test recommendation']
                       && true === $data['successful']
                       && '' === $data['error']
                       && isset($data['cached_at'])
                       && 3600 === $data['cache_ttl'];
            }))
            ->willReturn(true);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Analysis result cached', $this->callback(function ($context): bool {
                return 'test_cached_analyzer' === $context['analyzer']
                       && 'test_extension' === $context['extension']
                       && isset($context['cache_key']);
            }));

        $result = $this->analyzer->analyze($this->extension, $this->context);

        $this->assertSame($analysisResult, $result);
    }

    public function testAnalyzeWithFailedResultNoCaching(): void
    {
        $this->cacheService->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $analysisResult = new AnalysisResult('test_cached_analyzer', $this->extension);
        $analysisResult->setError('Analysis failed for test');
        $this->analyzer->setAnalysisResult($analysisResult);

        // Failed results should not be cached
        $this->cacheService->expects($this->never())->method('set');

        $result = $this->analyzer->analyze($this->extension, $this->context);

        $this->assertSame($analysisResult, $result);
        $this->assertFalse($result->isSuccessful());
    }

    public function testAnalyzeWithExceptionHandling(): void
    {
        $this->cacheService->expects($this->once())
            ->method('get')
            ->willReturn(null);

        // Set analyzer to throw an exception
        $this->analyzer->setThrowException(new \RuntimeException('Test analysis exception'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Analysis failed', [
                'analyzer' => 'test_cached_analyzer',
                'extension' => 'test_extension',
                'error' => 'Test analysis exception',
            ]);

        $result = $this->analyzer->analyze($this->extension, $this->context);

        $this->assertEquals('test_cached_analyzer', $result->getAnalyzerName());
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Analysis failed: Test analysis exception', $result->getError());
    }

    public function testGenerateCacheKey(): void
    {
        $extension = new Extension(
            'test_ext',
            'Test',
            new Version('2.0.0'),
            'ter',
            null, // No composer name
        );

        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.0.0'),
        );

        $key = $this->analyzer->generateCacheKeyPublic($extension, $context);

        $this->assertStringStartsWith('analysis_test_cached_analyzer_', $key);
        $this->assertMatchesRegularExpression('/^analysis_test_cached_analyzer_[a-f0-9]{64}$/', $key);
    }

    public function testGenerateCacheKeyWithAnalyzerSpecificComponents(): void
    {
        $analyzer = new TestCachedAnalyzer($this->cacheService, $this->logger);
        $analyzer->setAnalyzerSpecificComponents(['custom_param' => 'custom_value']);

        $key1 = $analyzer->generateCacheKeyPublic($this->extension, $this->context);

        $analyzer->setAnalyzerSpecificComponents(['custom_param' => 'different_value']);
        $key2 = $analyzer->generateCacheKeyPublic($this->extension, $this->context);

        $this->assertNotEquals($key1, $key2);
    }

    public function testIsCacheEnabledDefaultTrue(): void
    {
        $context = new AnalysisContext(new Version('11.5.0'), new Version('12.4.0'));
        $this->assertTrue($this->analyzer->isCacheEnabledPublic($context));
    }

    public function testIsCacheEnabledWithExplicitConfig(): void
    {
        $contextEnabled = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
            [],
            ['resultCache' => ['enabled' => true]],
        );
        $this->assertTrue($this->analyzer->isCacheEnabledPublic($contextEnabled));

        $contextDisabled = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
            [],
            ['resultCache' => ['enabled' => false]],
        );
        $this->assertFalse($this->analyzer->isCacheEnabledPublic($contextDisabled));
    }

    public function testGetCacheTtl(): void
    {
        // Default TTL
        $context = new AnalysisContext(new Version('11.5.0'), new Version('12.4.0'));
        $this->assertEquals(3600, $this->analyzer->getCacheTtlPublic($context));

        // Custom TTL
        $customContext = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
            [],
            ['resultCache' => ['ttl' => 7200]],
        );
        $this->assertEquals(7200, $this->analyzer->getCacheTtlPublic($customContext));
    }

    public function testSerializeResult(): void
    {
        $result = new AnalysisResult('test_analyzer', $this->extension);
        $result->addMetric('key1', 'value1');
        $result->addMetric('key2', 42);
        $result->setRiskScore(7.5);
        $result->addRecommendation('Recommendation 1');
        $result->addRecommendation('Recommendation 2');

        $serialized = $this->analyzer->serializeResultPublic($result);

        $expected = [
            'analyzer_name' => 'test_analyzer',
            'extension_key' => 'test_extension',
            'metrics' => ['key1' => 'value1', 'key2' => 42],
            'risk_score' => 7.5,
            'recommendations' => ['Recommendation 1', 'Recommendation 2'],
            'successful' => true,
            'error' => '',
        ];

        $this->assertEquals($expected, $serialized);
    }

    public function testSerializeResultWithError(): void
    {
        $result = new AnalysisResult('test_analyzer', $this->extension);
        $result->setError('Something went wrong');

        $serialized = $this->analyzer->serializeResultPublic($result);

        $this->assertEquals('test_analyzer', $serialized['analyzer_name']);
        $this->assertEquals('test_extension', $serialized['extension_key']);
        $this->assertFalse($serialized['successful']);
        $this->assertEquals('Something went wrong', $serialized['error']);
    }

    public function testDeserializeResult(): void
    {
        $cachedData = [
            'analyzer_name' => 'test_analyzer',
            'metrics' => ['key1' => 'value1', 'key2' => 42],
            'risk_score' => 8.5,
            'recommendations' => ['Rec 1', 'Rec 2'],
            'successful' => true,
        ];

        $result = $this->analyzer->deserializeResultPublic($cachedData, $this->extension);

        $this->assertEquals('test_analyzer', $result->getAnalyzerName());
        $this->assertEquals(['key1' => 'value1', 'key2' => 42], $result->getMetrics());
        $this->assertEquals(8.5, $result->getRiskScore());
        $this->assertEquals(['Rec 1', 'Rec 2'], $result->getRecommendations());
        $this->assertTrue($result->isSuccessful());
        $this->assertEmpty($result->getError());
    }

    public function testDeserializeResultWithError(): void
    {
        $cachedData = [
            'analyzer_name' => 'test_analyzer',
            'successful' => false,
            'error' => 'Cached error message',
        ];

        $result = $this->analyzer->deserializeResultPublic($cachedData, $this->extension);

        $this->assertEquals('test_analyzer', $result->getAnalyzerName());
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Cached error message', $result->getError());
    }

    public function testGetDirectoryModificationTimeWithNonExistentDirectory(): void
    {
        $mtime = $this->analyzer->getDirectoryModificationTimePublic('/non/existent/directory');
        $this->assertEquals(0, $mtime);
    }

    public function testGetDirectoryModificationTimeWithEmptyDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_mtime_' . uniqid('', true);
        mkdir($tempDir);

        try {
            $mtime = $this->analyzer->getDirectoryModificationTimePublic($tempDir);
            $this->assertGreaterThan(0, $mtime);
        } finally {
            rmdir($tempDir);
        }
    }

    public function testGetDirectoryModificationTimeWithPhpFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_mtime_' . uniqid('', true);
        mkdir($tempDir);

        try {
            $phpFile = $tempDir . '/test.php';
            file_put_contents($phpFile, '<?php echo "test"; ?>');

            sleep(1); // Ensure different modification time

            $txtFile = $tempDir . '/test.txt';
            file_put_contents($txtFile, 'text content');

            $mtime = $this->analyzer->getDirectoryModificationTimePublic($tempDir);

            $this->assertGreaterThan(0, $mtime);
            $this->assertGreaterThanOrEqual(filemtime($phpFile), $mtime);
        } finally {
            if (file_exists($tempDir . '/test.php')) {
                unlink($tempDir . '/test.php');
            }
            if (file_exists($tempDir . '/test.txt')) {
                unlink($tempDir . '/test.txt');
            }
            rmdir($tempDir);
        }
    }

    public function testIsValidCachedResultWithValidCache(): void
    {
        $cachedData = [
            'cached_at' => time() - 1800, // 30 minutes ago
            'cache_ttl' => 3600, // 1 hour TTL
        ];

        $isValid = $this->analyzer->isValidCachedResultPublic($cachedData, $this->extension, $this->context);
        $this->assertTrue($isValid);
    }

    public function testIsValidCachedResultWithExpiredCache(): void
    {
        $cachedData = [
            'cached_at' => time() - 7200, // 2 hours ago
            'cache_ttl' => 3600, // 1 hour TTL
        ];

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Cached result expired', $this->callback(function ($context): bool {
                return 'test_cached_analyzer' === $context['analyzer']
                       && 'test_extension' === $context['extension']
                       && isset($context['age']) && $context['age'] > 3600;
            }));

        $isValid = $this->analyzer->isValidCachedResultPublic($cachedData, $this->extension, $this->context);
        $this->assertFalse($isValid);
    }
}

/**
 * Concrete implementation of AbstractCachedAnalyzer for testing.
 */
class TestCachedAnalyzer extends AbstractCachedAnalyzer
{
    private ?AnalysisResult $analysisResult = null;
    private ?\Throwable $exceptionToThrow = null;
    private bool $doAnalyzeCalled = false;
    private array $analyzerSpecificComponents = [];

    public function getName(): string
    {
        return 'test_cached_analyzer';
    }

    public function getDescription(): string
    {
        return 'Test cached analyzer for unit testing';
    }

    public function supports(Extension $extension): bool
    {
        return true;
    }

    public function hasRequiredTools(): bool
    {
        return true;
    }

    public function getRequiredTools(): array
    {
        return [];
    }

    public function setAnalysisResult(AnalysisResult $result): void
    {
        $this->analysisResult = $result;
    }

    public function setThrowException(\Throwable $exception): void
    {
        $this->exceptionToThrow = $exception;
    }

    public function wasDoAnalyzeCalled(): bool
    {
        return $this->doAnalyzeCalled;
    }

    public function setAnalyzerSpecificComponents(array $components): void
    {
        $this->analyzerSpecificComponents = $components;
    }

    public function reset(): void
    {
        $this->analysisResult = null;
        $this->exceptionToThrow = null;
        $this->doAnalyzeCalled = false;
        $this->analyzerSpecificComponents = [];
    }

    /**
     * @throws \Throwable
     */
    protected function doAnalyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $this->doAnalyzeCalled = true;

        if (null !== $this->exceptionToThrow) {
            throw $this->exceptionToThrow;
        }

        return $this->analysisResult ?? new AnalysisResult($this->getName(), $extension);
    }

    protected function getAnalyzerSpecificCacheKeyComponents(Extension $extension, AnalysisContext $context): array
    {
        return $this->analyzerSpecificComponents;
    }

    // Public wrappers for testing protected methods

    /**
     * @throws \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerException
     */
    public function generateCacheKeyPublic(Extension $extension, AnalysisContext $context): string
    {
        return $this->generateCacheKey($extension, $context);
    }

    public function isCacheEnabledPublic(AnalysisContext $context): bool
    {
        return $this->isCacheEnabled($context);
    }

    public function getCacheTtlPublic(AnalysisContext $context): int
    {
        return $this->getCacheTtl($context);
    }

    public function serializeResultPublic(AnalysisResult $result): array
    {
        return $this->serializeResult($result);
    }

    public function deserializeResultPublic(array $cachedData, Extension $extension): AnalysisResult
    {
        return $this->deserializeResult($cachedData, $extension);
    }

    public function getDirectoryModificationTimePublic(string $path): int
    {
        return $this->getDirectoryModificationTime($path);
    }

    public function isValidCachedResultPublic(array $cachedData, Extension $extension, AnalysisContext $context): bool
    {
        return $this->isValidCachedResult($cachedData, $extension, $context);
    }
}
