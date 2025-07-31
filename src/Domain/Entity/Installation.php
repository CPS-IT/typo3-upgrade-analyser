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

/**
 * Represents a TYPO3 installation to be analyzed
 */
class Installation
{
    private array $extensions = [];
    private array $configuration = [];

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
}