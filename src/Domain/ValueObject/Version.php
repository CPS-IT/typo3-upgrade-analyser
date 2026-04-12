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
 * Value object representing a version number.
 */
class Version implements \Stringable
{
    private int $major;
    private int $minor;
    private int $patch;
    private ?string $suffix;
    private bool $isBranchVersion = false;
    private ?string $originalVersion = null;
    private bool $hasPatch = false;

    public function __construct(string $version)
    {
        $this->parseVersion($version);
    }

    public static function fromString(string $version): self
    {
        return new self($version);
    }

    public function getMajor(): int
    {
        return $this->major;
    }

    public function getMinor(): int
    {
        return $this->minor;
    }

    public function getPatch(): int
    {
        return $this->patch;
    }

    /**
     * Returns true when the patch number was explicitly present in the input string.
     * Returns false when only major.minor was given (patch defaulted to 0).
     *
     * Important: this flag is preserved by toString() — a Version constructed from
     * a two-component string (e.g. '13.4') will round-trip back to '13.4', keeping
     * hasPatch() === false. Do NOT reconstruct a Version from version->toString()
     * when the hasPatch distinction matters; use the original string instead.
     */
    public function hasPatch(): bool
    {
        return $this->hasPatch;
    }

    public function getSuffix(): ?string
    {
        return $this->suffix;
    }

    public function toString(): string
    {
        // For branch versions, return the original branch name
        if ($this->isBranchVersion && null !== $this->originalVersion) {
            return $this->originalVersion;
        }

        // Preserve the original component count so that round-trips via toString() keep
        // hasPatch() correct. A Version('13.4') serialises back to '13.4', not '13.4.0'.
        if ($this->hasPatch) {
            $version = "{$this->major}.{$this->minor}.{$this->patch}";
        } else {
            $version = "{$this->major}.{$this->minor}";
        }

        if (null !== $this->suffix) {
            $version .= "-{$this->suffix}";
        }

        return $version;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function isGreaterThan(Version $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function isLessThan(Version $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isEqual(Version $other): bool
    {
        return 0 === $this->compare($other);
    }

    public function isLessThanOrEqualTo(Version $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function isGreaterThanOrEqualTo(Version $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function isCompatibleWith(Version $other): bool
    {
        return $this->major === $other->major;
    }

    public function compare(Version $other): int
    {
        if ($this->major !== $other->major) {
            return $this->major <=> $other->major;
        }

        if ($this->minor !== $other->minor) {
            return $this->minor <=> $other->minor;
        }

        if ($this->patch !== $other->patch) {
            return $this->patch <=> $other->patch;
        }

        // Compare suffixes (null is considered "greater" than any suffix)
        if (null === $this->suffix && null === $other->suffix) {
            return 0;
        }
        if (null === $this->suffix) {
            return 1;
        }
        if (null === $other->suffix) {
            return -1;
        }

        return $this->suffix <=> $other->suffix;
    }

    private function parseVersion(string $version): void
    {
        $originalInput = $version;

        // Handle development branch versions (dev-*)
        if (str_starts_with($version, 'dev-')) {
            $this->isBranchVersion = true;
            $this->originalVersion = $originalInput;
            $this->major = 999;
            $this->minor = 999;
            $this->patch = 999;
            $this->suffix = 'dev';

            return;
        }

        // Remove common prefixes
        $version = ltrim($version, 'v^~>=<');

        // Handle constraint formats like "^12.4" or "~12.4.0"
        if (preg_match('/^[\^~>=<]*(\d+)\.(\d+)(?:\.(\d+))?(?:-([a-zA-Z0-9\-\.]+))?$/', $version, $matches)) {
            $patchStr = $matches[3] ?? null;
            $this->major = (int) $matches[1];
            $this->minor = (int) $matches[2];
            $this->hasPatch = null !== $patchStr;
            $this->patch = null !== $patchStr ? (int) $patchStr : 0;
            $this->suffix = $matches[4] ?? null;
        } else {
            throw new \InvalidArgumentException("Invalid version format: $version");
        }
    }
}
