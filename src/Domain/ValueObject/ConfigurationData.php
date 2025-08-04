<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Domain\ValueObject;

/**
 * Immutable value object representing parsed configuration data.
 *
 * Wraps configuration data with metadata and provides type-safe access
 * to configuration values with support for nested structures and validation.
 */
final class ConfigurationData
{
    /**
     * @param array<string, mixed>    $data               Configuration data
     * @param string                  $format             Configuration format (php, yaml, etc.)
     * @param string                  $source             Source identifier (file path, etc.)
     * @param array<string>           $validationErrors   Validation errors found in data
     * @param array<string>           $validationWarnings Validation warnings found in data
     * @param \DateTimeImmutable|null $loadedAt           When configuration was loaded
     * @param array<string, mixed>    $metadata           Additional metadata about the configuration
     */
    private readonly \DateTimeImmutable $loadedAt;

    public function __construct(
        private readonly array $data,
        private readonly string $format,
        private readonly string $source,
        private readonly array $validationErrors = [],
        private readonly array $validationWarnings = [],
        ?\DateTimeImmutable $loadedAt = null,
        private readonly array $metadata = [],
    ) {
        $this->loadedAt = $loadedAt ?? new \DateTimeImmutable();
    }

    /**
     * Get configuration data.
     *
     * @return array<string, mixed> Configuration data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get configuration format.
     *
     * @return string Format identifier
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Get configuration source.
     *
     * @return string Source identifier
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Get validation errors.
     *
     * @return array<string> Validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get validation warnings.
     *
     * @return array<string> Validation warnings
     */
    public function getValidationWarnings(): array
    {
        return $this->validationWarnings;
    }

    /**
     * Get loaded timestamp.
     *
     * @return \DateTimeImmutable When configuration was loaded
     */
    public function getLoadedAt(): \DateTimeImmutable
    {
        return $this->loadedAt;
    }

    /**
     * Get metadata.
     *
     * @return array<string, mixed> Configuration metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check if configuration has validation errors.
     *
     * @return bool True if validation errors exist
     */
    public function hasValidationErrors(): bool
    {
        return !empty($this->validationErrors);
    }

    /**
     * Check if configuration has validation warnings.
     *
     * @return bool True if validation warnings exist
     */
    public function hasValidationWarnings(): bool
    {
        return !empty($this->validationWarnings);
    }

    /**
     * Check if configuration is valid (no errors).
     *
     * @return bool True if no validation errors
     */
    public function isValid(): bool
    {
        return empty($this->validationErrors);
    }

    /**
     * Get configuration value by key path.
     *
     * Supports dot notation for nested values (e.g., 'database.connections.default.host')
     * Special handling for keys that contain literal dots.
     *
     * @param string $keyPath Key path using dot notation
     * @param mixed  $default Default value if key not found
     *
     * @return mixed Configuration value or default
     */
    public function getValue(string $keyPath, $default = null)
    {
        // Check if the key exists as a literal key first (for keys containing dots)
        if (\array_key_exists($keyPath, $this->data)) {
            return $this->data[$keyPath];
        }

        $keys = explode('.', $keyPath);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Check if configuration has specific key path.
     *
     * @param string $keyPath Key path using dot notation
     *
     * @return bool True if key exists
     */
    public function hasValue(string $keyPath): bool
    {
        // Check if the key exists as a literal key first (for keys containing dots)
        if (\array_key_exists($keyPath, $this->data)) {
            return true;
        }

        $keys = explode('.', $keyPath);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }

        return true;
    }

    /**
     * Get string value with type checking.
     *
     * @param string $keyPath Key path
     * @param string $default Default value
     *
     * @return string String value or default
     */
    public function getString(string $keyPath, string $default = ''): string
    {
        $value = $this->getValue($keyPath, $default);

        return \is_string($value) ? $value : $default;
    }

    /**
     * Get integer value with type checking.
     *
     * @param string $keyPath Key path
     * @param int    $default Default value
     *
     * @return int Integer value or default
     */
    public function getInt(string $keyPath, int $default = 0): int
    {
        $value = $this->getValue($keyPath, $default);

        return \is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    /**
     * Get boolean value with type checking.
     *
     * @param string $keyPath Key path
     * @param bool   $default Default value
     *
     * @return bool Boolean value or default
     */
    public function getBool(string $keyPath, bool $default = false): bool
    {
        $value = $this->getValue($keyPath, $default);

        if (\is_bool($value)) {
            return $value;
        }

        // Handle string boolean representations
        if (\is_string($value)) {
            $lower = strtolower($value);
            if (\in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (\in_array($lower, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * Get array value with type checking.
     *
     * @param string       $keyPath Key path
     * @param array<mixed> $default Default value
     *
     * @return array<mixed> Array value or default
     */
    public function getArray(string $keyPath, array $default = []): array
    {
        $value = $this->getValue($keyPath, $default);

        return \is_array($value) ? $value : $default;
    }

    /**
     * Get metadata value by key.
     *
     * @param string $key     Metadata key
     * @param mixed  $default Default value
     *
     * @return mixed Metadata value or default
     */
    public function getMetadataValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Get all top-level configuration keys.
     *
     * @return array<string> Configuration keys
     */
    public function getKeys(): array
    {
        return array_keys($this->data);
    }

    /**
     * Get configuration section by key.
     *
     * @param string $sectionKey Section key
     *
     * @throws \InvalidArgumentException If section doesn't exist or isn't an array
     *
     * @return self New ConfigurationData instance for the section
     */
    public function getSection(string $sectionKey): self
    {
        if (!$this->hasValue($sectionKey)) {
            throw new \InvalidArgumentException("Configuration section '{$sectionKey}' does not exist");
        }

        $sectionData = $this->getValue($sectionKey);
        if (!\is_array($sectionData)) {
            throw new \InvalidArgumentException("Configuration section '{$sectionKey}' is not an array");
        }

        return new self(
            $sectionData,
            $this->format,
            $this->source . '.' . $sectionKey,
            $this->validationErrors,
            $this->validationWarnings,
            $this->loadedAt,
            array_merge($this->metadata, ['section' => $sectionKey]),
        );
    }

    /**
     * Check if configuration is empty.
     *
     * @return bool True if no configuration data
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * Count configuration entries.
     *
     * @return int Number of top-level configuration entries
     */
    public function count(): int
    {
        return \count($this->data);
    }

    /**
     * Create new instance with additional metadata.
     *
     * @param array<string, mixed> $additionalMetadata Additional metadata
     *
     * @return self New instance with merged metadata
     */
    public function withMetadata(array $additionalMetadata): self
    {
        return new self(
            $this->data,
            $this->format,
            $this->source,
            $this->validationErrors,
            $this->validationWarnings,
            $this->loadedAt,
            array_merge($this->metadata, $additionalMetadata),
        );
    }

    /**
     * Create new instance with validation issues.
     *
     * @param array<string> $errors   Validation errors
     * @param array<string> $warnings Validation warnings
     *
     * @return self New instance with validation issues
     */
    public function withValidation(array $errors, array $warnings = []): self
    {
        return new self(
            $this->data,
            $this->format,
            $this->source,
            array_merge($this->validationErrors, $errors),
            array_merge($this->validationWarnings, $warnings),
            $this->loadedAt,
            $this->metadata,
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed> Array representation
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'format' => $this->format,
            'source' => $this->source,
            'validation_errors' => $this->validationErrors,
            'validation_warnings' => $this->validationWarnings,
            'loaded_at' => $this->getLoadedAt()->format(\DateTimeInterface::ATOM),
            'metadata' => $this->metadata,
            'statistics' => [
                'key_count' => \count($this->data),
                'is_empty' => $this->isEmpty(),
                'is_valid' => $this->isValid(),
                'has_warnings' => $this->hasValidationWarnings(),
                'has_nested_data' => $this->hasNestedData(),
            ],
        ];
    }

    /**
     * Check if data contains nested arrays.
     *
     * @return bool True if nested arrays are present
     */
    private function hasNestedData(): bool
    {
        foreach ($this->data as $value) {
            if (\is_array($value)) {
                return true;
            }
        }

        return false;
    }
}
