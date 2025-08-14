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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorAnalysisSummary;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorConfigGenerator;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutor;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorResultParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\FractorAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FractorAnalyzer::class)]
class SimplifiedFractorAnalyzerTest extends TestCase
{
    private FractorAnalyzer $analyzer;

    protected function setUp(): void
    {
        $cacheService = $this->createMock(CacheService::class);
        $cacheService->method('get')->willReturn(null);
        $cacheService->method('set')->willReturn(true);

        $this->analyzer = new FractorAnalyzer(
            $cacheService,
            new NullLogger(),
            $this->createMock(FractorExecutor::class),
            $this->createMock(FractorConfigGenerator::class),
            $this->createMock(FractorResultParser::class),
        );
    }

    #[Test]
    public function getNameReturnsCorrectName(): void
    {
        self::assertEquals('fractor', $this->analyzer->getName());
    }

    #[Test]
    public function getDescriptionReturnsCorrectDescription(): void
    {
        self::assertEquals(
            'Uses Fractor to modernize code patterns and detect improvement opportunities',
            $this->analyzer->getDescription(),
        );
    }

    #[Test]
    public function supportsReturnsTrue(): void
    {
        $extension = $this->createMock(Extension::class);
        self::assertTrue($this->analyzer->supports($extension));
    }

    #[Test]
    public function getRequiredToolsReturnsCorrectTools(): void
    {
        $tools = $this->analyzer->getRequiredTools();
        self::assertEquals(['php', 'fractor'], $tools);
    }

    #[Test]
    public function hasRequiredToolsChecksFractorAvailability(): void
    {
        // Create fresh analyzer with controlled executor
        $executor = $this->createMock(FractorExecutor::class);
        $executor->method('isAvailable')->willReturn(false);

        $analyzer = new FractorAnalyzer(
            $this->createMock(CacheService::class),
            new NullLogger(),
            $executor,
            $this->createMock(FractorConfigGenerator::class),
            $this->createMock(FractorResultParser::class),
        );

        self::assertFalse($analyzer->hasRequiredTools());

        // Test when available
        $executor = $this->createMock(FractorExecutor::class);
        $executor->method('isAvailable')->willReturn(true);

        $analyzer = new FractorAnalyzer(
            $this->createMock(CacheService::class),
            new NullLogger(),
            $executor,
            $this->createMock(FractorConfigGenerator::class),
            $this->createMock(FractorResultParser::class),
        );

        self::assertTrue($analyzer->hasRequiredTools());
    }

    #[Test]
    public function analyzeHandlesBasicFlow(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', Version::fromString('1.0.0'), 'local');
        $context = new AnalysisContext(
            Version::fromString('12.4.0'),
            Version::fromString('13.0.0'),
            ['installation_path' => __DIR__ . '/../../Fixtures/test_extension'],
        );

        // Create a complete analyzer with mocked dependencies that work together
        $cacheService = $this->createMock(CacheService::class);
        $cacheService->method('get')->willReturn(null);

        $configGenerator = $this->createMock(FractorConfigGenerator::class);
        $configGenerator->method('generateConfig')->willReturn('/tmp/test-config.php');

        $executor = $this->createMock(FractorExecutor::class);
        $executionResult = new FractorExecutionResult(0, 'test output', '', true);
        $executor->method('execute')->willReturn($executionResult);

        $parser = $this->createMock(FractorResultParser::class);
        $summary = new FractorAnalysisSummary(5, 2, [], true, 3, 10, ['file1.xml'], ['TestRule']);
        $parser->method('parse')->willReturn($summary);

        $analyzer = new FractorAnalyzer($cacheService, new NullLogger(), $executor, $configGenerator, $parser);

        $result = $analyzer->analyze($extension, $context);

        // Basic verification
        self::assertEquals('fractor', $result->getAnalyzerName());
        self::assertEquals($extension, $result->getExtension());
        self::assertGreaterThan(0, $result->getRiskScore());
        self::assertNotEmpty($result->getRecommendations());
    }
}
