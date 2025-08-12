<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Domain\ValueObject;

/**
 * Contains metadata about a TYPO3 extension discovered during installation analysis.
 *
 * This value object aggregates information from multiple sources:
 * - ext_emconf.php files (traditional TYPO3 extension configuration)
 * - composer.json files (Composer package metadata)
 * - Filesystem analysis (file structure, permissions, etc.)
 */
final class ExtensionMetadata
{
    /**
     * @param string               $description            Extension description
     * @param string               $author                 Extension author name
     * @param string               $authorEmail            Author email address
     * @param array<string>        $keywords               Extension keywords/tags
     * @param string               $license                License identifier (e.g., GPL-2.0-or-later)
     * @param array<string>        $supportedPhpVersions   Supported PHP versions
     * @param array<string>        $supportedTypo3Versions Supported TYPO3 versions
     * @param \DateTimeImmutable   $lastModified           Last modification timestamp
     * @param array<string, mixed> $additionalData         Additional metadata from various sources
     */
    public function __construct(
        private readonly string $description,
        private readonly string $author,
        private readonly string $authorEmail,
        private readonly array $keywords,
        private readonly string $license,
        private readonly array $supportedPhpVersions,
        private readonly array $supportedTypo3Versions,
        private readonly \DateTimeImmutable $lastModified,
        private readonly array $additionalData = [],
    ) {
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getAuthorEmail(): string
    {
        return $this->authorEmail;
    }

    public function getKeywords(): array
    {
        return $this->keywords;
    }

    public function hasKeyword(string $keyword): bool
    {
        return \in_array(strtolower($keyword), array_map('strtolower', $this->keywords), true);
    }

    public function getLicense(): string
    {
        return $this->license;
    }

    public function getSupportedPhpVersions(): array
    {
        return $this->supportedPhpVersions;
    }

    public function supportsPhpVersion(string $version): bool
    {
        // Simple check - in a full implementation would use version_compare with constraints
        return \in_array($version, $this->supportedPhpVersions, true);
    }

    public function getSupportedTypo3Versions(): array
    {
        return $this->supportedTypo3Versions;
    }

    public function supportsTypo3Version(string $version): bool
    {
        // Simple check - in a full implementation would use semantic version matching
        return \in_array($version, $this->supportedTypo3Versions, true);
    }

    public function getLastModified(): \DateTimeImmutable
    {
        return $this->lastModified;
    }

    public function getAdditionalData(): array
    {
        return $this->additionalData;
    }

    public function getAdditionalValue(string $key): mixed
    {
        return $this->additionalData[$key] ?? null;
    }

    public function hasComposerData(): bool
    {
        return isset($this->additionalData['composer_data']);
    }

    public function hasEmconfData(): bool
    {
        return isset($this->additionalData['emconf_data']);
    }

    public function getComposerData(): ?array
    {
        return $this->additionalData['composer_data'] ?? null;
    }

    public function getEmconfData(): ?array
    {
        return $this->additionalData['emconf_data'] ?? null;
    }

    public function withAdditionalData(array $data): self
    {
        return new self(
            $this->description,
            $this->author,
            $this->authorEmail,
            $this->keywords,
            $this->license,
            $this->supportedPhpVersions,
            $this->supportedTypo3Versions,
            $this->lastModified,
            array_merge($this->additionalData, $data),
        );
    }

    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'author' => $this->author,
            'author_email' => $this->authorEmail,
            'keywords' => $this->keywords,
            'license' => $this->license,
            'supported_php_versions' => $this->supportedPhpVersions,
            'supported_typo3_versions' => $this->supportedTypo3Versions,
            'last_modified' => $this->lastModified->format(\DateTimeInterface::ATOM),
            'additional_data' => $this->additionalData,
        ];
    }

    public static function createEmpty(): self
    {
        return new self(
            '',
            '',
            '',
            [],
            '',
            [],
            [],
            new \DateTimeImmutable(),
        );
    }
}
