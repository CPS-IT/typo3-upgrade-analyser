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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\LinesOfCodeAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\StrategyPriorityEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(LinesOfCodeAnalyzer::class)]
class LinesOfCodeAnalyzerTest extends TestCase
{
    private LinesOfCodeAnalyzer $subject;
    private MockObject $logger;
    private MockObject $cacheService;
    private MockObject $pathResolutionService;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheService = $this->createMock(CacheService::class);
        $this->pathResolutionService = $this->createMock(PathResolutionServiceInterface::class);

        // Default mock behavior - return failed responses (extensions not found)
        $this->pathResolutionService
            ->method('resolvePath')
            ->willReturnCallback(function (PathResolutionRequest $request): PathResolutionResponse {
                return $this->createFailedPathResolutionResponse('default_extension');
            });

        $this->subject = new LinesOfCodeAnalyzer($this->cacheService, $this->logger, $this->pathResolutionService);
    }

    private function createFailedPathResolutionResponse(string $extensionKey): PathResolutionResponse
    {
        $metadata = new PathResolutionMetadata(
            PathTypeEnum::EXTENSION,
            InstallationTypeEnum::COMPOSER_STANDARD,
            'test_strategy',
            StrategyPriorityEnum::HIGH->value,
            [],
            [],
            0.0,
            false,
            "Extension '{$extensionKey}' not found",
        );

        return PathResolutionResponse::notFound(
            PathTypeEnum::EXTENSION,
            $metadata,
            ["Extension '{$extensionKey}' not found"],
        );
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
            [],
        );

        $result = $this->subject->analyze($extension, $context);

        self::assertSame('lines_of_code', $result->getAnalyzerName());
        self::assertSame('test_ext', $result->getExtension()->getKey());
        self::assertFalse($result->isSuccessful());
        self::assertNotEmpty($result->getError());
        self::assertStringContainsString('No installation path', $result->getError());
    }

    public function testAnalyzeWithEmptyInstallationPath(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => ''],
        );

        $result = $this->subject->analyze($extension, $context);

        self::assertFalse($result->isSuccessful());
        self::assertNotEmpty($result->getError());
        self::assertStringContainsString('No installation path', $result->getError());
    }

    public function testAnalyzeWithInvalidRelativePath(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => 'invalid/relative/path'],
        );

        $result = $this->subject->analyze($extension, $context);

        self::assertFalse($result->isSuccessful());

        if ($error = $result->getError()) {
            self::assertStringContainsString('Invalid installation path', $error);
        }
    }

    private function createTempInstallationDir(): string
    {
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);

        return $tempDir;
    }

    private function cleanupTempDir(string $dir): void
    {
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    public function testAnalyzeWithNonExistentExtensionPath(): void
    {
        $extension = new Extension('non_existent_ext', 'Non Existent Extension', new Version('1.0.0'), 'local');
        $tempDir = sys_get_temp_dir() . '/test_installation_' . uniqid();
        mkdir($tempDir, 0o755, true);
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => $tempDir],
        );

        // Mock PathResolutionService to return a failed response
        $this->pathResolutionService
            ->expects(self::once())
            ->method('resolvePath')
            ->with(self::isInstanceOf(PathResolutionRequest::class))
            ->willReturn($this->createFailedPathResolutionResponse('non_existent_ext'));

        // Expect warning to be logged (either PathResolutionService failure or path not found)
        $this->logger->expects(self::atLeastOnce())
            ->method('warning');

        $result = $this->subject->analyze($extension, $context);

        // Clean up
        rmdir($tempDir);

        self::assertTrue($result->isSuccessful(), 'Analysis result should be successful, error: ' . $result->getError());
        self::assertSame(0, $result->getMetric('total_lines'));
        self::assertSame(0, $result->getMetric('php_files'));
        self::assertSame(0, $result->getMetric('classes'));
    }

    public function testAnalyzeWithValidAbsolutePath(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $tempDir = $this->createTempInstallationDir();
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => $tempDir],
        );

        $result = $this->subject->analyze($extension, $context);
        $this->cleanupTempDir($tempDir);

        // Should not error on a valid absolute path, even if the extension doesn't exist
        self::assertTrue($result->isSuccessful());
        self::assertGreaterThanOrEqual(0, $result->getRiskScore());
    }

    public function testAnalyzeLogsDebugInformation(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $tempDir = $this->createTempInstallationDir();
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => $tempDir],
        );

        $this->logger->expects(self::atLeastOnce())
            ->method('debug');

        $this->subject->analyze($extension, $context);
        $this->cleanupTempDir($tempDir);
    }

    public function testAnalyzeWithCustomPaths(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $customPaths = [
            'vendor-dir' => 'custom_vendor',
            'web-dir' => 'custom_web',
            'typo3conf-dir' => 'custom_web/typo3conf',
        ];
        $tempDir = $this->createTempInstallationDir();
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            [
                'installation_path' => $tempDir,
                'custom_paths' => $customPaths,
            ],
        );

        $result = $this->subject->analyze($extension, $context);
        $this->cleanupTempDir($tempDir);

        self::assertTrue($result->isSuccessful());
    }

    public function testAnalyzeWithComposerManagedExtension(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'third_party', 'vendor/test-extension');
        $tempDir = $this->createTempInstallationDir();
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => $tempDir],
        );

        $this->logger->expects(self::atLeastOnce())
            ->method('debug');

        $result = $this->subject->analyze($extension, $context);
        $this->cleanupTempDir($tempDir);

        self::assertTrue($result->isSuccessful());
    }

    public function testAnalyzeGeneratesRecommendations(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $tempDir = $this->createTempInstallationDir();

        // Create a sample extension directory with PHP files to generate recommendations
        $extensionPath = $tempDir . '/public/typo3conf/ext/test_ext';
        mkdir($extensionPath, 0o755, true);
        mkdir($extensionPath . '/Classes', 0o755, true);

        // Create sample PHP files with enough content to generate recommendations
        file_put_contents($extensionPath . '/Classes/Controller.php', str_repeat("<?php\n// Sample line\n", 100));
        file_put_contents($extensionPath . '/Classes/Model.php', str_repeat("<?php\n// Another line\n", 200));

        // Mock PathResolutionService to return the created extension path
        $this->pathResolutionService
            ->method('resolvePath')
            ->willReturnCallback(function () use ($extensionPath): \CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse {
                $metadata = new PathResolutionMetadata(
                    PathTypeEnum::EXTENSION,
                    InstallationTypeEnum::COMPOSER_STANDARD,
                    'test_strategy',
                    StrategyPriorityEnum::HIGH->value,
                );

                return PathResolutionResponse::success(
                    PathTypeEnum::EXTENSION,
                    $extensionPath,
                    $metadata,
                );
            });

        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => $tempDir],
        );

        $result = $this->subject->analyze($extension, $context);

        // Cleanup
        unlink($extensionPath . '/Classes/Controller.php');
        unlink($extensionPath . '/Classes/Model.php');
        rmdir($extensionPath . '/Classes');
        rmdir($extensionPath);
        rmdir($tempDir . '/public/typo3conf/ext');
        rmdir($tempDir . '/public/typo3conf');
        rmdir($tempDir . '/public');
        $this->cleanupTempDir($tempDir);

        $recommendations = $result->getRecommendations();
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
            ['installation_path' => '/test'],
        );

        $result = $this->subject->analyze($extension, $context);

        $riskScore = $result->getRiskScore();
        self::assertGreaterThanOrEqual(0.0, $riskScore);
        self::assertLessThanOrEqual(10.0, $riskScore);
    }

    public function testAnalyzeLogsCompletionInfo(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $tempDir = $this->createTempInstallationDir();
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => $tempDir],
        );

        $this->logger->expects(self::atLeastOnce())
            ->method('info');

        $result = $this->subject->analyze($extension, $context);
        $this->cleanupTempDir($tempDir);

        // Verify the result is successful and contains expected data
        self::assertTrue($result->isSuccessful());
        self::assertIsInt($result->getMetric('total_lines'));
        self::assertIsInt($result->getMetric('php_files'));
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
            ['installation_path' => '/test'],
        );

        $this->logger->expects(self::atLeastOnce())
            ->method('error');

        $result = $this->subject->analyze($extension, $context);

        self::assertFalse($result->isSuccessful());
        self::assertNotEmpty($result->getError());
    }

    public function testAnalyzeWithValidCurrentWorkingDirectory(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        // Use a relative path that will be resolved against getcwd()
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '.'],
        );

        $result = $this->subject->analyze($extension, $context);

        // Should resolve '.' to the current working directory
        self::assertTrue($result->isSuccessful());
    }
}
