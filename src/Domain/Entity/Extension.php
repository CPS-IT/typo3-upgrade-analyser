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
 * Represents a TYPO3 extension
 */
class Extension
{
    private array $dependencies = [];
    private array $files = [];
    private ?string $repositoryUrl = null;
    private array $emConfiguration = [];

    public function __construct(
        private readonly string $key,
        private readonly string $title,
        private readonly Version $version,
        private readonly string $type = 'local',
        private readonly ?string $composerName = null
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getVersion(): Version
    {
        return $this->version;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getComposerName(): ?string
    {
        return $this->composerName;
    }

    public function hasComposerName(): bool
    {
        return $this->composerName !== null;
    }

    public function addDependency(string $extensionKey, ?string $version = null): void
    {
        $this->dependencies[$extensionKey] = $version;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function hasDependency(string $extensionKey): bool
    {
        return array_key_exists($extensionKey, $this->dependencies);
    }

    public function addFile(string $filePath): void
    {
        $this->files[] = $filePath;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getPhpFiles(): array
    {
        return array_filter($this->files, fn($file) => str_ends_with($file, '.php'));
    }

    public function getTcaFiles(): array
    {
        return array_filter($this->files, fn($file) => str_contains($file, '/TCA/') && str_ends_with($file, '.php'));
    }

    public function getTemplateFiles(): array
    {
        return array_filter($this->files, fn($file) => str_ends_with($file, '.html') || str_ends_with($file, '.fluid'));
    }

    public function isSystemExtension(): bool
    {
        return $this->type === 'system';
    }

    public function isLocalExtension(): bool
    {
        return $this->type === 'local';
    }

    public function isTerExtension(): bool
    {
        return $this->type === 'ter';
    }

    public function getLinesOfCode(): int
    {
        $lines = 0;
        foreach ($this->getPhpFiles() as $file) {
            if (file_exists($file)) {
                $lines += count(file($file));
            }
        }
        return $lines;
    }

    public function setRepositoryUrl(?string $repositoryUrl): void
    {
        $this->repositoryUrl = $repositoryUrl;
    }

    public function getRepositoryUrl(): ?string
    {
        return $this->repositoryUrl;
    }

    public function hasRepositoryUrl(): bool
    {
        return $this->repositoryUrl !== null;
    }

    public function setEmConfiguration(array $emConfiguration): void
    {
        $this->emConfiguration = $emConfiguration;
    }

    public function getEmConfiguration(): array
    {
        return $this->emConfiguration;
    }

    public function getEmConfigurationValue(string $key): mixed
    {
        return $this->emConfiguration[$key] ?? null;
    }
}