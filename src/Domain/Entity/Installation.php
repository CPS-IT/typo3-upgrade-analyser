<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Domain\Entity;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMetadata;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ExtensionType;

/**
 * Represents a TYPO3 installation to be analyzed
 */
class Installation
{
    private array $extensions = [];
    private array $configuration = [];
    private ?InstallationMode $mode = null;
    private ?InstallationMetadata $metadata = null;
    private bool $isValid = true;
    private array $validationErrors = [];

    public function __construct(
        private readonly string $path,
        private readonly Version $version,
        private readonly string $type = 'composer'
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

    public function getExtensions(): array
    {
        return $this->extensions;
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
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public function isComposerMode(): bool
    {
        return $this->type === 'composer';
    }

    public function isLegacyMode(): bool
    {
        return $this->type === 'legacy';
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

    public function getExtensionByKey(string $key): ?Extension
    {
        return $this->getExtension($key);
    }

    public function getSystemExtensions(): array
    {
        return array_filter($this->extensions, fn(Extension $ext) => $ext->getType() === 'system');
    }

    public function getLocalExtensions(): array
    {
        return array_filter($this->extensions, fn(Extension $ext) => $ext->getType() === 'local');
    }

    public function getComposerExtensions(): array
    {
        return array_filter($this->extensions, fn(Extension $ext) => $ext->hasComposerName());
    }

    public function isMixedMode(): bool
    {
        // Mixed mode would combine local and composer extensions
        return $this->hasLocalExtensions() && $this->hasComposerExtensions();
    }

    private function hasLocalExtensions(): bool
    {
        return count($this->getLocalExtensions()) > 0;
    }

    private function hasComposerExtensions(): bool
    {
        return count($this->getComposerExtensions()) > 0;
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

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'version' => $this->version->toString(),
            'type' => $this->type,
            'mode' => $this->mode?->value,
            'extensions' => array_map(fn($ext) => $ext->toArray(), $this->extensions),
            'configuration' => $this->configuration,
            'metadata' => $this->metadata?->toArray(),
            'is_valid' => $this->isValid,
            'validation_errors' => $this->validationErrors,
        ];
    }

    public function setValidationErrors(array $errors): void
    {
        $this->validationErrors = $errors;
        $this->isValid = empty($errors);
    }
}