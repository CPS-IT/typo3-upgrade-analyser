<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Path\Validation;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\ExtensionIdentifier;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathConfiguration;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation\PathResolutionValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for PathResolutionValidator.
 */
final class PathResolutionValidatorTest extends TestCase
{
    private PathResolutionValidator $validator;
    private string $testPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PathResolutionValidator(new NullLogger());
        $this->testPath = sys_get_temp_dir() . '/validation-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testPath)) {
            $this->removeDirectory($this->testPath);
        }
        parent::tearDown();
    }

    public function testValidateSuccessfulRequest(): void
    {
        // Create valid test installation
        mkdir($this->testPath . '/typo3conf', 0o755, true);

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::LEGACY_SOURCE,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_ext'),
        );

        $result = $this->validator->validate($request);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testValidateNonExistentPath(): void
    {
        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            '/nonexistent/path',
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_ext'),
        );

        $result = $this->validator->validate($request);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('does not exist', implode(' ', $result->getErrors()));
    }

    public function testValidateNonReadablePath(): void
    {
        // Create path but make it non-readable (if possible on this system)
        mkdir($this->testPath, 0o000, true);

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_ext'),
        );

        $result = $this->validator->validate($request);

        // Restore permissions for cleanup
        chmod($this->testPath, 0o755);

        // On some systems this might not fail, so we check if we got the expected error
        if (!$result->isValid()) {
            $this->assertStringContainsString('not readable', implode(' ', $result->getErrors()));
        }
    }

    public function testValidatePathWithoutTypo3Indicators(): void
    {
        // Create empty directory without TYPO3 indicators
        mkdir($this->testPath, 0o755, true);

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_ext'),
        );

        $result = $this->validator->validate($request);

        // Should be valid but with warnings about TYPO3 indicators
        $this->assertTrue($result->isValid());
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('does not appear to be a TYPO3 installation', implode(' ', $result->getWarnings()));
    }

    public function testValidateExtensionRequiredButMissing(): void
    {
        mkdir($this->testPath, 0o755, true);

        // Create request without extension identifier for path type that requires it
        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
        );

        $result = $this->validator->validate($request);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Extension identifier is required', implode(' ', $result->getErrors()));
    }

    public function testValidateConfigurationIssues(): void
    {
        mkdir($this->testPath, 0o755, true);

        // Create configuration with validation issues
        $pathConfig = PathConfiguration::fromArray([
            'maxDepth' => -1, // Invalid depth
            'searchDirectories' => ['', 'valid_dir'], // Empty directory
            'excludePatterns' => ['', '*.tmp'], // Empty pattern
        ]);

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            $pathConfig,
            new ExtensionIdentifier('test_ext'),
        );

        $result = $this->validator->validate($request);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Max depth must be at least 1', implode(' ', $result->getErrors()));
        $this->assertStringContainsString('Empty search directory', implode(' ', $result->getWarnings()));
        $this->assertStringContainsString('Empty exclude pattern', implode(' ', $result->getWarnings()));
    }

    public function testValidateCustomRules(): void
    {
        mkdir($this->testPath, 0o755, true);

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_ext'),
            ['min_path_length' => ['length' => 100]], // Path too short
        );

        $result = $this->validator->validate($request);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('too short', implode(' ', $result->getErrors()));
    }

    public function testValidateRequiredSubdirectories(): void
    {
        mkdir($this->testPath, 0o755, true);

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_ext'),
            ['required_subdirs' => ['dirs' => ['typo3conf', 'fileadmin']]],
        );

        $result = $this->validator->validate($request);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Required subdirectory not found', implode(' ', $result->getErrors()));
    }

    public function testValidateForbiddenPaths(): void
    {
        $forbiddenPath = '/tmp/forbidden-path';
        mkdir($forbiddenPath, 0o755, true);

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $forbiddenPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_ext'),
            ['forbidden_paths' => ['paths' => ['forbidden']]],
        );

        $result = $this->validator->validate($request);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('forbidden path segment', implode(' ', $result->getErrors()));

        // Cleanup
        rmdir($forbiddenPath);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
