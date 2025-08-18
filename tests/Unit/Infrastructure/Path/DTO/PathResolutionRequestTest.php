<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Path\DTO;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\ExtensionIdentifier;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception\InvalidRequestException;
use PHPUnit\Framework\TestCase;

/**
 * Test for PathResolutionRequest DTO.
 */
final class PathResolutionRequestTest extends TestCase
{
    private string $testPath;

    protected function setUp(): void
    {
        $this->testPath = sys_get_temp_dir();
    }

    public function testBuilderCreatesValidRequest(): void
    {
        $request = PathResolutionRequest::builder()
            ->pathType(PathTypeEnum::EXTENSION)
            ->installationPath($this->testPath)
            ->installationType(InstallationTypeEnum::COMPOSER_STANDARD)
            ->build();

        self::assertSame(PathTypeEnum::EXTENSION, $request->pathType);
        self::assertSame(realpath($this->testPath), $request->installationPath);
        self::assertSame(InstallationTypeEnum::COMPOSER_STANDARD, $request->installationType);
    }

    public function testBuilderWithExtensionIdentifier(): void
    {
        $extensionIdentifier = new ExtensionIdentifier('test_extension', '1.0.0');

        $request = PathResolutionRequest::builder()
            ->pathType(PathTypeEnum::EXTENSION)
            ->installationPath($this->testPath)
            ->installationType(InstallationTypeEnum::COMPOSER_STANDARD)
            ->extensionIdentifier($extensionIdentifier)
            ->build();

        self::assertSame($extensionIdentifier, $request->extensionIdentifier);
    }

    public function testBuilderValidatesRequiredFields(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Missing required fields: pathType');

        PathResolutionRequest::builder()
            ->installationPath($this->testPath)
            ->installationType(InstallationTypeEnum::COMPOSER_STANDARD)
            ->build();
    }

    public function testBuilderValidatesPathExists(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Installation path does not exist');

        PathResolutionRequest::builder()
            ->pathType(PathTypeEnum::EXTENSION)
            ->installationPath('/nonexistent/path')
            ->installationType(InstallationTypeEnum::COMPOSER_STANDARD)
            ->build();
    }

    public function testBuilderValidatesCompatibility(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Path type vendor_dir is not compatible with installation type legacy_source');

        PathResolutionRequest::builder()
            ->pathType(PathTypeEnum::VENDOR_DIR)
            ->installationPath($this->testPath)
            ->installationType(InstallationTypeEnum::LEGACY_SOURCE)
            ->build();
    }

    public function testGetCacheKeyIsConsistent(): void
    {
        $request1 = PathResolutionRequest::builder()
            ->pathType(PathTypeEnum::EXTENSION)
            ->installationPath($this->testPath)
            ->installationType(InstallationTypeEnum::COMPOSER_STANDARD)
            ->build();

        $request2 = PathResolutionRequest::builder()
            ->pathType(PathTypeEnum::EXTENSION)
            ->installationPath($this->testPath)
            ->installationType(InstallationTypeEnum::COMPOSER_STANDARD)
            ->build();

        self::assertSame($request1->getCacheKey(), $request2->getCacheKey());
    }

    public function testWithExtensionIdentifier(): void
    {
        $originalRequest = PathResolutionRequest::builder()
            ->pathType(PathTypeEnum::EXTENSION)
            ->installationPath($this->testPath)
            ->installationType(InstallationTypeEnum::COMPOSER_STANDARD)
            ->build();

        $extensionIdentifier = new ExtensionIdentifier('test_extension');
        $modifiedRequest = $originalRequest->withExtensionIdentifier($extensionIdentifier);

        self::assertNull($originalRequest->extensionIdentifier);
        self::assertSame($extensionIdentifier, $modifiedRequest->extensionIdentifier);
        self::assertNotSame($originalRequest, $modifiedRequest);
    }
}
