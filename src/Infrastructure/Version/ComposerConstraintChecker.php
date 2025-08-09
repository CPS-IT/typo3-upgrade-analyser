<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Version;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Unified service for checking Composer version constraints against TYPO3 versions.
 */
class ComposerConstraintChecker implements ComposerConstraintCheckerInterface
{
    private readonly VersionParser $versionParser;

    public function __construct()
    {
        $this->versionParser = new VersionParser();
    }

    public function isConstraintCompatible(string $constraint, Version $targetVersion): bool
    {
        try {
            // Parse the constraint using Composer's parser
            $parsedConstraint = $this->versionParser->parseConstraints($constraint);

            // Create normalized version string for target version
            $targetVersionString = $targetVersion->toString();

            // If target version doesn't have patch version, add .0
            if (1 === substr_count($targetVersionString, '.')) {
                $targetVersionString .= '.0';
            }

            // Normalize the version for comparison
            $normalizedVersion = $this->versionParser->normalize($targetVersionString);

            // Check if target version satisfies the constraint
            return $parsedConstraint->matches(
                new Constraint('=', $normalizedVersion),
            );
        } catch (\Exception $e) {
            // If parsing fails, fall back to simple major version matching
            return str_contains($constraint, (string) $targetVersion->getMajor());
        }
    }

    public function isComposerJsonCompatible(?array $composerJson, Version $targetVersion): bool
    {
        if (!$composerJson || !isset($composerJson['require'])) {
            return false;
        }

        $requirements = $composerJson['require'];

        // Check for TYPO3 core requirements
        $typo3Requirements = array_filter($requirements, function ($constraint, $package) {
            return str_starts_with($package, 'typo3/cms-');
        }, ARRAY_FILTER_USE_BOTH);

        if (empty($typo3Requirements)) {
            return false;
        }

        // Check if any TYPO3 requirement is compatible with target version
        foreach ($typo3Requirements as $package => $constraint) {
            if ($this->isConstraintCompatible($constraint, $targetVersion)) {
                return true;
            }
        }

        return false;
    }

    public function findTypo3Requirements(array $requirements): array
    {
        // Standard TYPO3 requirement patterns
        $typo3Packages = [
            'typo3/cms-core',
            'typo3/cms',
            'typo3/minimal',
        ];

        $typo3Requirements = [];

        foreach ($typo3Packages as $package) {
            if (isset($requirements[$package])) {
                $typo3Requirements[$package] = $requirements[$package];
            }
        }

        // Also check for other typo3/cms-* packages
        foreach ($requirements as $package => $constraint) {
            if (str_starts_with($package, 'typo3/cms-') && !isset($typo3Requirements[$package])) {
                $typo3Requirements[$package] = $constraint;
            }
        }

        return $typo3Requirements;
    }

    public function normalizeVersion(Version $version): string
    {
        $versionString = $version->toString();

        // If version doesn't have patch version, add .0
        if (1 === substr_count($versionString, '.')) {
            $versionString .= '.0';
        }

        return $this->versionParser->normalize($versionString);
    }
}
