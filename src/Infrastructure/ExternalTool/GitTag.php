<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool;

/**
 * Represents a Git tag with associated metadata.
 */
class GitTag
{
    public function __construct(
        private readonly string $name,
        private readonly ?\DateTimeImmutable $date = null,
        private readonly ?string $commit = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function getCommit(): ?string
    {
        return $this->commit;
    }

    /**
     * Extract semantic version from tag name if possible.
     */
    public function getSemanticVersion(): ?string
    {
        // Remove common prefixes
        $version = preg_replace('/^(v|version|release)[-_]?/i', '', $this->name);

        // Check if it matches semantic versioning pattern
        if (preg_match('/^\d+\.\d+(\.\d+)?(-[\w\.-]+)?(\+[\w\.-]+)?$/', $version)) {
            return $version;
        }

        return null;
    }

    /**
     * Check if this tag represents a semantic version.
     */
    public function isSemanticVersion(): bool
    {
        return null !== $this->getSemanticVersion();
    }

    /**
     * Get the major version number if this is a semantic version.
     */
    public function getMajorVersion(): ?int
    {
        $version = $this->getSemanticVersion();
        if ($version) {
            $parts = explode('.', explode('-', $version)[0]);

            return (int) $parts[0];
        }

        return null;
    }

    /**
     * Get the minor version number if this is a semantic version.
     */
    public function getMinorVersion(): ?int
    {
        $version = $this->getSemanticVersion();
        if ($version) {
            $parts = explode('.', explode('-', $version)[0]);

            return isset($parts[1]) ? (int) $parts[1] : null;
        }

        return null;
    }

    /**
     * Check if this is a pre-release version.
     */
    public function isPreRelease(): bool
    {
        $version = $this->getSemanticVersion();

        return $version && str_contains($version, '-');
    }

    /**
     * Compare this tag with another tag by date.
     */
    public function isNewerThan(GitTag $other): bool
    {
        if ($this->date && $other->getDate()) {
            return $this->date > $other->getDate();
        }

        // Fall back to name comparison if no dates available
        return version_compare($this->name, $other->getName(), '>');
    }
}
