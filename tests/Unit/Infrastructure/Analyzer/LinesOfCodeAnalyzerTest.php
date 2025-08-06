<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ExtensionType;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\LinesOfCodeAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\LinesOfCodeAnalyzer
 */
class LinesOfCodeAnalyzerTest extends TestCase
{
    private LinesOfCodeAnalyzer $subject;
    private LoggerInterface $logger;
    private CacheService $cacheService;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheService = $this->createMock(CacheService::class);
        $this->subject = new LinesOfCodeAnalyzer($this->cacheService, $this->logger);
    }

    public function testImplementsAnalyzerInterface(): void
    {
        self::assertInstanceOf(AnalyzerInterface::class, $this->subject);
    }

    public function testGetNameReturnsCorrectName(): void
    {
        self::assertSame('lines_of_code', $this->subject->getName());
    }

    public function testGetDescriptionReturnsCorrectDescription(): void
    {
        $description = $this->subject->getDescription();
        
        self::assertIsString($description);
        self::assertStringContainsString('lines of code', $description);
        self::assertStringContainsString('codebase size', $description);
        self::assertStringContainsString('complexity', $description);
    }

    public function testSupportsReturnsTrueForNonSystemExtensions(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        
        self::assertTrue($this->subject->supports($extension));
    }

    public function testSupportsReturnsFalseForSystemExtensions(): void
    {
        $extension = new Extension('core', 'Core Extension', new Version('12.0.0'), 'system');
        
        self::assertFalse($this->subject->supports($extension));
    }

    public function testHasRequiredToolsReturnsTrue(): void
    {
        self::assertTrue($this->subject->hasRequiredTools());
    }

    public function testGetRequiredToolsReturnsEmptyArray(): void
    {
        self::assertSame([], $this->subject->getRequiredTools());
    }

    public function testAnalyzeWithMissingInstallationPath(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            []
        );
        
        $result = $this->subject->analyze($extension, $context);
        
        self::assertSame('lines_of_code', $result->getAnalyzerName());
        self::assertSame('test_ext', $result->getExtension()->getKey());
        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('No installation path', $result->getError());
    }

    public function testAnalyzeWithEmptyInstallationPath(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '']
        );
        
        $result = $this->subject->analyze($extension, $context);
        
        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('No installation path', $result->getError());
    }

    public function testAnalyzeWithInvalidRelativePath(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => 'invalid/relative/path']
        );
        
        $result = $this->subject->analyze($extension, $context);
        
        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('Invalid installation path', $result->getError());
    }

    public function testAnalyzeWithNonExistentExtensionPath(): void
    {
        $extension = new Extension('non_existent_ext', 'Non Existent Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '/nonexistent']
        );
        
        $this->logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                'Extension path not found for LOC analysis',
                self::callback(function ($context) {
                    return isset($context['extension']) && $context['extension'] === 'non_existent_ext';
                })
            );

        $result = $this->subject->analyze($extension, $context);
        
        self::assertTrue($result->isSuccessful());
        self::assertSame(0, $result->getMetric('total_lines'));
        self::assertSame(0, $result->getMetric('php_files'));
        self::assertSame(0, $result->getMetric('classes'));
    }

    public function testAnalyzeWithValidAbsolutePath(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '/valid/absolute/path']
        );
        
        $result = $this->subject->analyze($extension, $context);
        
        // Should not error on valid absolute path, even if extension doesn't exist
        self::assertTrue($result->isSuccessful());
        self::assertIsFloat($result->getRiskScore());
        self::assertGreaterThanOrEqual(0, $result->getRiskScore());
    }

    public function testAnalyzeLogsDebugInformation(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '/test']
        );
        
        $this->logger->expects(self::atLeastOnce())
            ->method('debug');
        
        $this->subject->analyze($extension, $context);
    }

    public function testAnalyzeWithCustomPaths(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $customPaths = [
            'vendor-dir' => 'custom_vendor',
            'web-dir' => 'custom_web',
            'typo3conf-dir' => 'custom_web/typo3conf'
        ];
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            [
                'installation_path' => '/test',
                'custom_paths' => $customPaths
            ]
        );
        
        $result = $this->subject->analyze($extension, $context);
        
        self::assertTrue($result->isSuccessful());
    }

    public function testAnalyzeWithComposerManagedExtension(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'third_party', 'vendor/test-extension');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '/test']
        );
        
        $this->logger->expects(self::atLeastOnce())
            ->method('debug');
        
        $result = $this->subject->analyze($extension, $context);
        
        self::assertTrue($result->isSuccessful());
    }

    public function testAnalyzeGeneratesRecommendations(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '/test']
        );
        
        $result = $this->subject->analyze($extension, $context);
        
        $recommendations = $result->getRecommendations();
        self::assertIsArray($recommendations);
        self::assertNotEmpty($recommendations);
        self::assertStringContainsString('lines', $recommendations[0]);
    }

    public function testAnalyzeCalculatesRiskScore(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '/test']
        );
        
        $result = $this->subject->analyze($extension, $context);
        
        $riskScore = $result->getRiskScore();
        self::assertIsFloat($riskScore);
        self::assertGreaterThanOrEqual(0.0, $riskScore);
        self::assertLessThanOrEqual(10.0, $riskScore);
    }

    public function testAnalyzeLogsCompletionInfo(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '/test']
        );
        
        $this->logger->expects(self::atLeastOnce())
            ->method('info');
        
        $result = $this->subject->analyze($extension, $context);
        
        // Verify the result is successful and contains expected data
        self::assertTrue($result->isSuccessful());
        self::assertIsInt($result->getMetric('total_lines'));
        self::assertIsInt($result->getMetric('php_files'));
        self::assertIsFloat($result->getRiskScore());
    }

    public function testAnalyzeHandlesExceptions(): void
    {
        $extension = $this->createMock(Extension::class);
        $extension->method('getKey')->willReturn('test_ext');
        $extension->method('isSystemExtension')->willReturn(false);
        $extension->method('getType')->willReturn('local');
        $extension->method('getComposerName')->willThrowException(new \RuntimeException('Test error'));
        
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '/test']
        );
        
        $this->logger->expects(self::atLeastOnce())
            ->method('error');
        
        $result = $this->subject->analyze($extension, $context);
        
        self::assertFalse($result->isSuccessful());
        self::assertNotNull($result->getError());
    }

    public function testAnalyzeWithValidCurrentWorkingDirectory(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        // Use relative path that will be resolved against getcwd()
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '.']
        );
        
        $result = $this->subject->analyze($extension, $context);
        
        // Should resolve . to current working directory
        self::assertTrue($result->isSuccessful());
    }
}