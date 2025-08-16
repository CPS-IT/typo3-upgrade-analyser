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

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use PHPUnit\Framework\TestCase;

/**
 * Test for PathResolutionResponse DTO.
 */
final class PathResolutionResponseTest extends TestCase
{
    private PathResolutionMetadata $metadata;

    protected function setUp(): void
    {
        $this->metadata = new PathResolutionMetadata(
            PathTypeEnum::EXTENSION,
            InstallationTypeEnum::COMPOSER_STANDARD,
            'test_strategy',
            50,
        );
    }

    public function testSuccessFactory(): void
    {
        $response = PathResolutionResponse::success(
            PathTypeEnum::EXTENSION,
            '/path/to/extension',
            $this->metadata,
            ['/alternative/path'],
            ['warning message'],
        );

        self::assertTrue($response->isSuccess());
        self::assertFalse($response->isError());
        self::assertFalse($response->isNotFound());
        self::assertSame('/path/to/extension', $response->resolvedPath);
        self::assertSame(['/alternative/path'], $response->alternativePaths);
        self::assertSame(['warning message'], $response->warnings);
        self::assertEmpty($response->errors);
        self::assertTrue($response->hasWarnings());
        self::assertFalse($response->hasErrors());
    }

    public function testNotFoundFactory(): void
    {
        $response = PathResolutionResponse::notFound(
            PathTypeEnum::EXTENSION,
            $this->metadata,
            ['/suggested/path1', '/suggested/path2'],
        );

        self::assertFalse($response->isSuccess());
        self::assertFalse($response->isError());
        self::assertTrue($response->isNotFound());
        self::assertNull($response->resolvedPath);
        self::assertSame(['/suggested/path1', '/suggested/path2'], $response->alternativePaths);
        self::assertSame('/suggested/path1', $response->getBestAlternative());
    }

    public function testErrorFactory(): void
    {
        $response = PathResolutionResponse::error(
            PathTypeEnum::EXTENSION,
            $this->metadata,
            ['error message'],
            ['warning message'],
        );

        self::assertFalse($response->isSuccess());
        self::assertTrue($response->isError());
        self::assertFalse($response->isNotFound());
        self::assertNull($response->resolvedPath);
        self::assertSame(['error message'], $response->errors);
        self::assertSame(['warning message'], $response->warnings);
        self::assertTrue($response->hasErrors());
        self::assertTrue($response->hasWarnings());
    }

    public function testToArray(): void
    {
        $response = PathResolutionResponse::success(
            PathTypeEnum::EXTENSION,
            '/path/to/extension',
            $this->metadata,
        );

        $array = $response->toArray();

        self::assertSame('success', $array['status']);
        self::assertSame('extension', $array['pathType']);
        self::assertSame('/path/to/extension', $array['resolvedPath']);
        self::assertIsArray($array['metadata']);
        self::assertSame('extension', $array['metadata']['pathType']);
        self::assertSame('composer_standard', $array['metadata']['installationType']);
    }

    public function testGetBestAlternativeReturnsNullWhenEmpty(): void
    {
        $response = PathResolutionResponse::notFound(
            PathTypeEnum::EXTENSION,
            $this->metadata,
            [], // No alternatives
        );

        self::assertNull($response->getBestAlternative());
    }
}
