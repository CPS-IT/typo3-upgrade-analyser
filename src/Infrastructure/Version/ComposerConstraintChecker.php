<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Version;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unified service for checking Composer version constraints against TYPO3 versions.
 */
readonly class ComposerConstraintChecker implements ComposerConstraintCheckerInterface
{
    private VersionParser $versionParser;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->versionParser = new VersionParser();
    }

    public function isConstraintCompatible(string $constraint, Version $targetVersion): bool
    {
        try {
            // Parse the constraint using Composer's parser
            $parsedConstraint = $this->versionParser->parseConstraints($constraint);

            // When the target has no explicit patch (e.g. "13.4"), check against the
            // ceiling of the minor series (13.4.9999) so that constraints like
            // "^13.4.20" are recognised as compatible with the 13.4.x upgrade target.
            // This is a heuristic: any constraint with an upper patch bound below 9999
            // (e.g. ">=13.4.0,<13.4.100") would produce a false negative, but such
            // tight upper patch bounds do not appear in real-world TYPO3 constraints.
            // Note: dev-branch Version objects (major=999, minor=999, hasPatch=false)
            // would resolve to 999.999.9999 — they are not valid constraint check targets.
            if (!$targetVersion->hasPatch()) {
                $targetVersionString = \sprintf('%d.%d.9999', $targetVersion->getMajor(), $targetVersion->getMinor());
            } else {
                $targetVersionString = $targetVersion->toString();
            }

            // Normalize the version for comparison
            $normalizedVersion = $this->versionParser->normalize($targetVersionString);

            // Check if target version satisfies the constraint
            return $parsedConstraint->matches(
                new Constraint('=', $normalizedVersion),
            );
        } catch (\Exception $e) {
            // Parsing failed — the constraint is malformed or empty.
            // Return false as a safe conservative default.
            $this->logger->warning('ComposerConstraintChecker: failed to parse constraint', [
                'constraint' => $constraint,
                'target' => $targetVersion->toString(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function isComposerJsonCompatible(?array $composerJson, Version $targetVersion): bool
    {
        if (!$composerJson || !isset($composerJson['require'])) {
            return false;
        }

        $requirements = $composerJson['require'];

        // Check for TYPO3 core requirements
        $typo3Requirements = array_filter($requirements, function ($constraint, $package): bool {
            return str_starts_with($package, 'typo3/cms-');
        }, ARRAY_FILTER_USE_BOTH);

        if (empty($typo3Requirements)) {
            return false;
        }

        // Check if any TYPO3 requirement is compatible with target version
        foreach ($typo3Requirements as $constraint) {
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
