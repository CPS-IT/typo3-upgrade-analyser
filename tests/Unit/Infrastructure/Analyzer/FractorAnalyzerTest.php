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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorAnalysisSummary;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorConfigGenerator;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutionException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutor;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorResultParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\FractorAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(FractorAnalyzer::class)]
class FractorAnalyzerTest extends TestCase
{
    private FractorAnalyzer $analyzer;
    private MockObject&CacheService $cacheService;
    private MockObject&LoggerInterface $logger;
    private MockObject&FractorExecutor $fractorExecutor;
    private MockObject&FractorConfigGenerator $configGenerator;
    private MockObject&FractorResultParser $resultParser;

    protected function setUp(): void
    {
        $this->cacheService = $this->createMock(CacheService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->fractorExecutor = $this->createMock(FractorExecutor::class);
        $this->configGenerator = $this->createMock(FractorConfigGenerator::class);
        $this->resultParser = $this->createMock(FractorResultParser::class);

        $this->analyzer = new FractorAnalyzer(
            $this->cacheService,
            $this->logger,
            $this->fractorExecutor,
            $this->configGenerator,
            $this->resultParser,
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
    public function hasRequiredToolsReturnsFalseWhenFractorNotAvailable(): void
    {
        $this->fractorExecutor
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(false);

        self::assertFalse($this->analyzer->hasRequiredTools());
    }

    #[Test]
    public function hasRequiredToolsReturnsTrueWhenFractorIsAvailable(): void
    {
        $this->fractorExecutor
            ->expects(self::once())
            ->method('isAvailable')
            ->willReturn(true);

        self::assertTrue($this->analyzer->hasRequiredTools());
    }

    #[Test]
    public function analyzeReturnsResultWithErrorWhenExtensionPathMissing(): void
    {
        $extension = $this->createExtension('test_ext');
        $context = $this->createAnalysisContext();

        // Configure cache service to not return cached results
        $this->cacheService
            ->expects(self::once())
            ->method('get')
            ->willReturn(null);

        // The cache service should also be called to set the result
        $this->cacheService
            ->expects(self::once())
            ->method('set');

        $result = $this->analyzer->analyze($extension, $context);

        self::assertInstanceOf(AnalysisResult::class, $result);
        self::assertEquals('fractor', $result->getAnalyzerName());
        self::assertTrue($result->getMetric('analysis_error'));
        self::assertEquals(5.0, $result->getRiskScore());
        self::assertContains('Analysis encountered errors - results may be incomplete', $result->getRecommendations());
    }

    #[Test]
    public function analyzeStoresAllNewMetrics(): void
    {
        $extension = $this->createExtension('test_ext');
        $context = $this->createAnalysisContextWithValidPath();
        $configPath = '/tmp/fractor.php';
        $extensionPath = '/path/to/extension';

        // Mock successful analysis
        $this->setupSuccessfulAnalysisMocks($configPath, $extensionPath);

        // Create comprehensive summary with all new fields
        $summary = new FractorAnalysisSummary(
            filesScanned: 7,
            rulesApplied: 2,
            findings: ['finding1', 'finding2'],
            successful: true,
            changeBlocks: 10,
            changedLines: 25,
            filePaths: ['file1.xml', 'file2.html'],
            appliedRules: ['Rule1Fractor', 'Rule2Fractor'],
            errorMessage: null,
        );

        $this->resultParser
            ->expects(self::once())
            ->method('parse')
            ->willReturn($summary);

        $result = $this->analyzer->analyze($extension, $context);

        // Verify all metrics are stored
        self::assertEquals(7, $result->getMetric('files_scanned'));
        self::assertEquals(7, $result->getMetric('files_changed'));
        self::assertEquals(2, $result->getMetric('rules_applied'));
        self::assertEquals(2, $result->getMetric('total_issues'));
        self::assertTrue($result->getMetric('has_findings'));
        self::assertTrue($result->getMetric('analysis_successful'));
        self::assertEquals(10, $result->getMetric('change_blocks'));
        self::assertEquals(25, $result->getMetric('changed_lines'));
        self::assertEquals(['file1.xml', 'file2.html'], $result->getMetric('file_paths'));
        self::assertEquals(['Rule1Fractor', 'Rule2Fractor'], $result->getMetric('applied_rules'));
        self::assertNull($result->getMetric('error_message'));
    }

    #[Test]
    public function analyzeStoresErrorMessageWhenAnalysisFails(): void
    {
        $extension = $this->createExtension('test_ext');
        $context = $this->createAnalysisContextWithValidPath();
        $configPath = '/tmp/fractor.php';
        $extensionPath = '/path/to/extension';

        $this->setupSuccessfulAnalysisMocks($configPath, $extensionPath);

        // Create summary with error
        $errorMessage = 'Configuration file not found';
        $summary = new FractorAnalysisSummary(
            filesScanned: 0,
            rulesApplied: 0,
            findings: [],
            successful: false,
            errorMessage: $errorMessage,
        );

        $this->resultParser
            ->expects(self::once())
            ->method('parse')
            ->willReturn($summary);

        $result = $this->analyzer->analyze($extension, $context);

        self::assertEquals($errorMessage, $result->getMetric('error_message'));
        self::assertFalse($result->getMetric('analysis_successful'));
        self::assertEquals(9.0, $result->getRiskScore()); // High risk for failed analysis
    }

    #[Test]
    public function analyzeCalculatesRiskScoreBasedOnRulesAndFiles(): void
    {
        $extension = $this->createExtension('test_ext');
        $context = $this->createAnalysisContextWithValidPath();

        $this->setupSuccessfulAnalysisMocks('/tmp/fractor.php', '/path/to/extension');

        // Test different scenarios
        $testCases = [
            // [rulesApplied, filesScanned, expectedMinRisk, expectedMaxRisk]
            [0, 0, 1.0, 1.0],      // No changes needed
            [5, 3, 3.0, 4.0],      // Some changes, few files
            [25, 15, 5.0, 6.0],    // Moderate changes, some files
            [60, 25, 7.0, 8.0],    // Many changes, many files
        ];

        foreach ($testCases as [$rulesApplied, $filesScanned, $minRisk, $maxRisk]) {
            $summary = new FractorAnalysisSummary(
                filesScanned: $filesScanned,
                rulesApplied: $rulesApplied,
                findings: [],
                successful: true,
            );

            $this->resultParser
                ->expects(self::any())
                ->method('parse')
                ->willReturn($summary);

            $result = $this->analyzer->analyze($extension, $context);
            $riskScore = $result->getRiskScore();

            self::assertGreaterThanOrEqual(
                $minRisk,
                $riskScore,
                "Risk score {$riskScore} should be >= {$minRisk} for {$rulesApplied} rules, {$filesScanned} files",
            );
            self::assertLessThanOrEqual(
                $maxRisk,
                $riskScore,
                "Risk score {$riskScore} should be <= {$maxRisk} for {$rulesApplied} rules, {$filesScanned} files",
            );
        }
    }

    #[Test]
    public function analyzeGeneratesAppropriateRecommendations(): void
    {
        $extension = $this->createExtension('test_ext');
        $context = $this->createAnalysisContextWithValidPath();

        $this->setupSuccessfulAnalysisMocks('/tmp/fractor.php', '/path/to/extension');

        // Test no changes scenario
        $summary = new FractorAnalysisSummary(0, 0, [], true);
        $this->resultParser->expects(self::any())->method('parse')->willReturn($summary);
        $result = $this->analyzer->analyze($extension, $context);
        self::assertContains(
            'Code appears to follow modern patterns - minimal refactoring needed',
            $result->getRecommendations(),
        );

        // Test many changes scenario
        $summary = new FractorAnalysisSummary(25, 60, [], true);
        $this->resultParser->expects(self::any())->method('parse')->willReturn($summary);
        $result = $this->analyzer->analyze($extension, $context);
        self::assertContains(
            'Many modernization opportunities found (60 rules) - consider systematic refactoring',
            $result->getRecommendations(),
        );
    }

    #[Test]
    public function analyzeHandlesFractorExecutionException(): void
    {
        $extension = $this->createExtension('test_ext');
        $context = $this->createAnalysisContextWithValidPath();

        $this->cacheService->expects(self::once())->method('get')->willReturn(null);
        $this->configGenerator->expects(self::once())->method('generateConfig')->willReturn('/tmp/config.php');

        // Mock executor to throw exception
        $this->fractorExecutor
            ->expects(self::once())
            ->method('execute')
            ->willThrowException(new FractorExecutionException('Fractor failed'));

        $result = $this->analyzer->analyze($extension, $context);

        self::assertTrue($result->getMetric('execution_failed'));
        self::assertEquals('Fractor failed', $result->getMetric('error_message'));
        self::assertEquals(8.0, $result->getRiskScore());
        self::assertContains(
            'Fractor analysis failed - manual code review recommended',
            $result->getRecommendations(),
        );
    }

    #[Test]
    public function analyzeLimitsFindingsToPreventExcessiveData(): void
    {
        $extension = $this->createExtension('test_ext');
        $context = $this->createAnalysisContextWithValidPath();

        $this->setupSuccessfulAnalysisMocks('/tmp/fractor.php', '/path/to/extension');

        // Create summary with many findings
        $manyFindings = array_fill(0, 30, 'finding');
        $summary = new FractorAnalysisSummary(
            filesScanned: 30,
            rulesApplied: 30,
            findings: $manyFindings,
            successful: true,
        );

        $this->resultParser->expects(self::once())->method('parse')->willReturn($summary);
        $result = $this->analyzer->analyze($extension, $context);

        // Findings should be limited to 20
        self::assertCount(20, $result->getMetric('findings'));
    }

    #[Test]
    public function analyzeHandlesFilePaths(): void
    {
        $extension = $this->createExtension('test_ext');
        $context = $this->createAnalysisContextWithValidPath();

        $this->setupSuccessfulAnalysisMocks('/tmp/fractor.php', '/path/to/extension');

        $filePaths = [
            '../path/to/file1.xml:10',
            '../path/to/file2.html:25',
            '../path/to/file3.php:5',
        ];

        $summary = new FractorAnalysisSummary(
            filesScanned: 3,
            rulesApplied: 2,
            findings: [],
            successful: true,
            filePaths: $filePaths,
        );

        $this->resultParser->expects(self::once())->method('parse')->willReturn($summary);
        $result = $this->analyzer->analyze($extension, $context);

        self::assertEquals($filePaths, $result->getMetric('file_paths'));
    }

    #[Test]
    public function analyzeHandlesAppliedRules(): void
    {
        $extension = $this->createExtension('test_ext');
        $context = $this->createAnalysisContextWithValidPath();

        $this->setupSuccessfulAnalysisMocks('/tmp/fractor.php', '/path/to/extension');

        $appliedRules = [
            'RemoveNoCacheHashAndUseCacheHashAttributeFluidFractor',
            'RemoveTCEformsFractor',
        ];

        $summary = new FractorAnalysisSummary(
            filesScanned: 5,
            rulesApplied: 2,
            findings: [],
            successful: true,
            appliedRules: $appliedRules,
        );

        $this->resultParser->expects(self::once())->method('parse')->willReturn($summary);
        $result = $this->analyzer->analyze($extension, $context);

        self::assertEquals($appliedRules, $result->getMetric('applied_rules'));
    }

    #[Test]
    public function analyzeHandlesChangeBlocksAndLines(): void
    {
        $extension = $this->createExtension('test_ext');
        $context = $this->createAnalysisContextWithValidPath();

        $this->setupSuccessfulAnalysisMocks('/tmp/fractor.php', '/path/to/extension');

        $summary = new FractorAnalysisSummary(
            filesScanned: 3,
            rulesApplied: 1,
            findings: [],
            successful: true,
            changeBlocks: 5,
            changedLines: 15,
        );

        $this->resultParser->expects(self::once())->method('parse')->willReturn($summary);
        $result = $this->analyzer->analyze($extension, $context);

        self::assertEquals(5, $result->getMetric('change_blocks'));
        self::assertEquals(15, $result->getMetric('changed_lines'));
    }

    private function createExtension(string $key, string $type = 'local'): Extension
    {
        return new Extension(
            $key,
            'Test Extension',
            Version::fromString('1.0.0'),
            $type,
        );
    }

    private function createAnalysisContext(): AnalysisContext
    {
        $currentVersion = Version::fromString('12.4.0');
        $targetVersion = Version::fromString('13.0.0');

        return new AnalysisContext($currentVersion, $targetVersion, []);
    }

    private function createAnalysisContextWithValidPath(): AnalysisContext
    {
        $currentVersion = Version::fromString('12.4.0');
        $targetVersion = Version::fromString('13.0.0');

        return new AnalysisContext($currentVersion, $targetVersion, [
            'installation_path' => __DIR__ . '/../../../Fixtures/test_extension',
        ]);
    }

    private function setupSuccessfulAnalysisMocks(string $configPath, string $extensionPath): void
    {
        // Setup cache mocks
        $this->cacheService->expects(self::once())->method('get')->willReturn(null);
        $this->cacheService->expects(self::once())->method('set');

        // Setup other mocks
        $this->configGenerator->expects(self::once())->method('generateConfig')->willReturn($configPath);

        $executionResult = new FractorExecutionResult(0, 'success output', '', true);
        $this->fractorExecutor->expects(self::once())->method('execute')->willReturn($executionResult);
    }
}
