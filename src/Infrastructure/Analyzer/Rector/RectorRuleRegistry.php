<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use Psr\Log\LoggerInterface;
use Ssch\TYPO3Rector\Set\Typo3SetList;

/**
 * Registry for TYPO3 Rector rules organized by version and category.
 */
class RectorRuleRegistry
{
    public const KEY_CATEGORY = 'category';
    public const string KEY_CHANGE_TYPE = 'changeType';
    public const string KEY_DESCRIPTION = 'description';
    public const string KEY_EFFORT = 'effort';
    public const string KEY_SEVERITY = 'severity';

    /**
     * TYPO3 version-specific Rector sets mapping.
     * Organized by target version with sets that should be applied when upgrading TO that version.
     */
    private const TYPO3_VERSION_SETS = [
        '10.0' => [
            Typo3SetList::TYPO3_10,
        ],
        '11.0' => [
            Typo3SetList::TYPO3_11,
        ],
        '12.0' => [
            Typo3SetList::TYPO3_12,
        ],
        '13.0' => [
            Typo3SetList::TYPO3_13,
        ],
        '14.0' => [
            Typo3SetList::TYPO3_14,
        ],
    ];

    /**
     * General TYPO3 sets that apply across versions.
     */
    private const GENERAL_SETS = [
        'general' => [Typo3SetList::GENERAL],
        'code_quality' => [Typo3SetList::CODE_QUALITY],
    ];

    /**
     * Set metadata for providing descriptions and categorization.
     */
    private const SET_METADATA = [
        Typo3SetList::TYPO3_10 => [
            self::KEY_DESCRIPTION => 'TYPO3 10 upgrade rules',
            self::KEY_CATEGORY => 'version_upgrade',
            self::KEY_SEVERITY => 'warning',
            'effort_minutes' => 60,
        ],
        Typo3SetList::TYPO3_11 => [
            self::KEY_DESCRIPTION => 'TYPO3 11 upgrade rules',
            self::KEY_CATEGORY => 'version_upgrade',
            self::KEY_SEVERITY => 'warning',
            'effort_minutes' => 90,
        ],
        Typo3SetList::TYPO3_12 => [
            self::KEY_DESCRIPTION => 'TYPO3 12 upgrade rules',
            self::KEY_CATEGORY => 'version_upgrade',
            self::KEY_SEVERITY => 'critical',
            'effort_minutes' => 120,
        ],
        Typo3SetList::TYPO3_13 => [
            self::KEY_DESCRIPTION => 'TYPO3 13 upgrade rules',
            self::KEY_CATEGORY => 'version_upgrade',
            self::KEY_SEVERITY => 'critical',
            'effort_minutes' => 120,
        ],
        Typo3SetList::TYPO3_14 => [
            self::KEY_DESCRIPTION => 'TYPO3 14 upgrade rules',
            self::KEY_CATEGORY => 'version_upgrade',
            self::KEY_SEVERITY => 'critical',
            'effort_minutes' => 150,
        ],
        Typo3SetList::GENERAL => [
            self::KEY_DESCRIPTION => 'General TYPO3 best practices',
            self::KEY_CATEGORY => 'best_practice',
            self::KEY_SEVERITY => 'info',
            'effort_minutes' => 30,
        ],
        Typo3SetList::CODE_QUALITY => [
            self::KEY_DESCRIPTION => 'Code quality improvements',
            self::KEY_CATEGORY => 'code_quality',
            self::KEY_SEVERITY => 'info',
            'effort_minutes' => 45,
        ],
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get Rector sets for upgrading from one version to another.
     *
     * @return array<string> Array of Rector set paths
     */
    public function getSetsForVersionUpgrade(Version $fromVersion, Version $toVersion): array
    {
        $sets = [];

        // For same version analysis, provide general best practice sets
        if (0 === $fromVersion->compare($toVersion)) {
            $this->logger->info('Same version analysis - applying general best practice sets', [
                'version' => $fromVersion->toString(),
            ]);

            // Apply general sets for code quality analysis
            $sets = array_merge($sets, self::GENERAL_SETS['general']);
            $sets = array_merge($sets, self::GENERAL_SETS['code_quality']);

            return array_unique($sets);
        }

        // Return empty for downgrades
        if ($fromVersion->isGreaterThan($toVersion)) {
            $this->logger->warning('Downgrade scenario detected - no sets applied', [
                'from_version' => $fromVersion->toString(),
                'to_version' => $toVersion->toString(),
            ]);

            return $sets;
        }

        // Return empty if the source version is unsupported
        if (!$this->isVersionSupported($fromVersion)) {
            return $sets;
        }

        // Get all version-specific sets between source and target versions
        foreach (self::TYPO3_VERSION_SETS as $versionString => $versionSets) {
            $setVersion = new Version($versionString);

            // Include sets for versions greater than source and less than or equal to target
            if ($setVersion->isGreaterThan($fromVersion) && $setVersion->isLessThanOrEqualTo($toVersion)) {
                array_push($sets, ...$versionSets);

                $this->logger->info('Including sets for TYPO3 version {version}', [
                    'version' => $versionString,
                    'set_count' => \count($versionSets),
                ]);
            }
        }

        // Always include general sets for upgrades when at least one version is supported
        // (fromVersion is already validated above, so we always include general sets)

        $sets = array_merge($sets, self::GENERAL_SETS['general']);

        // Include code quality sets for major version upgrades
        if ($fromVersion->getMajor() !== $toVersion->getMajor()) {
            $sets = array_merge($sets, self::GENERAL_SETS['code_quality']);
        }

        $sets = array_unique($sets);

        $this->logger->info('Selected {count} Rector sets for version upgrade', [
            'count' => \count($sets),
            'from_version' => $fromVersion->toString(),
            'to_version' => $toVersion->toString(),
        ]);

        return $sets;
    }

    /**
     * Get sets by category.
     *
     * @return array<string> Array of Rector set paths
     */
    public function getSetsByCategory(string $category): array
    {
        return self::GENERAL_SETS[$category] ?? [];
    }

    /**
     * Get all available sets.
     *
     * @return array<string> Array of all Rector set paths
     */
    public function getAllAvailableSets(): array
    {
        $allSets = [];

        // Collect version-specific sets
        foreach (self::TYPO3_VERSION_SETS as $versionSets) {
            $allSets = array_merge($allSets, $versionSets);
        }

        // Collect general sets
        foreach (self::GENERAL_SETS as $categorySets) {
            $allSets = array_merge($allSets, $categorySets);
        }

        return array_unique($allSets);
    }

    /**
     * Get description for a specific set.
     */
    public function getSetDescription(string $setPath): string
    {
        $metadata = self::SET_METADATA[$setPath] ?? null;

        if ($metadata) {
            return $metadata[self::KEY_DESCRIPTION];
        }

        // Return default for unknown sets
        return 'Unknown set';
    }

    /**
     * Get severity for a specific set.
     */
    public function getSetSeverity(string $setPath): RectorRuleSeverity
    {
        $metadata = self::SET_METADATA[$setPath] ?? null;

        if ($metadata) {
            return RectorRuleSeverity::from($metadata[self::KEY_SEVERITY]);
        }

        // Determine severity from set path patterns
        if (str_contains($setPath, 'typo3-12') || str_contains($setPath, 'typo3-13') || str_contains($setPath, 'typo3-14')) {
            return RectorRuleSeverity::CRITICAL;
        }

        if (str_contains($setPath, 'typo3-10') || str_contains($setPath, 'typo3-11')) {
            return RectorRuleSeverity::WARNING;
        }

        return RectorRuleSeverity::INFO;
    }

    /**
     * Get a change type for a specific set.
     */
    public function getSetChangeType(string $setPath): RectorChangeType
    {
        // Determine a change type from a set path
        if (str_contains($setPath, 'typo3-12') || str_contains($setPath, 'typo3-13') || str_contains($setPath, 'typo3-14')) {
            return RectorChangeType::BREAKING_CHANGE;
        }

        if (str_contains($setPath, 'typo3-10') || str_contains($setPath, 'typo3-11')) {
            return RectorChangeType::DEPRECATION;
        }

        if (str_contains($setPath, 'code-quality')) {
            return RectorChangeType::BEST_PRACTICE;
        }

        if (str_contains($setPath, 'general')) {
            return RectorChangeType::BEST_PRACTICE;
        }

        return RectorChangeType::DEPRECATION;
    }

    /**
     * Get estimated effort for a specific set in minutes.
     */
    public function getSetEffort(string $setPath): int
    {
        $metadata = self::SET_METADATA[$setPath] ?? null;

        if ($metadata) {
            return $metadata['effort_minutes'];
        }

        // Default effort based on a change type
        return $this->getSetChangeType($setPath)->getEstimatedEffort();
    }

    /**
     * Check if a set exists in the registry.
     */
    public function hasSet(string $setPath): bool
    {
        return \in_array($setPath, $this->getAllAvailableSets(), true);
    }

    /**
     * Get sets applicable for a specific TYPO3 major version.
     *
     * @return array<string> Array of Rector set paths
     */
    public function getSetsForMajorVersion(int $majorVersion): array
    {
        $sets = [];

        foreach (self::TYPO3_VERSION_SETS as $versionString => $versionSets) {
            $version = new Version($versionString);

            if ($version->getMajor() === $majorVersion) {
                $sets = array_merge($sets, $versionSets);
            }
        }

        return array_unique($sets);
    }

    /**
     * Get supported TYPO3 versions that have sets defined.
     *
     * @return array<string> Array of version strings
     */
    public function getSupportedVersions(): array
    {
        return array_keys(self::TYPO3_VERSION_SETS);
    }

    /**
     * Check if a version is supported by the registry.
     */
    public function isVersionSupported(Version $version): bool
    {
        $versionString = $version->getMajor() . '.0';

        return \array_key_exists($versionString, self::TYPO3_VERSION_SETS);
    }

    /**
     * Get sets for a specific version.
     *
     * @return array<string> Array of Rector set paths
     */
    public function getVersionSpecificSets(Version $version): array
    {
        $versionString = $version->toString();

        // Try exact version match first
        if (isset(self::TYPO3_VERSION_SETS[$versionString])) {
            return self::TYPO3_VERSION_SETS[$versionString];
        }

        // Try major.0 version if exact not found
        $majorVersionString = $version->getMajor() . '.0';

        return self::TYPO3_VERSION_SETS[$majorVersionString] ?? [];
    }

    /**
     * Get statistics about the sets in the registry.
     *
     * @return array<string, mixed> Statistics about sets
     */
    public function getSetsStatistics(): array
    {
        $allSets = $this->getAllAvailableSets();
        $stats = [
            'total_sets' => \count($allSets),
            'sets_by_version' => [],
            'sets_by_category' => [],
            'sets_by_severity' => [],
        ];

        // Count sets by version
        foreach (self::TYPO3_VERSION_SETS as $version => $sets) {
            $stats['sets_by_version'][$version] = \count($sets);
        }

        // Count sets by category
        foreach (self::GENERAL_SETS as $category => $sets) {
            $stats['sets_by_category'][$category] = \count($sets);
        }

        // Count sets by severity
        $severityCount = [];
        foreach ($allSets as $set) {
            $severity = $this->getSetSeverity($set)->value;
            $severityCount[$severity] = ($severityCount[$severity] ?? 0) + 1;
        }
        $stats['sets_by_severity'] = $severityCount;

        return $stats;
    }

    /**
     * Get severity for a specific rule class based on pattern matching.
     * This method provides rule-level granularity by analyzing the rule class name.
     */
    public function getRuleSeverity(string $ruleClass): RectorRuleSeverity
    {
        // First, try to determine severity from rule class patterns
        // This provides more granular control than set-based severity

        // Explicit critical patterns
        if (str_contains($ruleClass, 'Critical') || str_contains($ruleClass, 'v12\\') || str_contains($ruleClass, 'v13\\') || str_contains($ruleClass, 'v14\\')) {
            return RectorRuleSeverity::CRITICAL;
        }

        // Deprecations in older versions
        if (str_contains($ruleClass, 'v10\\') || str_contains($ruleClass, 'v11\\')) {
            return RectorRuleSeverity::WARNING;
        }

        // Code quality improvements
        if (str_contains($ruleClass, 'CodeQuality') || str_contains($ruleClass, 'General')) {
            return RectorRuleSeverity::INFO;
        }

        // Default for unknown patterns - use warning as conservative approach
        return RectorRuleSeverity::WARNING;
    }

    /**
     * Get change type for a specific rule class based on pattern matching.
     * This method provides rule-level granularity by analyzing the rule class name.
     */
    public function getRuleChangeType(string $ruleClass): RectorChangeType
    {
        // Breaking changes
        if (str_contains($ruleClass, 'Remove') || str_contains($ruleClass, 'v12\\') || str_contains($ruleClass, 'v13\\') || str_contains($ruleClass, 'v14\\')) {
            return RectorChangeType::BREAKING_CHANGE;
        }

        // Deprecations
        if (str_contains($ruleClass, 'Deprecat') || str_contains($ruleClass, 'v10\\') || str_contains($ruleClass, 'v11\\')) {
            return RectorChangeType::DEPRECATION;
        }

        // Code quality improvements
        if (str_contains($ruleClass, 'CodeQuality') || str_contains($ruleClass, 'General')) {
            return RectorChangeType::BEST_PRACTICE;
        }

        // Default for unknown patterns
        return RectorChangeType::DEPRECATION;
    }

    /**
     * Get rule information for a specific rule class.
     * This combines severity, change type, and estimated effort into a single lookup.
     *
     * @return array{severity: RectorRuleSeverity, changeType: RectorChangeType, effort: int}
     */
    public function getRuleInfo(string $ruleClass): array
    {
        $severity = $this->getRuleSeverity($ruleClass);
        $changeType = $this->getRuleChangeType($ruleClass);

        // Calculate effort based on change type and severity
        $effort = match ([$changeType, $severity]) {
            [RectorChangeType::BREAKING_CHANGE, RectorRuleSeverity::CRITICAL] => 15,
            [RectorChangeType::BREAKING_CHANGE, RectorRuleSeverity::WARNING] => 10,
            [RectorChangeType::BREAKING_CHANGE, RectorRuleSeverity::INFO] => 10,
            [RectorChangeType::BREAKING_CHANGE, RectorRuleSeverity::SUGGESTION] => 10,
            [RectorChangeType::DEPRECATION, RectorRuleSeverity::WARNING] => $this->isKnownRule($ruleClass) ? 8 : 5,
            [RectorChangeType::DEPRECATION, RectorRuleSeverity::CRITICAL] => 5,
            [RectorChangeType::DEPRECATION, RectorRuleSeverity::INFO] => 5,
            [RectorChangeType::DEPRECATION, RectorRuleSeverity::SUGGESTION] => 5,
            [RectorChangeType::BEST_PRACTICE, RectorRuleSeverity::CRITICAL] => 3,
            [RectorChangeType::BEST_PRACTICE, RectorRuleSeverity::WARNING] => 3,
            [RectorChangeType::BEST_PRACTICE, RectorRuleSeverity::INFO] => 3,
            [RectorChangeType::BEST_PRACTICE, RectorRuleSeverity::SUGGESTION] => 3,
            default => 5,
        };

        return [
            self::KEY_SEVERITY => $severity,
            self::KEY_CHANGE_TYPE => $changeType,
            self::KEY_EFFORT => $effort,
        ];
    }

    /**
     * Check if a rule class matches any known TYPO3 Rector patterns.
     */
    public function isKnownRule(string $ruleClass): bool
    {
        // Check if it's a TYPO3 Rector rule
        return str_contains($ruleClass, 'TYPO3Rector')
            || str_contains($ruleClass, 'Ssch\\')
            || preg_match('/v\d+\\\\/', $ruleClass);
    }
}
