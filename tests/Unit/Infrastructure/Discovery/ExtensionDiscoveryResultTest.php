<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\SerializableInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryResult;
use PHPUnit\Framework\TestCase;

final class ExtensionDiscoveryResultTest extends TestCase
{
    private Extension $newsExtension;
    private Extension $addressExtension;
    private Extension $localExtension;

    protected function setUp(): void
    {
        $this->newsExtension = new Extension(
            'news',
            'News System',
            Version::fromString('10.0.0'),
            'composer',
            'georgringer/news',
        );
        $this->newsExtension->setActive(true);

        $this->addressExtension = new Extension(
            'tt_address',
            'Address Management',
            Version::fromString('7.1.0'),
            'composer',
            'friendsoftypo3/tt-address',
        );
        $this->addressExtension->setActive(false);

        $this->localExtension = new Extension(
            'local_ext',
            'Local Extension',
            Version::fromString('1.0.0'),
            'local',
            null,
        );
        $this->localExtension->setActive(true);
    }

    public function testImplementsSerializableInterface(): void
    {
        $result = ExtensionDiscoveryResult::success([]);
        $this->assertInstanceOf(SerializableInterface::class, $result);
    }

    public function testSuccessFactoryMethod(): void
    {
        $extensions = [$this->newsExtension, $this->addressExtension];
        $methods = ['PackageStates.php', 'composer installed.json'];
        $metadata = [
            ['method' => 'PackageStates.php', 'successful' => true],
        ];

        $result = ExtensionDiscoveryResult::success($extensions, $methods, $metadata);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('', $result->getErrorMessage());
        $this->assertSame($extensions, $result->getExtensions());
        $this->assertSame($methods, $result->getSuccessfulMethods());
        $this->assertSame($metadata, $result->getDiscoveryMetadata());
    }

    public function testSuccessFactoryMethodWithDefaults(): void
    {
        $extensions = [$this->newsExtension];

        $result = ExtensionDiscoveryResult::success($extensions);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('', $result->getErrorMessage());
        $this->assertSame($extensions, $result->getExtensions());
        $this->assertSame([], $result->getSuccessfulMethods());
        $this->assertSame([], $result->getDiscoveryMetadata());
    }

    public function testFailedFactoryMethod(): void
    {
        $errorMessage = 'Discovery failed due to file not found';
        $metadata = [
            ['method' => 'PackageStates.php', 'successful' => false, 'error' => 'File not found'],
        ];

        $result = ExtensionDiscoveryResult::failed($errorMessage, $metadata);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame($errorMessage, $result->getErrorMessage());
        $this->assertSame([], $result->getExtensions());
        $this->assertSame([], $result->getSuccessfulMethods());
        $this->assertSame($metadata, $result->getDiscoveryMetadata());
    }

    public function testFailedFactoryMethodWithDefaults(): void
    {
        $errorMessage = 'Generic error';

        $result = ExtensionDiscoveryResult::failed($errorMessage);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame($errorMessage, $result->getErrorMessage());
        $this->assertSame([], $result->getExtensions());
        $this->assertSame([], $result->getSuccessfulMethods());
        $this->assertSame([], $result->getDiscoveryMetadata());
    }

    public function testGetExtensionCount(): void
    {
        $result = ExtensionDiscoveryResult::success([$this->newsExtension, $this->addressExtension]);
        $this->assertSame(2, $result->getExtensionCount());

        $emptyResult = ExtensionDiscoveryResult::success([]);
        $this->assertSame(0, $emptyResult->getExtensionCount());
    }

    public function testHasExtensions(): void
    {
        $result = ExtensionDiscoveryResult::success([$this->newsExtension]);
        $this->assertTrue($result->hasExtensions());

        $emptyResult = ExtensionDiscoveryResult::success([]);
        $this->assertFalse($emptyResult->hasExtensions());
    }

    public function testGetExtensionsByType(): void
    {
        $extensions = [$this->newsExtension, $this->addressExtension, $this->localExtension];
        $result = ExtensionDiscoveryResult::success($extensions);

        $composerExtensions = $result->getExtensionsByType('composer');
        $this->assertCount(2, $composerExtensions);
        $this->assertContains($this->newsExtension, $composerExtensions);
        $this->assertContains($this->addressExtension, $composerExtensions);

        $localExtensions = $result->getExtensionsByType('local');
        $this->assertCount(1, $localExtensions);
        $this->assertContains($this->localExtension, $localExtensions);

        $systemExtensions = $result->getExtensionsByType('system');
        $this->assertCount(0, $systemExtensions);
    }

    public function testGetExtensionsByActiveStatus(): void
    {
        $extensions = [$this->newsExtension, $this->addressExtension, $this->localExtension];
        $result = ExtensionDiscoveryResult::success($extensions);

        $activeExtensions = $result->getExtensionsByActiveStatus(true);
        $this->assertCount(2, $activeExtensions);
        $this->assertContains($this->newsExtension, $activeExtensions);
        $this->assertContains($this->localExtension, $activeExtensions);

        $inactiveExtensions = $result->getExtensionsByActiveStatus(false);
        $this->assertCount(1, $inactiveExtensions);
        $this->assertContains($this->addressExtension, $inactiveExtensions);
    }

    public function testGetExtensionByKey(): void
    {
        $extensions = [$this->newsExtension, $this->addressExtension];
        $result = ExtensionDiscoveryResult::success($extensions);

        $foundExtension = $result->getExtensionByKey('news');
        $this->assertSame($this->newsExtension, $foundExtension);

        $foundExtension = $result->getExtensionByKey('tt_address');
        $this->assertSame($this->addressExtension, $foundExtension);

        $notFound = $result->getExtensionByKey('nonexistent');
        $this->assertNull($notFound);
    }

    public function testHasExtension(): void
    {
        $extensions = [$this->newsExtension, $this->addressExtension];
        $result = ExtensionDiscoveryResult::success($extensions);

        $this->assertTrue($result->hasExtension('news'));
        $this->assertTrue($result->hasExtension('tt_address'));
        $this->assertFalse($result->hasExtension('nonexistent'));
    }

    public function testGetExtensionsGroupedByType(): void
    {
        $extensions = [$this->newsExtension, $this->addressExtension, $this->localExtension];
        $result = ExtensionDiscoveryResult::success($extensions);

        $grouped = $result->getExtensionsGroupedByType();

        $this->assertArrayHasKey('composer', $grouped);
        $this->assertArrayHasKey('local', $grouped);
        $this->assertCount(2, $grouped['composer']);
        $this->assertCount(1, $grouped['local']);
        $this->assertContains($this->newsExtension, $grouped['composer']);
        $this->assertContains($this->addressExtension, $grouped['composer']);
        $this->assertContains($this->localExtension, $grouped['local']);
    }

    public function testGetExtensionsGroupedByTypeEmpty(): void
    {
        $result = ExtensionDiscoveryResult::success([]);
        $grouped = $result->getExtensionsGroupedByType();
        $this->assertSame([], $grouped);
    }

    public function testGetStatistics(): void
    {
        $extensions = [$this->newsExtension, $this->addressExtension, $this->localExtension];
        $methods = ['PackageStates.php', 'composer installed.json'];
        $result = ExtensionDiscoveryResult::success($extensions, $methods);

        $stats = $result->getStatistics();

        $this->assertSame(3, $stats['total_extensions']);
        $this->assertSame(2, $stats['active_extensions']);
        $this->assertSame(1, $stats['inactive_extensions']);
        $this->assertSame(['composer' => 2, 'local' => 1], $stats['extensions_by_type']);
        $this->assertSame($methods, $stats['successful_methods']);
        $this->assertSame(2, $stats['discovery_methods_used']);
        $this->assertTrue($stats['successful']);
    }

    public function testGetStatisticsForEmptyResult(): void
    {
        $result = ExtensionDiscoveryResult::success([]);
        $stats = $result->getStatistics();

        $this->assertSame(0, $stats['total_extensions']);
        $this->assertSame(0, $stats['active_extensions']);
        $this->assertSame(0, $stats['inactive_extensions']);
        $this->assertSame([], $stats['extensions_by_type']);
        $this->assertSame([], $stats['successful_methods']);
        $this->assertSame(0, $stats['discovery_methods_used']);
        $this->assertTrue($stats['successful']);
    }

    public function testGetStatisticsForFailedResult(): void
    {
        $result = ExtensionDiscoveryResult::failed('Discovery failed');
        $stats = $result->getStatistics();

        $this->assertSame(0, $stats['total_extensions']);
        $this->assertSame(0, $stats['active_extensions']);
        $this->assertSame(0, $stats['inactive_extensions']);
        $this->assertSame([], $stats['extensions_by_type']);
        $this->assertSame([], $stats['successful_methods']);
        $this->assertSame(0, $stats['discovery_methods_used']);
        $this->assertFalse($stats['successful']);
    }

    public function testGetSummaryForSuccessfulResultWithExtensions(): void
    {
        $extensions = [$this->newsExtension, $this->addressExtension, $this->localExtension];
        $methods = ['PackageStates.php', 'composer installed.json'];
        $result = ExtensionDiscoveryResult::success($extensions, $methods);

        $summary = $result->getSummary();

        $expected = 'Discovered 3 extensions (2 active) using 2 methods (2 composer, 1 local)';
        $this->assertSame($expected, $summary);
    }

    public function testGetSummaryForSuccessfulResultWithOneExtension(): void
    {
        $methods = ['PackageStates.php'];
        $result = ExtensionDiscoveryResult::success([$this->newsExtension], $methods);

        $summary = $result->getSummary();

        $expected = 'Discovered 1 extension (1 active) using 1 method';
        $this->assertSame($expected, $summary);
    }

    public function testGetSummaryForSuccessfulResultWithNoExtensions(): void
    {
        $result = ExtensionDiscoveryResult::success([]);

        $summary = $result->getSummary();

        $this->assertSame('No extensions found in installation', $summary);
    }

    public function testGetSummaryForFailedResult(): void
    {
        $metadata = [
            ['method' => 'PackageStates.php'],
            ['method' => 'composer installed.json'],
        ];
        $result = ExtensionDiscoveryResult::failed('File system error', $metadata);

        $summary = $result->getSummary();

        $expected = 'Extension discovery failed: File system error (attempted 2 methods)';
        $this->assertSame($expected, $summary);
    }

    public function testGetSummaryForFailedResultWithOneMethod(): void
    {
        $metadata = [
            ['method' => 'PackageStates.php'],
        ];
        $result = ExtensionDiscoveryResult::failed('File not found', $metadata);

        $summary = $result->getSummary();

        $expected = 'Extension discovery failed: File not found (attempted 1 method)';
        $this->assertSame($expected, $summary);
    }

    public function testGetSummaryForFailedResultWithNoMetadata(): void
    {
        $result = ExtensionDiscoveryResult::failed('Generic error');

        $summary = $result->getSummary();

        $expected = 'Extension discovery failed: Generic error (attempted 0 methods)';
        $this->assertSame($expected, $summary);
    }

    public function testToArrayForSuccessfulResult(): void
    {
        $extensions = [$this->newsExtension, $this->addressExtension];
        $methods = ['PackageStates.php'];
        $metadata = [['method' => 'PackageStates.php', 'successful' => true]];
        $result = ExtensionDiscoveryResult::success($extensions, $methods, $metadata);

        $array = $result->toArray();

        $this->assertTrue($array['successful']);
        $this->assertSame('', $array['error_message']);
        $this->assertCount(2, $array['extensions']);
        $this->assertSame($methods, $array['successful_methods']);
        $this->assertSame($metadata, $array['discovery_metadata']);
        $this->assertArrayHasKey('statistics', $array);
        $this->assertArrayHasKey('summary', $array);

        // Check extension serialization
        $this->assertSame('news', $array['extensions'][0]['key']);
        $this->assertSame('tt_address', $array['extensions'][1]['key']);
    }

    public function testToArrayForFailedResult(): void
    {
        $errorMessage = 'Discovery failed';
        $metadata = [['method' => 'PackageStates.php', 'successful' => false]];
        $result = ExtensionDiscoveryResult::failed($errorMessage, $metadata);

        $array = $result->toArray();

        $this->assertFalse($array['successful']);
        $this->assertSame($errorMessage, $array['error_message']);
        $this->assertSame([], $array['extensions']);
        $this->assertSame([], $array['successful_methods']);
        $this->assertSame($metadata, $array['discovery_metadata']);
        $this->assertArrayHasKey('statistics', $array);
        $this->assertArrayHasKey('summary', $array);
    }

    public function testFromArrayForSuccessfulResult(): void
    {
        $data = [
            'successful' => true,
            'error_message' => '',
            'extensions' => [
                [
                    'key' => 'news',
                    'title' => 'News System',
                    'version' => '10.0.0',
                    'type' => 'composer',
                    'composer_name' => 'georgringer/news',
                    'is_active' => true,
                    'em_configuration' => ['priority' => 'top'],
                ],
                [
                    'key' => 'tt_address',
                    'title' => 'Address Management',
                    'version' => '7.1.0',
                    'type' => 'composer',
                    'composer_name' => 'friendsoftypo3/tt-address',
                    'is_active' => false,
                    'em_configuration' => [],
                ],
            ],
            'successful_methods' => ['PackageStates.php'],
            'discovery_metadata' => [['method' => 'PackageStates.php', 'successful' => true]],
        ];

        $result = ExtensionDiscoveryResult::fromArray($data);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('', $result->getErrorMessage());
        $this->assertCount(2, $result->getExtensions());
        $this->assertSame(['PackageStates.php'], $result->getSuccessfulMethods());

        $extensions = $result->getExtensions();
        $this->assertSame('news', $extensions[0]->getKey());
        $this->assertSame('News System', $extensions[0]->getTitle());
        $this->assertSame('10.0.0', $extensions[0]->getVersion()->toString());
        $this->assertTrue($extensions[0]->isActive());
        $this->assertSame(['priority' => 'top'], $extensions[0]->getEmConfiguration());

        $this->assertSame('tt_address', $extensions[1]->getKey());
        $this->assertFalse($extensions[1]->isActive());
    }

    public function testFromArrayForFailedResult(): void
    {
        $data = [
            'successful' => false,
            'error_message' => 'Discovery failed',
            'extensions' => [],
            'successful_methods' => [],
            'discovery_metadata' => [['method' => 'PackageStates.php', 'successful' => false]],
        ];

        $result = ExtensionDiscoveryResult::fromArray($data);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('Discovery failed', $result->getErrorMessage());
        $this->assertCount(0, $result->getExtensions());
        $this->assertSame([], $result->getSuccessfulMethods());
    }

    public function testFromArrayWithMissingOptionalFields(): void
    {
        $data = [
            'successful' => true,
            'extensions' => [
                [
                    'key' => 'simple_ext',
                    'title' => 'Simple Extension',
                    'version' => '1.0.0',
                    'type' => 'local',
                    'composer_name' => null,
                    'is_active' => true,
                    // Missing em_configuration
                ],
            ],
            // Missing successful_methods and discovery_metadata
        ];

        $result = ExtensionDiscoveryResult::fromArray($data);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->getExtensions());
        $this->assertSame([], $result->getSuccessfulMethods());
        $this->assertSame([], $result->getDiscoveryMetadata());

        $extension = $result->getExtensions()[0];
        $this->assertSame('simple_ext', $extension->getKey());
        $this->assertSame([], $extension->getEmConfiguration());
    }

    public function testFromArrayForFailedResultWithMissingErrorMessage(): void
    {
        $data = [
            'successful' => false,
            // Missing error_message
        ];

        $result = ExtensionDiscoveryResult::fromArray($data);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('Unknown cached error', $result->getErrorMessage());
    }

    public function testSerializationRoundTrip(): void
    {
        $extensions = [$this->newsExtension, $this->localExtension];
        $methods = ['PackageStates.php', 'composer installed.json'];
        $metadata = [
            ['method' => 'PackageStates.php', 'successful' => true, 'extensions_found' => 1],
            ['method' => 'composer installed.json', 'successful' => true, 'extensions_found' => 1],
        ];

        $original = ExtensionDiscoveryResult::success($extensions, $methods, $metadata);
        $serialized = $original->toArray();
        $deserialized = ExtensionDiscoveryResult::fromArray($serialized);

        // Compare basic properties
        $this->assertSame($original->isSuccessful(), $deserialized->isSuccessful());
        $this->assertSame($original->getErrorMessage(), $deserialized->getErrorMessage());
        $this->assertSame($original->getSuccessfulMethods(), $deserialized->getSuccessfulMethods());
        $this->assertSame($original->getDiscoveryMetadata(), $deserialized->getDiscoveryMetadata());
        $this->assertSame($original->getExtensionCount(), $deserialized->getExtensionCount());

        // Compare extensions
        $originalExtensions = $original->getExtensions();
        $deserializedExtensions = $deserialized->getExtensions();

        $this->assertCount(\count($originalExtensions), $deserializedExtensions);

        for ($i = 0; $i < \count($originalExtensions); ++$i) {
            $origExt = $originalExtensions[$i];
            $deserExt = $deserializedExtensions[$i];

            $this->assertSame($origExt->getKey(), $deserExt->getKey());
            $this->assertSame($origExt->getTitle(), $deserExt->getTitle());
            $this->assertSame($origExt->getVersion()->toString(), $deserExt->getVersion()->toString());
            $this->assertSame($origExt->getType(), $deserExt->getType());
            $this->assertSame($origExt->getComposerName(), $deserExt->getComposerName());
            $this->assertSame($origExt->isActive(), $deserExt->isActive());
        }
    }

    public function testReadonlyBehavior(): void
    {
        $extensions = [$this->newsExtension];
        $result = ExtensionDiscoveryResult::success($extensions);

        // Verify that the result is truly readonly by checking that arrays are copies
        $retrievedExtensions = $result->getExtensions();
        $retrievedExtensions[] = $this->addressExtension;

        // Original result should not be affected
        $this->assertCount(1, $result->getExtensions());
        $this->assertNotContains($this->addressExtension, $result->getExtensions());
    }

    public function testComplexScenarioWithMixedExtensions(): void
    {
        // Create system extension
        $systemExt = new Extension('backend', 'TYPO3 Backend', Version::fromString('12.4.0'), 'system', null);
        $systemExt->setActive(true);

        // Create composer extension with em_configuration
        $composerExt = new Extension('powermail', 'Powermail', Version::fromString('8.5.0'), 'composer', 'in2code/powermail');
        $composerExt->setActive(true);
        $composerExt->setEmConfiguration(['priority' => 'top', 'state' => 'stable']);

        $extensions = [$systemExt, $composerExt, $this->localExtension, $this->addressExtension];
        $methods = ['PackageStates.php', 'composer installed.json'];
        $metadata = [
            ['method' => 'PackageStates.php', 'successful' => true, 'extensions_found' => 3],
            ['method' => 'composer installed.json', 'successful' => true, 'extensions_found' => 2],
        ];

        $result = ExtensionDiscoveryResult::success($extensions, $methods, $metadata);

        // Test type filtering
        $this->assertCount(2, $result->getExtensionsByType('composer'));
        $this->assertCount(1, $result->getExtensionsByType('system'));
        $this->assertCount(1, $result->getExtensionsByType('local'));

        // Test active status filtering
        $this->assertCount(3, $result->getExtensionsByActiveStatus(true));
        $this->assertCount(1, $result->getExtensionsByActiveStatus(false));

        // Test grouping
        $grouped = $result->getExtensionsGroupedByType();
        $this->assertCount(3, $grouped); // 3 different types

        // Test statistics
        $stats = $result->getStatistics();
        $this->assertSame(4, $stats['total_extensions']);
        $this->assertSame(3, $stats['active_extensions']);
        $this->assertSame(1, $stats['inactive_extensions']);
        $this->assertSame(['system' => 1, 'composer' => 2, 'local' => 1], $stats['extensions_by_type']);

        // Test summary
        $summary = $result->getSummary();
        $this->assertStringContainsString('4 extensions', $summary);
        $this->assertStringContainsString('3 active', $summary);
        $this->assertStringContainsString('2 methods', $summary);
        $this->assertStringContainsString('1 system, 2 composer, 1 local', $summary);
    }
}
