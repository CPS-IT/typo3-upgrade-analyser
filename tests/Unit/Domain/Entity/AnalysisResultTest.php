<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\Entity;

use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalysisResult::class)]
class AnalysisResultTest extends TestCase
{
    private AnalysisResult $analysisResult;
    private Extension $extension;

    protected function setUp(): void
    {
        $this->extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'));
        $this->analysisResult = new AnalysisResult('version_availability', $this->extension);
    }

    public function testConstructorSetsProperties(): void
    {
        self::assertEquals('version_availability', $this->analysisResult->getAnalyzerName());
        self::assertSame($this->extension, $this->analysisResult->getExtension());
        self::assertInstanceOf(\DateTimeImmutable::class, $this->analysisResult->getExecutedAt());
    }

    public function testExecutedAtIsSetOnConstruction(): void
    {
        $beforeCreation = new \DateTimeImmutable();
        $result = new AnalysisResult('test_analyzer', $this->extension);
        $afterCreation = new \DateTimeImmutable();

        $executedAt = $result->getExecutedAt();

        self::assertGreaterThanOrEqual($beforeCreation->getTimestamp(), $executedAt->getTimestamp());
        self::assertLessThanOrEqual($afterCreation->getTimestamp(), $executedAt->getTimestamp());
    }

    public function testMetricManagement(): void
    {
        $this->analysisResult->addMetric('ter_available', true);
        $this->analysisResult->addMetric('packagist_available', false);
        $this->analysisResult->addMetric('version_count', 5);

        self::assertTrue($this->analysisResult->hasMetric('ter_available'));
        self::assertTrue($this->analysisResult->hasMetric('packagist_available'));
        self::assertTrue($this->analysisResult->hasMetric('version_count'));
        self::assertFalse($this->analysisResult->hasMetric('non_existent'));

        self::assertTrue($this->analysisResult->getMetric('ter_available'));
        self::assertFalse($this->analysisResult->getMetric('packagist_available'));
        self::assertEquals(5, $this->analysisResult->getMetric('version_count'));
        self::assertNull($this->analysisResult->getMetric('non_existent'));

        $allMetrics = $this->analysisResult->getMetrics();
        self::assertCount(3, $allMetrics);
        self::assertEquals([
            'ter_available' => true,
            'packagist_available' => false,
            'version_count' => 5,
        ], $allMetrics);
    }

    public function testRiskScoreManagement(): void
    {
        // Test default risk score
        self::assertEquals(0.0, $this->analysisResult->getRiskScore());

        // Test setting valid risk score
        $this->analysisResult->setRiskScore(7.5);
        self::assertEquals(7.5, $this->analysisResult->getRiskScore());

        // Test boundary values
        $this->analysisResult->setRiskScore(0.0);
        self::assertEquals(0.0, $this->analysisResult->getRiskScore());

        $this->analysisResult->setRiskScore(10.0);
        self::assertEquals(10.0, $this->analysisResult->getRiskScore());
    }

    public function testRiskScoreValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Risk score must be between 0.0 and 10.0');

        $this->analysisResult->setRiskScore(11.0);
    }

    public function testRiskScoreValidationNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Risk score must be between 0.0 and 10.0');

        $this->analysisResult->setRiskScore(-1.0);
    }

    public function testRiskLevelCalculation(): void
    {
        // Test low risk
        $this->analysisResult->setRiskScore(1.5);
        self::assertEquals('low', $this->analysisResult->getRiskLevel());

        $this->analysisResult->setRiskScore(2.0);
        self::assertEquals('low', $this->analysisResult->getRiskLevel());

        // Test medium risk
        $this->analysisResult->setRiskScore(3.0);
        self::assertEquals('medium', $this->analysisResult->getRiskLevel());

        $this->analysisResult->setRiskScore(5.0);
        self::assertEquals('medium', $this->analysisResult->getRiskLevel());

        // Test high risk
        $this->analysisResult->setRiskScore(6.0);
        self::assertEquals('high', $this->analysisResult->getRiskLevel());

        $this->analysisResult->setRiskScore(8.0);
        self::assertEquals('high', $this->analysisResult->getRiskLevel());

        // Test critical risk
        $this->analysisResult->setRiskScore(9.0);
        self::assertEquals('critical', $this->analysisResult->getRiskLevel());

        $this->analysisResult->setRiskScore(10.0);
        self::assertEquals('critical', $this->analysisResult->getRiskLevel());
    }

    public function testRecommendationManagement(): void
    {
        $recommendation1 = 'Update to latest version';
        $recommendation2 = 'Check for breaking changes';

        $this->analysisResult->addRecommendation($recommendation1);
        $this->analysisResult->addRecommendation($recommendation2);

        $recommendations = $this->analysisResult->getRecommendations();

        self::assertCount(2, $recommendations);
        self::assertEquals($recommendation1, $recommendations[0]);
        self::assertEquals($recommendation2, $recommendations[1]);
    }

    public function testErrorHandling(): void
    {
        // Test no error initially
        self::assertEmpty($this->analysisResult->getError());
        self::assertFalse($this->analysisResult->hasError());
        self::assertTrue($this->analysisResult->isSuccessful());

        // Test setting error
        $errorMessage = 'Analysis failed due to network timeout';
        $this->analysisResult->setError($errorMessage);

        self::assertEquals($errorMessage, $this->analysisResult->getError());
        self::assertTrue($this->analysisResult->hasError());
        self::assertFalse($this->analysisResult->isSuccessful());
    }

    public function testToArray(): void
    {
        $this->analysisResult->addMetric('test_metric', 'test_value');
        $this->analysisResult->addMetric('number_metric', 42);
        $this->analysisResult->setRiskScore(6.5);
        $this->analysisResult->addRecommendation('First recommendation');
        $this->analysisResult->addRecommendation('Second recommendation');

        $array = $this->analysisResult->toArray();

        // Check required keys
        $expectedKeys = [
            'analyzer_name',
            'extension_key',
            'metrics',
            'risk_score',
            'risk_level',
            'recommendations',
            'executed_at',
            'error',
            'successful',
        ];

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $array);
        }

        // Check values
        self::assertEquals('version_availability', $array['analyzer_name']);
        self::assertEquals('test_ext', $array['extension_key']);
        self::assertEquals([
            'test_metric' => 'test_value',
            'number_metric' => 42,
        ], $array['metrics']);
        self::assertEquals(6.5, $array['risk_score']);
        self::assertEquals('high', $array['risk_level']);
        self::assertEquals(['First recommendation', 'Second recommendation'], $array['recommendations']);
        self::assertIsString($array['executed_at']);
        self::assertSame('', $array['error']);
        self::assertTrue($array['successful']);
    }

    public function testToArrayWithError(): void
    {
        $this->analysisResult->setError('Test error message');

        $array = $this->analysisResult->toArray();

        self::assertEquals('Test error message', $array['error']);
        self::assertFalse($array['successful']);
    }

    public function testExecutedAtFormat(): void
    {
        $array = $this->analysisResult->toArray();

        // Verify the executed_at is in ATOM format
        $executedAt = $array['executed_at'];
        self::assertIsString($executedAt);

        // Try to parse it back to verify format
        $dateTime = \DateTimeImmutable::createFromFormat(\DateTime::ATOM, $executedAt);
        self::assertInstanceOf(\DateTimeImmutable::class, $dateTime);
    }

    public function testMetricTypesHandling(): void
    {
        // Test various metric types
        $this->analysisResult->addMetric('boolean_metric', true);
        $this->analysisResult->addMetric('integer_metric', 123);
        $this->analysisResult->addMetric('float_metric', 45.67);
        $this->analysisResult->addMetric('string_metric', 'test string');
        $this->analysisResult->addMetric('array_metric', ['item1', 'item2']);
        $this->analysisResult->addMetric('null_metric', null);

        $metrics = $this->analysisResult->getMetrics();

        self::assertTrue($metrics['boolean_metric']);
        self::assertEquals(123, $metrics['integer_metric']);
        self::assertEquals(45.67, $metrics['float_metric']);
        self::assertEquals('test string', $metrics['string_metric']);
        self::assertEquals(['item1', 'item2'], $metrics['array_metric']);
        self::assertNull($metrics['null_metric']);
    }
}
