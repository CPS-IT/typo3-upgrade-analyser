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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(FractorConfigGenerator::class)]
class FractorConfigGeneratorTest extends TestCase
{
    private FractorConfigGenerator $generator;
    private FractorRuleRegistry&MockObject $ruleRegistry;
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/fractor_test_' . uniqid();
        $this->ruleRegistry = $this->createMock(FractorRuleRegistry::class);
        $this->generator = new FractorConfigGenerator($this->ruleRegistry, $this->tempDirectory);
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

        $this->ruleRegistry->expects(self::any())->method('getSetsForVersionUpgrade')
            ->willReturn(['/path/to/typo3-13.php']);

        $configPath = $this->generator->generateConfig($extension, $context, '/test/extension/path');

        self::assertFileExists($configPath);
        self::assertStringContainsString('test_ext', $configPath);

        $configContent = file_get_contents($configPath);
        self::assertIsString($configContent);
        self::assertStringContainsString('FractorConfiguration::configure()', $configContent);
        self::assertStringContainsString('/path/to/typo3-13.php', $configContent);
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

        $this->ruleRegistry->expects(self::exactly(2))
            ->method('getSetsForVersionUpgrade')
            ->willReturnCallback(function (Version $current, Version $target) {
                if ('12.4.0' === $target->toString()) {
                    return ['/path/to/typo3-12.php'];
                }
                if ('11.5.0' === $target->toString()) {
                    return ['/path/to/typo3-11.php'];
                }

                return [];
            });

        $configPath12 = $this->generator->generateConfig($extension, $context12, '/test/extension/path');
        $configContent12 = file_get_contents($configPath12);
        self::assertIsString($configContent12);
        self::assertStringContainsString('/path/to/typo3-12.php', $configContent12);

        // Test TYPO3 11 target
        $context11 = new AnalysisContext(
            Version::fromString('10.4.0'),
            Version::fromString('11.5.0'),
            [],
        );

        $configPath11 = $this->generator->generateConfig($extension, $context11, '/test/extension/path');
        $configContent11 = file_get_contents($configPath11);
        self::assertIsString($configContent11);
        self::assertStringContainsString('/path/to/typo3-11.php', $configContent11);
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

        $this->ruleRegistry->expects(self::any())->method('getSetsForVersionUpgrade')->willReturn([]);

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

        $this->ruleRegistry->expects(self::any())->method('getSetsForVersionUpgrade')->willReturn([]);

        // Pass custom options
        $options = [
            'typoscript' => [
                'indent_size' => 4,
                'include_empty_line_breaks' => false,
            ],
        ];

        $configPath = $this->generator->generateConfig($extension, $context, '/test/extension/path', $options);
        $configContent = file_get_contents($configPath);
        self::assertIsString($configContent);

        // Check for TypoScript options - checking values since keys are constants that var_export might evaluate
        self::assertStringContainsString('=> 4', $configContent); // Indent size should be 4
        self::assertStringContainsString('=> false', $configContent); // Boolean option should be false
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

        $this->ruleRegistry->expects(self::any())->method('getSetsForVersionUpgrade')->willReturn([]);

        $configPath = $this->generator->generateConfig($extension, $context, '/test/extension/path');
        $configContent = file_get_contents($configPath);
        self::assertIsString($configContent);

        // Verify it's valid PHP syntax by checking for basic elements
        self::assertStringStartsWith('<?php', $configContent);
        self::assertStringContainsString('declare(strict_types=1);', $configContent);
    }
}
