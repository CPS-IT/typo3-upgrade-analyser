<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiResponseParser
 */
class TerApiResponseParserTest extends TestCase
{
    private TerApiResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TerApiResponseParser();
    }

    public function testParseExtensionDataWithValidData(): void
    {
        $responseData = [
            [
                'key' => 'news',
                'name' => 'News',
                'description' => 'News extension'
            ]
        ];

        $result = $this->parser->parseExtensionData($responseData);

        self::assertNotNull($result);
        self::assertSame('news', $result['key']);
        self::assertSame('News', $result['name']);
        self::assertSame('News extension', $result['description']);
    }

    public function testParseExtensionDataWithEmptyArray(): void
    {
        $result = $this->parser->parseExtensionData([]);

        self::assertNull($result);
    }

    public function testParseExtensionDataWithMissingFirstElement(): void
    {
        $responseData = [
            1 => ['key' => 'news'] // Missing index 0
        ];

        $result = $this->parser->parseExtensionData($responseData);

        self::assertNull($result);
    }

    public function testParseVersionsDataWithValidData(): void
    {
        $responseData = [
            [
                [
                    'number' => '1.0.0',
                    'typo3_versions' => [11, 12]
                ],
                [
                    'number' => '2.0.0',
                    'typo3_versions' => [12, 13]
                ]
            ]
        ];

        $result = $this->parser->parseVersionsData($responseData);

        self::assertNotNull($result);
        self::assertCount(2, $result);
        self::assertSame('1.0.0', $result[0]['number']);
        self::assertSame([11, 12], $result[0]['typo3_versions']);
    }

    public function testParseVersionsDataWithEmptyArray(): void
    {
        $result = $this->parser->parseVersionsData([]);

        self::assertNull($result);
    }

    public function testExtractExtensionKeyWithValidData(): void
    {
        $extensionData = ['key' => 'news', 'name' => 'News'];

        $result = $this->parser->extractExtensionKey($extensionData);

        self::assertSame('news', $result);
    }

    public function testExtractExtensionKeyWithMissingKey(): void
    {
        $extensionData = ['name' => 'News'];

        $result = $this->parser->extractExtensionKey($extensionData);

        self::assertNull($result);
    }

    public function testExtractVersionNumbersWithValidData(): void
    {
        $versions = [
            ['number' => '1.0.0', 'typo3_versions' => [11]],
            ['number' => '2.0.0', 'typo3_versions' => [12]],
            ['typo3_versions' => [13]] // Missing number field
        ];

        $result = $this->parser->extractVersionNumbers($versions);

        self::assertSame(['1.0.0', '2.0.0'], $result);
    }

    public function testExtractVersionNumbersWithEmptyArray(): void
    {
        $result = $this->parser->extractVersionNumbers([]);

        self::assertSame([], $result);
    }

    public function testExtractVersionNumbersWithNoValidVersions(): void
    {
        $versions = [
            ['typo3_versions' => [11]],
            ['description' => 'Some version']
        ];

        $result = $this->parser->extractVersionNumbers($versions);

        self::assertSame([], $result);
    }
}