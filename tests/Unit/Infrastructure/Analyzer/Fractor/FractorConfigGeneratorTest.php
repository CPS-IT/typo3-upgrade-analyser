<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Fractor;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorConfigGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FractorConfigGenerator::class)]
class FractorConfigGeneratorTest extends TestCase
{
    private FractorConfigGenerator $generator;
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/fractor_test_' . uniqid();
        $this->generator = new FractorConfigGenerator($this->tempDirectory);
    }

    protected function tearDown(): void
    {
        // Clean up generated config files
        if (is_dir($this->tempDirectory)) {
            $files = glob($this->tempDirectory . '/*');
            if (false !== $files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->tempDirectory);
        }
    }

    #[Test]
    public function generateConfigCreatesValidConfigFile(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', Version::fromString('1.0.0'));
        $context = new AnalysisContext(
            Version::fromString('12.4.0'),
            Version::fromString('13.0.0'),
            [],
        );

        $configPath = $this->generator->generateConfig($extension, $context, '/test/extension/path');

        self::assertFileExists($configPath);
        self::assertStringContainsString('test_ext', $configPath);

        $configContent = file_get_contents($configPath);
        self::assertIsString($configContent);
        self::assertStringContainsString('FractorConfiguration::configure()', $configContent);
        self::assertStringContainsString('Typo3LevelSetList::UP_TO_TYPO3_13', $configContent);
        self::assertStringContainsString('TypoScriptProcessorOption::', $configContent);
        self::assertStringContainsString('/test/extension/path', $configContent);
    }

    #[Test]
    public function generateConfigUsesCorrectTypo3SetForDifferentVersions(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', Version::fromString('1.0.0'));

        // Test TYPO3 12 target
        $context12 = new AnalysisContext(
            Version::fromString('11.5.0'),
            Version::fromString('12.4.0'),
            [],
        );

        $configPath12 = $this->generator->generateConfig($extension, $context12, '/test/extension/path');
        $configContent12 = file_get_contents($configPath12);
        self::assertIsString($configContent12);
        self::assertStringContainsString('Typo3LevelSetList::UP_TO_TYPO3_12', $configContent12);

        // Test TYPO3 11 target
        $context11 = new AnalysisContext(
            Version::fromString('10.4.0'),
            Version::fromString('11.5.0'),
            [],
        );

        $configPath11 = $this->generator->generateConfig($extension, $context11, '/test/extension/path');
        $configContent11 = file_get_contents($configPath11);
        self::assertIsString($configContent11);
        self::assertStringContainsString('Typo3LevelSetList::UP_TO_TYPO3_11', $configContent11);
    }

    #[Test]
    public function generateConfigContainsExpectedSkipPatterns(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', Version::fromString('1.0.0'));
        $context = new AnalysisContext(
            Version::fromString('12.4.0'),
            Version::fromString('13.0.0'),
            [],
        );

        $configPath = $this->generator->generateConfig($extension, $context, '/test/extension/path');
        $configContent = file_get_contents($configPath);
        self::assertIsString($configContent);

        // Check for expected skip patterns
        self::assertStringContainsString('*/Tests/*', $configContent);
        self::assertStringContainsString('*/vendor/*', $configContent);
        self::assertStringContainsString('*/Resources/Public/*', $configContent);
        self::assertStringContainsString('*/Documentation/*', $configContent);
    }

    #[Test]
    public function generateConfigContainsTypoScriptOptions(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', Version::fromString('1.0.0'));
        $context = new AnalysisContext(
            Version::fromString('12.4.0'),
            Version::fromString('13.0.0'),
            [],
        );

        $configPath = $this->generator->generateConfig($extension, $context, '/test/extension/path');
        $configContent = file_get_contents($configPath);
        self::assertIsString($configContent);

        // Check for TypoScript options
        self::assertStringContainsString('INDENT_SIZE => 2', $configContent);
        self::assertStringContainsString('INDENTATION_STYLE_SPACES', $configContent);
        self::assertStringContainsString('ADD_CLOSING_GLOBAL => true', $configContent);
        self::assertStringContainsString('INCLUDE_EMPTY_LINE_BREAKS => true', $configContent);
    }

    #[Test]
    public function generateConfigCreatesReadablePhpFile(): void
    {
        $extension = new Extension('my_extension', 'My Extension', Version::fromString('2.1.0'));
        $context = new AnalysisContext(
            Version::fromString('12.4.0'),
            Version::fromString('13.0.0'),
            [],
        );

        $configPath = $this->generator->generateConfig($extension, $context, '/test/extension/path');
        $configContent = file_get_contents($configPath);
        self::assertIsString($configContent);

        // Verify it's valid PHP syntax by checking for basic elements
        self::assertStringStartsWith('<?php', $configContent);
        self::assertStringContainsString('declare(strict_types=1);', $configContent);

        // Check for comments with extension info
        self::assertStringContainsString('my_extension', $configContent);
        self::assertStringContainsString('13.0.0', $configContent);
        self::assertStringContainsString('Generated by TYPO3 Upgrade Analyzer', $configContent);
    }
}
