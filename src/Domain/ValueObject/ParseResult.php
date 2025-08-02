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

use DateTimeImmutable;

/**
 * Immutable result of configuration parsing operation
 *
 * Contains parsed configuration data, metadata about the parsing process,
 * and detailed error information if parsing failed.
 */
final readonly class ParseResult
{
    /**
     * @param bool $isSuccessful Whether parsing was successful
     * @param array<string, mixed> $data Parsed configuration data
     * @param string $format Configuration format that was parsed
     * @param string $sourcePath Source file path or identifier
     * @param array<string> $errors Error messages if parsing failed
     * @param array<string> $warnings Non-fatal warnings during parsing
     * @param array<string, mixed> $metadata Additional parsing metadata
     * @param \DateTimeImmutable $parsedAt When parsing was performed
     */
    private function __construct(
        private bool               $isSuccessful,
        private array              $data,
        private string             $format,
        private string             $sourcePath,
        private array              $errors,
        private array              $warnings,
        private array              $metadata,
        private DateTimeImmutable $parsedAt
    ) {
    }

    /**
     * Create successful parse result
     *
     * @param array<string, mixed> $data Parsed configuration data
     * @param string $format Configuration format
     * @param string $sourcePath Source file path
     * @param array<string> $warnings Optional warnings
     * @param array<string, mixed> $metadata Optional metadata
     * @return self Successful result
     */
    public static function success(
        array $data,
        string $format,
        string $sourcePath,
        array $warnings = [],
        array $metadata = []
    ): self {
        return new self(
            true,
            $data,
            $format,
            $sourcePath,
            [],
            $warnings,
            $metadata,
            new DateTimeImmutable()
        );
    }

    /**
     * Create failed parse result
     *
     * @param array<string> $errors Error messages
     * @param string $format Configuration format that failed
     * @param string $sourcePath Source file path
     * @param array<string> $warnings Optional warnings
     * @param array<string, mixed> $metadata Optional metadata
     * @return self Failed result
     */
    public static function failure(
        array $errors,
        string $format,
        string $sourcePath,
        array $warnings = [],
        array $metadata = []
    ): self {
        return new self(
            false,
            [],
            $format,
            $sourcePath,
            $errors,
            $warnings,
            $metadata,
            new DateTimeImmutable()
        );
    }

    /**
     * Check if parsing was successful
     *
     * @return bool True if parsing succeeded
     */
    public function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }

    /**
     * Get parsed configuration data
     *
     * @return array<string, mixed> Configuration data (empty if parsing failed)
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get configuration format
     *
     * @return string Format identifier
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Get source path
     *
     * @return string Source file path or identifier
     */
    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    /**
     * Get error messages
     *
     * @return array<string> Error messages (empty if successful)
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get warning messages
     *
     * @return array<string> Warning messages
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Get parsing metadata
     *
     * @return array<string, mixed> Metadata about parsing process
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get parsing timestamp
     *
     * @return \DateTimeImmutable When parsing was performed
     */
    public function getParsedAt(): DateTimeImmutable
    {
        return $this->parsedAt;
    }

    /**
     * Check if result has errors
     *
     * @return bool True if errors are present
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Check if result has warnings
     *
     * @return bool True if warnings are present
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Get specific configuration value by key path
     *
     * Supports dot notation for nested values (e.g., 'database.connections.default.host')
     *
     * @param string $keyPath Key path using dot notation
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value or default
     */
    public function getValue(string $keyPath, $default = null)
    {
        if (!$this->isSuccessful) {
            return $default;
        }

        $keys = explode('.', $keyPath);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Check if configuration has specific key path
     *
     * @param string $keyPath Key path using dot notation
     * @return bool True if key exists
     */
    public function hasValue(string $keyPath): bool
    {
        if (!$this->isSuccessful) {
            return false;
        }

        $keys = explode('.', $keyPath);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }

        return true;
    }

    /**
     * Get metadata value by key
     *
     * @param string $key Metadata key
     * @param mixed $default Default value
     * @return mixed Metadata value or default
     */
    public function getMetadataValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Get first error message
     *
     * @return string|null First error message or null if no errors
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * Get first warning message
     *
     * @return string|null First warning message or null if no warnings
     */
    public function getFirstWarning(): ?string
    {
        return $this->warnings[0] ?? null;
    }

    /**
     * Create summary string of parse result
     *
     * @return string Human-readable summary
     */
    public function getSummary(): string
    {
        if ($this->isSuccessful) {
            $summary = sprintf(
                '%s configuration parsed successfully from %s',
                ucfirst($this->format),
                basename($this->sourcePath)
            );

            if (!empty($this->warnings)) {
                $summary .= sprintf(' (%d warning%s)', count($this->warnings), count($this->warnings) === 1 ? '' : 's');
            }

            return $summary;
        }

        $errorCount = count($this->errors);
        return sprintf(
            '%s configuration parsing failed: %s (%d error%s)',
            ucfirst($this->format),
            $this->getFirstError() ?? 'Unknown error',
            $errorCount,
            $errorCount === 1 ? '' : 's'
        );
    }

    /**
     * Convert result to array for serialization
     *
     * @return array<string, mixed> Array representation
     */
    public function toArray(): array
    {
        return [
            'successful' => $this->isSuccessful,
            'data' => $this->data,
            'format' => $this->format,
            'source_path' => $this->sourcePath,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
            'parsed_at' => $this->parsedAt->format(\DateTimeInterface::ATOM),
            'summary' => $this->getSummary(),
            'statistics' => [
                'error_count' => count($this->errors),
                'warning_count' => count($this->warnings),
                'data_keys' => $this->isSuccessful ? count($this->data) : 0,
                'has_nested_data' => $this->hasNestedData(),
            ],
        ];
    }

    /**
     * Check if data contains nested arrays
     *
     * @return bool True if data has nested structure
     */
    private function hasNestedData(): bool
    {
        if (!$this->isSuccessful || empty($this->data)) {
            return false;
        }

        foreach ($this->data as $value) {
            if (is_array($value)) {
                return true;
            }
        }

        return false;
    }
}
