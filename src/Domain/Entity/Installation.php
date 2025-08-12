<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Domain\Entity;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationData;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationMetadata;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMetadata;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\SerializableInterface;

/**
 * Represents a TYPO3 installation to be analyzed.
 */
final class Installation implements SerializableInterface
{
    private array $extensions = [];
    private array $configuration = [];
    private ?InstallationMode $mode = null;
    private ?InstallationMetadata $metadata = null;
    private bool $isValid = true;
    private array $validationErrors = [];

    /** @var array<string, ConfigurationData> */
    private array $configurationData = [];

    /** @var array<string, ConfigurationMetadata> */
    private array $configurationMetadata = [];

    public function __construct(
        private readonly string $path,
        private readonly Version $version,
        private readonly string $type = 'composer',
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getVersion(): Version
    {
        return $this->version;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function addExtension(Extension $extension): void
    {
        $this->extensions[$extension->getKey()] = $extension;
    }

    public function getExtension(string $key): ?Extension
    {
        return $this->extensions[$key] ?? null;
    }

    public function hasExtension(string $key): bool
    {
        return isset($this->extensions[$key]);
    }

    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getConfigurationValue(string $path, mixed $default = null): mixed
    {
        $keys = explode('.', $path);
        $value = $this->configuration;

        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public function isComposerMode(): bool
    {
        return 'composer' === $this->type;
    }

    public function isLegacyMode(): bool
    {
        return 'legacy' === $this->type;
    }

    // New discovery system methods

    public function setMode(InstallationMode $mode): void
    {
        $this->mode = $mode;
    }

    public function getMode(): ?InstallationMode
    {
        return $this->mode;
    }

    public function setMetadata(InstallationMetadata $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getMetadata(): ?InstallationMetadata
    {
        return $this->metadata;
    }

    public function getSystemExtensions(): array
    {
        return array_filter($this->extensions, fn (Extension $ext): bool => 'system' === $ext->getType());
    }

    public function getLocalExtensions(): array
    {
        return array_filter($this->extensions, fn (Extension $ext): bool => 'local' === $ext->getType());
    }

    public function getComposerExtensions(): array
    {
        return array_filter($this->extensions, fn (Extension $ext): bool => $ext->hasComposerName());
    }

    public function isMixedMode(): bool
    {
        // Mixed mode would combine local and composer extensions
        return $this->hasLocalExtensions() && $this->hasComposerExtensions();
    }

    private function hasLocalExtensions(): bool
    {
        return \count($this->getLocalExtensions()) > 0;
    }

    private function hasComposerExtensions(): bool
    {
        return \count($this->getComposerExtensions()) > 0;
    }

    public function markAsInvalid(string $error): void
    {
        $this->isValid = false;
        $this->validationErrors[] = $error;
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function clearValidationErrors(): void
    {
        $this->isValid = true;
        $this->validationErrors = [];
    }

    public function addValidationError(string $error): void
    {
        $this->validationErrors[] = $error;
        if ($this->isValid) {
            $this->isValid = false;
        }
    }

    public function toArray(bool $includeExtensions = true): array
    {
        $data = [
            'path' => $this->path,
            'version' => $this->version->toString(),
            'type' => $this->type,
            'mode' => $this->mode?->value,
            'configuration' => $this->configuration,
            'metadata' => $this->metadata?->toArray(),
            'is_valid' => $this->isValid,
            'validation_errors' => $this->validationErrors,
        ];

        // Extensions are only included when explicitly requested
        // In discovery context, extensions are managed separately by ExtensionDiscoveryService
        if ($includeExtensions) {
            $data['extensions'] = array_map(fn ($ext) => $ext->toArray(), $this->extensions);
        }

        return $data;
    }

    /**
     * Create Installation instance from array data.
     *
     * @param array<string, mixed> $data Array representation to deserialize from
     *
     * @return static Deserialized Installation instance
     */
    public static function fromArray(array $data): static
    {
        $installation = new static(
            $data['path'],
            Version::fromString($data['version']),
            $data['type'] ?? 'composer',
        );

        if (isset($data['mode'])) {
            $installation->setMode(InstallationMode::from($data['mode']));
        }

        if (isset($data['metadata'])) {
            $metadataArray = $data['metadata'];
            $metadata = new InstallationMetadata(
                $metadataArray['php_versions'] ?? [],
                $metadataArray['database_config'] ?? [],
                $metadataArray['enabled_features'] ?? [],
                isset($metadataArray['last_modified']) ?
                    new \DateTimeImmutable($metadataArray['last_modified']) :
                    new \DateTimeImmutable(),
                $metadataArray['custom_paths'] ?? [],
                $metadataArray['discovery_data'] ?? [],
            );
            $installation->setMetadata($metadata);
        }

        if (isset($data['configuration'])) {
            $installation->setConfiguration($data['configuration']);
        }

        if (isset($data['validation_errors'])) {
            $installation->setValidationErrors($data['validation_errors']);
        }

        // Note: Extensions are not reconstructed from serialized data in discovery context
        // Extensions are managed separately by ExtensionDiscoveryService

        return $installation;
    }

    public function setValidationErrors(array $errors): void
    {
        $this->validationErrors = $errors;
        $this->isValid = empty($errors);
    }

    // Configuration management methods

    /**
     * Add parsed configuration data.
     *
     * @param string            $identifier Configuration identifier (e.g., 'LocalConfiguration', 'Services')
     * @param ConfigurationData $configData Parsed configuration data
     */
    public function addConfigurationData(string $identifier, ConfigurationData $configData): void
    {
        $this->configurationData[$identifier] = $configData;
    }

    /**
     * Add configuration metadata.
     *
     * @param string                $identifier Configuration identifier
     * @param ConfigurationMetadata $metadata   Configuration metadata
     */
    public function addConfigurationMetadata(string $identifier, ConfigurationMetadata $metadata): void
    {
        $this->configurationMetadata[$identifier] = $metadata;
    }

    /**
     * Get configuration data by identifier.
     *
     * @param string $identifier Configuration identifier
     *
     * @return ConfigurationData|null Configuration data or null if not found
     */
    public function getConfigurationData(string $identifier): ?ConfigurationData
    {
        return $this->configurationData[$identifier] ?? null;
    }

    /**
     * Get configuration metadata by identifier.
     *
     * @param string $identifier Configuration identifier
     *
     * @return ConfigurationMetadata|null Configuration metadata or null if not found
     */
    public function getConfigurationMetadata(string $identifier): ?ConfigurationMetadata
    {
        return $this->configurationMetadata[$identifier] ?? null;
    }

    /**
     * Get all configuration data.
     *
     * @return array<string, ConfigurationData> All configuration data
     */
    public function getAllConfigurationData(): array
    {
        return $this->configurationData;
    }

    /**
     * Get all configuration metadata.
     *
     * @return array<string, ConfigurationMetadata> All configuration metadata
     */
    public function getAllConfigurationMetadata(): array
    {
        return $this->configurationMetadata;
    }

    /**
     * Check if configuration exists.
     *
     * @param string $identifier Configuration identifier
     *
     * @return bool True if configuration exists
     */
    public function hasConfiguration(string $identifier): bool
    {
        return isset($this->configurationData[$identifier]);
    }

    /**
     * Get configuration value from any configuration file.
     *
     * @param string $identifier Configuration identifier
     * @param string $keyPath    Key path using dot notation
     * @param mixed  $default    Default value
     *
     * @return mixed Configuration value or default
     */
    public function getConfigValue(string $identifier, string $keyPath, mixed $default = null): mixed
    {
        $configData = $this->getConfigurationData($identifier);

        return $configData?->getValue($keyPath, $default) ?? $default;
    }

    /**
     * Get database configuration from LocalConfiguration.
     *
     * @return array<string, mixed> Database configuration
     */
    public function getDatabaseConfiguration(): array
    {
        return $this->getConfigValue('LocalConfiguration', 'DB', []);
    }

    /**
     * Get system configuration from LocalConfiguration.
     *
     * @return array<string, mixed> System configuration
     */
    public function getSystemConfiguration(): array
    {
        return $this->getConfigValue('LocalConfiguration', 'SYS', []);
    }

    /**
     * Get mail configuration from LocalConfiguration.
     *
     * @return array<string, mixed> Mail configuration
     */
    public function getMailConfiguration(): array
    {
        return $this->getConfigValue('LocalConfiguration', 'MAIL', []);
    }

    /**
     * Check if configuration has validation errors.
     *
     * @return bool True if any configuration has validation errors
     */
    public function hasConfigurationErrors(): bool
    {
        foreach ($this->configurationData as $configData) {
            if ($configData->hasValidationErrors()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all configuration validation errors.
     *
     * @return array<string, array<string>> Configuration errors grouped by identifier
     */
    public function getConfigurationErrors(): array
    {
        $errors = [];
        foreach ($this->configurationData as $identifier => $configData) {
            if ($configData->hasValidationErrors()) {
                $errors[$identifier] = $configData->getValidationErrors();
            }
        }

        return $errors;
    }

    /**
     * Get configuration summary for analysis.
     *
     * @return array<string, mixed> Configuration summary
     */
    public function getConfigurationSummary(): array
    {
        return [
            'total_configurations' => \count($this->configurationData),
            'configurations' => array_keys($this->configurationData),
            'has_errors' => $this->hasConfigurationErrors(),
            'error_count' => array_sum(array_map(
                fn (ConfigurationData $config): int => \count($config->getValidationErrors()),
                $this->configurationData,
            )),
            'file_sizes' => array_map(
                fn (ConfigurationMetadata $meta): int => $meta->getFileSize(),
                $this->configurationMetadata,
            ),
            'last_modified' => array_map(
                fn (ConfigurationMetadata $meta): string => $meta->getLastModified()->format(\DateTimeInterface::ATOM),
                $this->configurationMetadata,
            ),
        ];
    }
}
