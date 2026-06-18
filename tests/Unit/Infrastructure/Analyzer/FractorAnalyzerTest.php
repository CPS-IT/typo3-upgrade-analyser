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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorChangeType;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorConfigGenerator;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutor;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorFinding;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorResultParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\FractorAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FractorAnalyzer::class)]
#[AllowMockObjectsWithoutExpectations]
class FractorAnalyzerTest extends TestCase
{
    private FractorAnalyzer $analyzer;
    private MockObject $cacheService;
    private NullLogger $logger;
    private MockObject $fractorExecutor;
    private MockObject $configGenerator;
    private MockObject $resultParser;
    private MockObject $ruleRegistry;
    private MockObject $pathResolutionService;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->logger = new NullLogger();
        $this->cacheService = $this->createMock(CacheService::class);
        $this->fractorExecutor = $this->createMock(FractorExecutor::class);
        $this->configGenerator = $this->createMock(FractorConfigGenerator::class);
        $this->resultParser = $this->createMock(FractorResultParser::class);
        $this->ruleRegistry = $this->createMock(FractorRuleRegistry::class);
        $this->pathResolutionService = $this->createMock(PathResolutionServiceInterface::class);

        $this->analyzer = new FractorAnalyzer(
            $this->cacheService,
            $this->logger,
            $this->fractorExecutor,
            $this->configGenerator,
            $this->resultParser,
            $this->ruleRegistry,
            $this->pathResolutionService,
        );
    }

    public function testGetName(): void
    {
        $this->assertEquals(FractorAnalyzer::NAME, $this->analyzer->getName());
    }

    public function testGetDescription(): void
    {
        $description = $this->analyzer->getDescription();

        $this->assertEquals(FractorAnalyzer::DESCRIPTION, $description);
    }

    public function testSupports(): void
    {
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'));

        // Should support all extensions
        $this->assertTrue($this->analyzer->supports($extension));
    }

    public function testGetRequiredTools(): void
    {
        $tools = $this->analyzer->getRequiredTools();

        $this->assertContains('php', $tools);
        $this->assertContains('fractor', $tools);
    }

    public function testHasRequiredToolsWhenAvailable(): void
    {
        $this->fractorExecutor
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->assertTrue($this->analyzer->hasRequiredTools());
    }

    public function testHasRequiredToolsWhenUnavailable(): void
    {
        $this->fractorExecutor
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $this->assertFalse($this->analyzer->hasRequiredTools());
    }

    public function testAnalyzeWithCacheHit(): void
    {
        $this->markTestSkipped('Cache testing requires complex mock setup of parent class behavior');
    }

    public function testAnalyzeWithoutCacheHit(): void
    {
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'));
        $context = new AnalysisContext(new Version('11.5.0'), new Version('12.4.0'));

        $findings = [
            new FractorFinding(
                'src/Test.php',
                10,
                'TestRule',
                'Test message',
                FractorRuleSeverity::WARNING,
                FractorChangeType::DEPRECATION,
            ),
        ];

        $executionResult = new FractorExecutionResult(
            successful: true,
            findings: $findings,
            errors: [],
            executionTime: 2.5,
            exitCode: 0,
            rawOutput: 'output',
            processedFileCount: 5,
        );

        $summary = new FractorAnalysisSummary(
            totalFindings: 1,
            criticalIssues: 0,
            warnings: 1,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 1,
            totalFiles: 5,
            ruleBreakdown: ['TestRule' => 1],
            fileBreakdown: ['src/Test.php' => 1],
            typeBreakdown: ['deprecation' => 1],
            complexityScore: 3.0,
            estimatedFixTime: 30,
        );

        // Set up mocks
        $this->cacheService
            ->expects($this->once())
            ->method('get')
            ->willReturn(null); // Cache miss

        $this->configGenerator
            ->expects($this->once())
            ->method('generateConfig')
            ->willReturn('/tmp/fractor_config.php');

        $this->fractorExecutor
            ->expects($this->once())
            ->method('execute')
            ->willReturn($executionResult);

        $this->resultParser
            ->expects($this->once())
            ->method('aggregateFindings')
            ->with($findings)
            ->willReturn($summary);

        $this->fractorExecutor
            ->method('getVersion')
            ->willReturn('0.5.0');

        $this->configGenerator
            ->expects($this->once())
            ->method('cleanup');

        $this->cacheService
            ->expects($this->once())
            ->method('set');

        $result = $this->analyzer->analyze($extension, $context);
        $this->assertGreaterThan(1.0, $result->getRiskScore());
    }

    public function testAnalyzeWithExecutorException(): void
    {
        $this->markTestSkipped('Exception handling testing requires complex mock setup of parent class behavior');
    }

    public function testGetAnalyzerSpecificCacheKeyComponents(): void
    {
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'));
        $context = new AnalysisContext(new Version('11.5.0'), new Version('12.4.0'));

        $this->ruleRegistry
            ->method('getSetsForVersionUpgrade')
            ->willReturn(['Rule1', 'Rule2']);

        $this->fractorExecutor
            ->method('getVersion')
            ->willReturn('0.5.0');

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('getAnalyzerSpecificCacheKeyComponents');
        $method->setAccessible(true);

        $components = $method->invoke($this->analyzer, $extension, $context);

        $this->assertArrayHasKey('current_version', $components);
        $this->assertArrayHasKey('target_version', $components);
        $this->assertArrayHasKey('fractor_version', $components);
        $this->assertArrayHasKey('set_count', $components);

        $this->assertEquals('11.5.0', $components['current_version']);
        $this->assertEquals('12.4.0', $components['target_version']);
        $this->assertEquals('0.5.0', $components['fractor_version']);
        $this->assertEquals(2, $components['set_count']);
    }

    public function testCalculateRiskScoreWithNoFindings(): void
    {
        $summary = new FractorAnalysisSummary(
            totalFindings: 0,
            criticalIssues: 0,
            warnings: 0,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 0,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 0.0,
            estimatedFixTime: 0,
        );

        $reflection = new \ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('calculateRiskScore');
        $method->setAccessible(true);

        $riskScore = $method->invoke($this->analyzer, $summary);

        $this->assertEquals(1.0, $riskScore);
    }

    public function testCalculateRiskScoreWithManyIssues(): void
    {
        $summary = new FractorAnalysisSummary(
            totalFindings: 20,
            criticalIssues: 5,
            warnings: 10,
            infoIssues: 5,
            suggestions: 0,
            affectedFiles: 8,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 8.0,
            estimatedFixTime: 1200,
        );

        $reflection = new \ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('calculateRiskScore');
        $method->setAccessible(true);

        $riskScore = $method->invoke($this->analyzer, $summary);

        $this->assertGreaterThan(5.0, $riskScore);
        $this->assertLessThanOrEqual(10.0, $riskScore);
    }

    public function testGenerateRecommendationsWithNoFindings(): void
    {
        $summary = new FractorAnalysisSummary(
            totalFindings: 0,
            criticalIssues: 0,
            warnings: 0,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 0,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 0.0,
            estimatedFixTime: 0,
        );

        $context = new AnalysisContext(new Version('11.5.0'), new Version('12.4.0'));

        $reflection = new \ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('generateRecommendations');

        $recommendations = $method->invoke($this->analyzer, $summary, $context);

        $this->assertIsArray($recommendations);
        $this->assertCount(1, $recommendations);
        $this->assertStringContainsString('ready for TYPO3', $recommendations[0]);
    }

    public function testGenerateRecommendationsWithManyIssues(): void
    {
        $summary = new FractorAnalysisSummary(
            totalFindings: 15,
            criticalIssues: 3,
            warnings: 8,
            infoIssues: 4,
            suggestions: 0,
            affectedFiles: 6,
            totalFiles: 10,
            ruleBreakdown: ['TopRule' => 8, 'OtherRule' => 4],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 7.5,
            estimatedFixTime: 600,
        );

        $context = new AnalysisContext(new Version('11.5.0'), new Version('12.4.0'));

        $reflection = new \ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('generateRecommendations');

        $recommendations = $method->invoke($this->analyzer, $summary, $context);

        $this->assertIsArray($recommendations);
        $this->assertGreaterThan(3, \count($recommendations));

        $hasBreakingRecommendation = false;
        foreach ($recommendations as $recommendation) {
            if (str_contains($recommendation, 'Critical:')) {
                $hasBreakingRecommendation = true;
                break;
            }
        }
        $this->assertTrue($hasBreakingRecommendation);
    }

    public function testGetFallbackExtensionPathUsesWebDir(): void
    {
        $extension = new Extension('my_ext', 'My Extension', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
            [],
            [
                'installation_path' => '/test/path',
                'custom_paths' => ['web-dir' => 'htdocs'],
            ],
        );

        // PathResolutionService fails so the fallback is used
        $metadata = new PathResolutionMetadata(
            PathTypeEnum::EXTENSION,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            'ExtensionPathResolutionStrategy',
            1,
        );
        $response = PathResolutionResponse::error(
            PathTypeEnum::EXTENSION,
            $metadata,
            ['Forced failure to exercise fallback'],
        );

        $this->pathResolutionService
            ->expects($this->once())
            ->method('resolvePath')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('getExtensionPath');

        $path = $method->invoke($this->analyzer, $extension, $context);

        // Exact path so a malformed join (e.g. doubled slashes) would fail
        $this->assertSame('/test/path/htdocs/typo3conf/ext/my_ext', $path);
    }

    public function testGetFallbackExtensionPathDefaultsToPublic(): void
    {
        $extension = new Extension('my_ext', 'My Extension', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
            [],
            [
                'installation_path' => '/test/path',
                'custom_paths' => [],
            ],
        );

        $metadata = new PathResolutionMetadata(
            PathTypeEnum::EXTENSION,
            InstallationTypeEnum::COMPOSER_STANDARD,
            'ExtensionPathResolutionStrategy',
            1,
        );
        $response = PathResolutionResponse::error(
            PathTypeEnum::EXTENSION,
            $metadata,
            ['Forced failure to exercise fallback'],
        );

        $this->pathResolutionService
            ->expects($this->once())
            ->method('resolvePath')
            ->willReturn($response);

        $reflection = new \ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('getExtensionPath');

        $path = $method->invoke($this->analyzer, $extension, $context);

        // Exact path so a malformed join (e.g. doubled slashes) would fail
        $this->assertSame('/test/path/public/typo3conf/ext/my_ext', $path);
    }

    public function testDoAnalyzeReturnsEmptyResultWhenPathNotFound(): void
    {
        $extension = new Extension('nonexistent_ext_xyz', 'Nonexistent Extension', new Version('1.0.0'));
        $tempDir = sys_get_temp_dir();
        $expectedExtensionPath = $tempDir . '/public/typo3conf/ext/nonexistent_ext_xyz';
        $this->assertDirectoryDoesNotExist($expectedExtensionPath, 'Test precondition: extension path must not exist in temp dir');

        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
            [],
            [
                'installation_path' => $tempDir,
                'custom_paths' => [],
            ],
        );

        // PathResolutionService fails so the fallback builds a non-existent path
        $metadata = new PathResolutionMetadata(
            PathTypeEnum::EXTENSION,
            InstallationTypeEnum::COMPOSER_STANDARD,
            'ExtensionPathResolutionStrategy',
            1,
        );
        $response = PathResolutionResponse::error(
            PathTypeEnum::EXTENSION,
            $metadata,
            ['Forced failure to exercise fallback'],
        );

        $this->pathResolutionService
            ->method('resolvePath')
            ->willReturn($response);

        // Cache miss so doAnalyze() is actually called
        $this->cacheService
            ->method('get')
            ->willReturn(null);

        $this->fractorExecutor
            ->method('getVersion')
            ->willReturn('0.5.0');

        $this->ruleRegistry
            ->method('getSetsForVersionUpgrade')
            ->willReturn([]);

        // generateConfig must NEVER be called because the path does not exist
        $this->configGenerator
            ->expects($this->never())
            ->method('generateConfig');

        // execute must NEVER be called because the path does not exist
        $this->fractorExecutor
            ->expects($this->never())
            ->method('execute');

        $result = $this->analyzer->analyze($extension, $context);

        // Empty result: no findings metrics, no recommendations
        $this->assertNull($result->getMetric('total_findings'));
        $this->assertEmpty($result->getRecommendations());
    }

    public function testDoAnalyzeProceedsWhenInstallationPathEmpty(): void
    {
        // When installation_path is empty the is_dir guard is deliberately
        // bypassed: getExtensionPath() returns a relative fallback path and
        // analysis proceeds even though that path does not exist on disk.
        $extension = new Extension('some_ext', 'Some Extension', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
            [],
            [
                'installation_path' => '',
                'custom_paths' => [],
            ],
        );

        $executionResult = new FractorExecutionResult(
            successful: true,
            findings: [],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: '',
            processedFileCount: 0,
        );

        $summary = new FractorAnalysisSummary(
            totalFindings: 0,
            criticalIssues: 0,
            warnings: 0,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 0,
            totalFiles: 0,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 0.0,
            estimatedFixTime: 0,
        );

        $this->cacheService
            ->method('get')
            ->willReturn(null);

        $this->fractorExecutor
            ->method('getVersion')
            ->willReturn('0.5.0');

        $this->ruleRegistry
            ->method('getSetsForVersionUpgrade')
            ->willReturn([]);

        // Guard is bypassed, so config generation and execution must happen
        $this->configGenerator
            ->expects($this->once())
            ->method('generateConfig')
            ->willReturn('/tmp/fractor_config.php');

        $this->fractorExecutor
            ->expects($this->once())
            ->method('execute')
            ->willReturn($executionResult);

        $this->resultParser
            ->expects($this->once())
            ->method('aggregateFindings')
            ->willReturn($summary);

        // PathResolutionService is never consulted when installation_path is empty
        $this->pathResolutionService
            ->expects($this->never())
            ->method('resolvePath');

        $this->analyzer->analyze($extension, $context);
    }
}
