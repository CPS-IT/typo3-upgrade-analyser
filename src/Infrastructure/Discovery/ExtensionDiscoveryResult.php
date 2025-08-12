<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\SerializableInterface;

/**
 * Result of extension discovery operation.
 *
 * Contains the discovered extensions, metadata about the discovery process,
 * and information about which discovery methods were used.
 */
final readonly class ExtensionDiscoveryResult implements SerializableInterface
{
    /**
     * @param array<Extension>            $extensions        Discovered extensions
     * @param bool                        $isSuccessful      Whether discovery was successful
     * @param string                      $errorMessage      Error message if discovery failed
     * @param array<string>               $successfulMethods Methods that succeeded in finding extensions
     * @param array<array<string, mixed>> $discoveryMetadata Information about discovery process
     */
    private function __construct(
        private readonly array $extensions,
        private readonly bool $isSuccessful,
        private readonly string $errorMessage,
        private readonly array $successfulMethods,
        private readonly array $discoveryMetadata,
    ) {
    }

    /**
     * Create a successful extension discovery result.
     *
     * @param array<Extension>            $extensions        Discovered extensions
     * @param array<string>               $successfulMethods Methods that succeeded in finding extensions
     * @param array<array<string, mixed>> $discoveryMetadata Information about discovery process
     *
     * @return self Successful result
     */
    public static function success(
        array $extensions,
        array $successfulMethods = [],
        array $discoveryMetadata = [],
    ): self {
        return new self($extensions, true, '', $successfulMethods, $discoveryMetadata);
    }

    /**
     * Create a failed extension discovery result.
     *
     * @param string                      $errorMessage      Error message describing the failure
     * @param array<array<string, mixed>> $discoveryMetadata Information about discovery process
     *
     * @return self Failed result
     */
    public static function failed(string $errorMessage, array $discoveryMetadata = []): self
    {
        return new self([], false, $errorMessage, [], $discoveryMetadata);
    }

    /**
     * Get the discovered extensions.
     *
     * @return array<Extension> Array of discovered extensions
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Check if extension discovery was successful.
     *
     * @return bool True if extensions were discovered successfully
     */
    public function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }

    /**
     * Get error message if discovery failed.
     *
     * @return string Error message (empty string if successful)
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Get the methods that successfully discovered extensions.
     *
     * @return array<string> Array of successful method names
     */
    public function getSuccessfulMethods(): array
    {
        return $this->successfulMethods;
    }

    /**
     * Get metadata about the discovery process.
     *
     * @return array<array<string, mixed>> Discovery metadata
     */
    public function getDiscoveryMetadata(): array
    {
        return $this->discoveryMetadata;
    }

    /**
     * Get total number of discovered extensions.
     *
     * @return int Extension count
     */
    public function getExtensionCount(): int
    {
        return \count($this->extensions);
    }

    /**
     * Check if any extensions were discovered.
     *
     * @return bool True if extensions were found
     */
    public function hasExtensions(): bool
    {
        return !empty($this->extensions);
    }

    /**
     * Get extensions filtered by type.
     *
     * @param string $type Extension type to filter by
     *
     * @return array<Extension> Extensions of specified type
     */
    public function getExtensionsByType(string $type): array
    {
        return array_filter($this->extensions, fn (Extension $ext): bool => $ext->getType() === $type);
    }

    /**
     * Get extensions filtered by active status.
     *
     * @param bool $active Active status to filter by
     *
     * @return array<Extension> Extensions with specified active status
     */
    public function getExtensionsByActiveStatus(bool $active = true): array
    {
        return array_filter($this->extensions, fn (Extension $ext): bool => $ext->isActive() === $active);
    }

    /**
     * Get extension by key.
     *
     * @param string $key Extension key to search for
     *
     * @return Extension|null Extension if found, null otherwise
     */
    public function getExtensionByKey(string $key): ?Extension
    {
        foreach ($this->extensions as $extension) {
            if ($extension->getKey() === $key) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * Check if extension with given key exists.
     *
     * @param string $key Extension key to check
     *
     * @return bool True if extension exists
     */
    public function hasExtension(string $key): bool
    {
        return null !== $this->getExtensionByKey($key);
    }

    /**
     * Get extensions grouped by type.
     *
     * @return array<string, array<Extension>> Extensions grouped by type
     */
    public function getExtensionsGroupedByType(): array
    {
        $grouped = [];

        foreach ($this->extensions as $extension) {
            $type = $extension->getType();
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $extension;
        }

        return $grouped;
    }

    /**
     * Get discovery statistics.
     *
     * @return array<string, mixed> Discovery statistics
     */
    public function getStatistics(): array
    {
        $typeGroups = $this->getExtensionsGroupedByType();
        $typeCounts = array_map('count', $typeGroups);

        $activeExtensions = $this->getExtensionsByActiveStatus(true);
        $inactiveExtensions = $this->getExtensionsByActiveStatus(false);

        return [
            'total_extensions' => $this->getExtensionCount(),
            'active_extensions' => \count($activeExtensions),
            'inactive_extensions' => \count($inactiveExtensions),
            'extensions_by_type' => $typeCounts,
            'successful_methods' => $this->successfulMethods,
            'discovery_methods_used' => \count($this->successfulMethods),
            'successful' => $this->isSuccessful,
        ];
    }

    /**
     * Get a human-readable summary of the discovery result.
     *
     * @return string Summary string
     */
    public function getSummary(): string
    {
        if (!$this->isSuccessful) {
            $methodsCount = \count($this->discoveryMetadata);

            return \sprintf(
                'Extension discovery failed: %s (attempted %d method%s)',
                $this->errorMessage,
                $methodsCount,
                1 === $methodsCount ? '' : 's',
            );
        }

        $totalCount = $this->getExtensionCount();
        $activeCount = \count($this->getExtensionsByActiveStatus(true));
        $methodsUsed = \count($this->successfulMethods);

        if (0 === $totalCount) {
            return 'No extensions found in installation';
        }

        $summary = \sprintf(
            'Discovered %d extension%s (%d active) using %d method%s',
            $totalCount,
            1 === $totalCount ? '' : 's',
            $activeCount,
            $methodsUsed,
            1 === $methodsUsed ? '' : 's',
        );

        $typeGroups = $this->getExtensionsGroupedByType();
        if (\count($typeGroups) > 1) {
            $typeCounts = [];
            foreach ($typeGroups as $type => $extensions) {
                $typeCounts[] = \sprintf('%d %s', \count($extensions), $type);
            }
            $summary .= \sprintf(' (%s)', implode(', ', $typeCounts));
        }

        return $summary;
    }

    /**
     * Convert result to array for serialization.
     *
     * @return array<string, mixed> Array representation
     */
    public function toArray(): array
    {
        return [
            'successful' => $this->isSuccessful,
            'error_message' => $this->errorMessage,
            'extensions' => array_map(fn (Extension $ext): array => $ext->toArray(), $this->extensions),
            'successful_methods' => $this->successfulMethods,
            'discovery_metadata' => $this->discoveryMetadata,
            'statistics' => $this->getStatistics(),
            'summary' => $this->getSummary(),
        ];
    }

    /**
     * Create result from array data.
     *
     * @param array<string, mixed> $data Array representation to deserialize from
     *
     * @return static Deserialized result instance
     */
    public static function fromArray(array $data): static
    {
        if ($data['successful']) {
            // Reconstruct extensions from cached data
            $extensions = [];
            foreach ($data['extensions'] as $extensionData) {
                $extension = new Extension(
                    $extensionData['key'],
                    $extensionData['title'],
                    Version::fromString($extensionData['version']),
                    $extensionData['type'],
                    $extensionData['composer_name'],
                );
                $extension->setActive($extensionData['is_active']);
                $extension->setEmConfiguration($extensionData['em_configuration'] ?? []);
                $extensions[] = $extension;
            }

            return new self(
                $extensions,
                true,
                '',
                $data['successful_methods'] ?? [],
                $data['discovery_metadata'] ?? [],
            );
        } else {
            return new self(
                [],
                false,
                $data['error_message'] ?? 'Unknown cached error',
                [],
                $data['discovery_metadata'] ?? [],
            );
        }
    }
}
