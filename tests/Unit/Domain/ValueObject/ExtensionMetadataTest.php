<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\ValueObject;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ExtensionMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionMetadata::class)]
final class ExtensionMetadataTest extends TestCase
{
    private \DateTimeImmutable $testDate;

    protected function setUp(): void
    {
        $this->testDate = new \DateTimeImmutable('2023-12-01 15:30:00');
    }

    public function testConstructorSetsAllProperties(): void
    {
        $description = 'Test extension for TYPO3';
        $author = 'John Doe';
        $authorEmail = 'john@example.com';
        $keywords = ['test', 'typo3', 'extension'];
        $license = 'GPL-2.0-or-later';
        $supportedPhpVersions = ['8.1', '8.2'];
        $supportedTypo3Versions = ['12.4', '13.0'];
        $additionalData = ['source' => 'local'];

        $metadata = new ExtensionMetadata(
            $description,
            $author,
            $authorEmail,
            $keywords,
            $license,
            $supportedPhpVersions,
            $supportedTypo3Versions,
            $this->testDate,
            $additionalData,
        );

        self::assertSame($description, $metadata->getDescription());
        self::assertSame($author, $metadata->getAuthor());
        self::assertSame($authorEmail, $metadata->getAuthorEmail());
        self::assertSame($keywords, $metadata->getKeywords());
        self::assertSame($license, $metadata->getLicense());
        self::assertSame($supportedPhpVersions, $metadata->getSupportedPhpVersions());
        self::assertSame($supportedTypo3Versions, $metadata->getSupportedTypo3Versions());
        self::assertSame($this->testDate, $metadata->getLastModified());
        self::assertSame($additionalData, $metadata->getAdditionalData());
    }

    public function testConstructorWithEmptyAdditionalData(): void
    {
        $metadata = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
        );

        self::assertSame([], $metadata->getAdditionalData());
    }

    #[DataProvider('hasKeywordProvider')]
    public function testHasKeyword(array $keywords, string $searchKeyword, bool $expected): void
    {
        $metadata = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            $keywords,
            'GPL-2.0',
            [],
            [],
            $this->testDate,
        );

        self::assertSame($expected, $metadata->hasKeyword($searchKeyword));
    }

    /**
     * @return array<string, array{array<string>, string, bool}>
     */
    public static function hasKeywordProvider(): array
    {
        return [
            'exact match' => [['typo3', 'extension'], 'typo3', true],
            'case insensitive match' => [['TYPO3', 'Extension'], 'typo3', true],
            'not found' => [['typo3', 'extension'], 'missing', false],
            'empty keywords' => [[], 'typo3', false],
            'empty search keyword' => [['typo3'], '', false],
            'mixed case in both' => [['TyPo3'], 'TYPO3', true],
        ];
    }

    #[DataProvider('supportsPhpVersionProvider')]
    public function testSupportsPhpVersion(array $supportedVersions, string $testVersion, bool $expected): void
    {
        $metadata = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            $supportedVersions,
            [],
            $this->testDate,
        );

        self::assertSame($expected, $metadata->supportsPhpVersion($testVersion));
    }

    /**
     * @return array<string, array{array<string>, string, bool}>
     */
    public static function supportsPhpVersionProvider(): array
    {
        return [
            'supported version' => [['8.1', '8.2', '8.3'], '8.2', true],
            'unsupported version' => [['8.1', '8.2'], '8.3', false],
            'empty supported versions' => [[], '8.2', false],
            'exact match' => [['8.2.0'], '8.2.0', true],
            'no exact match' => [['8.2.0'], '8.2', false],
        ];
    }

    #[DataProvider('supportsTypo3VersionProvider')]
    public function testSupportsTypo3Version(array $supportedVersions, string $testVersion, bool $expected): void
    {
        $metadata = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            $supportedVersions,
            $this->testDate,
        );

        self::assertSame($expected, $metadata->supportsTypo3Version($testVersion));
    }

    /**
     * @return array<string, array{array<string>, string, bool}>
     */
    public static function supportsTypo3VersionProvider(): array
    {
        return [
            'supported version' => [['12.4', '13.0'], '12.4', true],
            'unsupported version' => [['12.4'], '13.0', false],
            'empty supported versions' => [[], '12.4', false],
            'exact match' => [['12.4.0'], '12.4.0', true],
            'no exact match' => [['12.4.0'], '12.4', false],
        ];
    }

    public function testGetAdditionalValue(): void
    {
        $additionalData = [
            'source' => 'local',
            'path' => '/var/www/ext',
            'nested' => ['key' => 'value'],
        ];

        $metadata = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
            $additionalData,
        );

        self::assertSame('local', $metadata->getAdditionalValue('source'));
        self::assertSame('/var/www/ext', $metadata->getAdditionalValue('path'));
        self::assertSame(['key' => 'value'], $metadata->getAdditionalValue('nested'));
        self::assertNull($metadata->getAdditionalValue('nonexistent'));
    }

    public function testHasComposerData(): void
    {
        $metadataWithComposer = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
            ['composer_data' => ['name' => 'vendor/package']],
        );

        $metadataWithoutComposer = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
            ['other_data' => 'value'],
        );

        self::assertTrue($metadataWithComposer->hasComposerData());
        self::assertFalse($metadataWithoutComposer->hasComposerData());
    }

    public function testHasEmconfData(): void
    {
        $metadataWithEmconf = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
            ['emconf_data' => ['title' => 'Extension Title']],
        );

        $metadataWithoutEmconf = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
            ['other_data' => 'value'],
        );

        self::assertTrue($metadataWithEmconf->hasEmconfData());
        self::assertFalse($metadataWithoutEmconf->hasEmconfData());
    }

    public function testGetComposerData(): void
    {
        $composerData = ['name' => 'vendor/package', 'type' => 'typo3-cms-extension'];

        $metadataWithComposer = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
            ['composer_data' => $composerData],
        );

        $metadataWithoutComposer = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
        );

        self::assertSame($composerData, $metadataWithComposer->getComposerData());
        self::assertNull($metadataWithoutComposer->getComposerData());
    }

    public function testGetEmconfData(): void
    {
        $emconfData = ['title' => 'Extension Title', 'version' => '1.0.0'];

        $metadataWithEmconf = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
            ['emconf_data' => $emconfData],
        );

        $metadataWithoutEmconf = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
        );

        self::assertSame($emconfData, $metadataWithEmconf->getEmconfData());
        self::assertNull($metadataWithoutEmconf->getEmconfData());
    }

    public function testWithAdditionalData(): void
    {
        $originalData = ['source' => 'local'];
        $metadata = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
            $originalData,
        );

        $additionalData = ['version' => '1.0.0', 'type' => 'extension'];
        $newMetadata = $metadata->withAdditionalData($additionalData);

        // Original should be unchanged
        self::assertSame($originalData, $metadata->getAdditionalData());

        // New should have merged data
        $expectedMerged = ['source' => 'local', 'version' => '1.0.0', 'type' => 'extension'];
        self::assertSame($expectedMerged, $newMetadata->getAdditionalData());

        // Should be different instances
        self::assertNotSame($metadata, $newMetadata);

        // Other properties should be the same
        self::assertSame($metadata->getDescription(), $newMetadata->getDescription());
        self::assertSame($metadata->getAuthor(), $newMetadata->getAuthor());
        self::assertSame($metadata->getLastModified(), $newMetadata->getLastModified());
    }

    public function testWithAdditionalDataOverwritesExistingKeys(): void
    {
        $metadata = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $this->testDate,
            ['source' => 'local', 'version' => '1.0.0'],
        );

        $newMetadata = $metadata->withAdditionalData(['version' => '2.0.0', 'new_key' => 'new_value']);

        $expected = ['source' => 'local', 'version' => '2.0.0', 'new_key' => 'new_value'];
        self::assertSame($expected, $newMetadata->getAdditionalData());
    }

    public function testToArray(): void
    {
        $description = 'Test extension';
        $author = 'John Doe';
        $authorEmail = 'john@example.com';
        $keywords = ['test', 'typo3'];
        $license = 'GPL-2.0-or-later';
        $supportedPhpVersions = ['8.1', '8.2'];
        $supportedTypo3Versions = ['12.4', '13.0'];
        $additionalData = ['source' => 'local'];

        $metadata = new ExtensionMetadata(
            $description,
            $author,
            $authorEmail,
            $keywords,
            $license,
            $supportedPhpVersions,
            $supportedTypo3Versions,
            $this->testDate,
            $additionalData,
        );

        $array = $metadata->toArray();

        $expected = [
            'description' => $description,
            'author' => $author,
            'author_email' => $authorEmail,
            'keywords' => $keywords,
            'license' => $license,
            'supported_php_versions' => $supportedPhpVersions,
            'supported_typo3_versions' => $supportedTypo3Versions,
            'last_modified' => $this->testDate->format(\DateTimeInterface::ATOM),
            'additional_data' => $additionalData,
        ];

        self::assertSame($expected, $array);
    }

    public function testCreateEmpty(): void
    {
        $metadata = ExtensionMetadata::createEmpty();

        self::assertSame('', $metadata->getDescription());
        self::assertSame('', $metadata->getAuthor());
        self::assertSame('', $metadata->getAuthorEmail());
        self::assertSame([], $metadata->getKeywords());
        self::assertSame('', $metadata->getLicense());
        self::assertSame([], $metadata->getSupportedPhpVersions());
        self::assertSame([], $metadata->getSupportedTypo3Versions());
        self::assertSame([], $metadata->getAdditionalData());
    }

    public function testCreateEmptyHasRecentTimestamp(): void
    {
        $before = new \DateTimeImmutable();
        $metadata = ExtensionMetadata::createEmpty();
        $after = new \DateTimeImmutable();

        $timestamp = $metadata->getLastModified();

        self::assertGreaterThanOrEqual($before->getTimestamp(), $timestamp->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $timestamp->getTimestamp());
    }

    public function testImmutability(): void
    {
        $keywords = ['test', 'typo3'];
        $supportedPhpVersions = ['8.1'];
        $supportedTypo3Versions = ['12.4'];
        $additionalData = ['source' => 'local'];

        $metadata = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            $keywords,
            'GPL-2.0',
            $supportedPhpVersions,
            $supportedTypo3Versions,
            $this->testDate,
            $additionalData,
        );

        // Modify original arrays
        $keywords[] = 'modified';
        $supportedPhpVersions[] = '8.2';
        $supportedTypo3Versions[] = '13.0';
        $additionalData['new_key'] = 'new_value';

        // Metadata should be unchanged
        self::assertSame(['test', 'typo3'], $metadata->getKeywords());
        self::assertSame(['8.1'], $metadata->getSupportedPhpVersions());
        self::assertSame(['12.4'], $metadata->getSupportedTypo3Versions());
        self::assertSame(['source' => 'local'], $metadata->getAdditionalData());
    }

    public function testDateTimeImmutability(): void
    {
        $date = new \DateTimeImmutable('2023-12-01 15:30:00');
        $metadata = new ExtensionMetadata(
            'Test',
            'Author',
            'email@test.com',
            [],
            'GPL-2.0',
            [],
            [],
            $date,
        );

        $retrievedDate = $metadata->getLastModified();
        self::assertEquals($date, $retrievedDate);
        self::assertSame($date, $retrievedDate); // Should be the same instance
    }
}
