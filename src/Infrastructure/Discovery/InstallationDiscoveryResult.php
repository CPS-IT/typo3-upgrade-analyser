<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\SerializableInterface;

/**
 * Result of installation discovery operation.
 *
 * Contains the discovered installation (if successful), validation results,
 * and detailed information about the discovery process including which
 * strategies were attempted.
 */
final readonly class InstallationDiscoveryResult implements SerializableInterface
{
    /**
     * @param Installation|null               $installation        Discovered installation (null if not found)
     * @param bool                            $isSuccessful        Whether discovery was successful
     * @param string                          $errorMessage        Error message if discovery failed
     * @param DetectionStrategyInterface|null $successfulStrategy  Strategy that succeeded
     * @param array<ValidationIssue>          $validationIssues    Validation issues found
     * @param array<array<string, mixed>>     $attemptedStrategies Information about attempted strategies
     */
    private function __construct(
        private ?Installation $installation,
        private bool $isSuccessful,
        private string $errorMessage,
        private ?DetectionStrategyInterface $successfulStrategy,
        private array $validationIssues,
        private array $attemptedStrategies,
    ) {
    }

    /**
     * Create a successful installation discovery result.
     *
     * @param Installation                $installation        Discovered installation
     * @param DetectionStrategyInterface  $strategy            Strategy that succeeded
     * @param array<ValidationIssue>      $validationIssues    Validation issues found
     * @param array<array<string, mixed>> $attemptedStrategies Information about attempted strategies
     *
     * @return self Successful result
     */
    public static function success(
        Installation $installation,
        DetectionStrategyInterface $strategy,
        array $validationIssues = [],
        array $attemptedStrategies = [],
    ): self {
        return new self($installation, true, '', $strategy, $validationIssues, $attemptedStrategies);
    }

    /**
     * Create a failed installation discovery result.
     *
     * @param string                      $errorMessage        Error message describing the failure
     * @param array<array<string, mixed>> $attemptedStrategies Information about attempted strategies
     *
     * @return self Failed result
     */
    public static function failed(string $errorMessage, array $attemptedStrategies = []): self
    {
        return new self(null, false, $errorMessage, null, [], $attemptedStrategies);
    }

    /**
     * Get the discovered installation.
     *
     * @return Installation|null Installation if discovery was successful, null otherwise
     */
    public function getInstallation(): ?Installation
    {
        return $this->installation;
    }

    /**
     * Check if installation discovery was successful.
     *
     * @return bool True if installation was discovered successfully
     */
    public function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }

    /**
     * Get error message if discovery failed.
     *
     * @return string Error message (empty string if successful)
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Get the strategy that successfully discovered the installation.
     *
     * @return DetectionStrategyInterface|null Successful strategy, null if discovery failed
     */
    public function getSuccessfulStrategy(): ?DetectionStrategyInterface
    {
        return $this->successfulStrategy;
    }

    /**
     * Get validation issues found during discovery.
     *
     * @return array<ValidationIssue> Array of validation issues
     */
    public function getValidationIssues(): array
    {
        return $this->validationIssues;
    }

    /**
     * Get information about all attempted detection strategies.
     *
     * @return array<array<string, mixed>> Array of strategy attempt information
     */
    public function getAttemptedStrategies(): array
    {
        return $this->attemptedStrategies;
    }

    /**
     * Check if the discovered installation has validation issues.
     *
     * @return bool True if validation issues were found
     */
    public function hasValidationIssues(): bool
    {
        return !empty($this->validationIssues);
    }

    /**
     * Get validation issues of specific severity level.
     *
     * @param ValidationSeverity $severity Severity level to filter by
     *
     * @return array<ValidationIssue> Issues of specified severity
     */
    public function getValidationIssuesBySeverity(ValidationSeverity $severity): array
    {
        return array_filter(
            $this->validationIssues,
            fn (ValidationIssue $issue): bool => $issue->getSeverity() === $severity,
        );
    }

    /**
     * Check if installation has blocking validation issues.
     *
     * @return bool True if blocking issues are present
     */
    public function hasBlockingIssues(): bool
    {
        return !empty(array_filter(
            $this->validationIssues,
            fn (ValidationIssue $issue): bool => $issue->isBlockingAnalysis(),
        ));
    }

    /**
     * Get validation issues grouped by category.
     *
     * @return array<string, array<ValidationIssue>> Issues grouped by category
     */
    public function getValidationIssuesByCategory(): array
    {
        $grouped = [];

        foreach ($this->validationIssues as $issue) {
            $category = $issue->getCategory();
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $issue;
        }

        return $grouped;
    }

    /**
     * Get a human-readable summary of the discovery result.
     *
     * @return string Summary string
     */
    public function getSummary(): string
    {
        if (!$this->isSuccessful) {
            $attemptedCount = \count($this->attemptedStrategies);
            $supportedCount = \count(array_filter($this->attemptedStrategies, fn ($attempt): mixed => $attempt['supported'] ?? false));

            return \sprintf(
                'Installation discovery failed: %s (attempted %d strategies, %d supported)',
                $this->errorMessage,
                $attemptedCount,
                $supportedCount,
            );
        }

        $version = $this->installation?->getVersion()->toString() ?? 'unknown';
        $mode = $this->installation?->getMode()?->value ?? 'unknown';
        $strategyName = $this->successfulStrategy?->getName() ?? 'unknown';

        $summary = \sprintf(
            'TYPO3 %s installation discovered using %s (%s mode)',
            $version,
            $strategyName,
            $mode,
        );

        if ($this->hasValidationIssues()) {
            $issueCount = \count($this->validationIssues);
            $blockingCount = \count(array_filter($this->validationIssues, fn ($issue): bool => $issue->isBlockingAnalysis()));

            $summary .= \sprintf(
                ' - %d validation issue%s found%s',
                $issueCount,
                1 === $issueCount ? '' : 's',
                $blockingCount > 0 ? " ({$blockingCount} blocking)" : '',
            );
        }

        return $summary;
    }

    /**
     * Get detailed discovery statistics.
     *
     * @return array<string, mixed> Discovery statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'successful' => $this->isSuccessful,
            'attempted_strategies' => \count($this->attemptedStrategies),
            'supported_strategies' => \count(array_filter($this->attemptedStrategies, fn ($attempt): mixed => $attempt['supported'] ?? false)),
            'validation_issues' => \count($this->validationIssues),
            'blocking_issues' => \count(array_filter($this->validationIssues, fn ($issue): bool => $issue->isBlockingAnalysis())),
        ];

        if ($this->isSuccessful) {
            $stats['installation_path'] = $this->installation?->getPath();
            $stats['typo3_version'] = $this->installation?->getVersion()->toString();
            $stats['installation_mode'] = $this->installation?->getMode()?->value;
            $stats['successful_strategy'] = $this->successfulStrategy?->getName();
        }

        return $stats;
    }

    /**
     * Convert result to array for serialization.
     *
     * @return array<string, mixed> Array representation
     */
    public function toArray(): array
    {
        return [
            'successful' => $this->isSuccessful,
            'error_message' => $this->errorMessage,
            'installation' => $this->installation?->toArray(false), // Exclude extensions in discovery context
            'successful_strategy' => $this->successfulStrategy?->getName(),
            'validation_issues' => array_map(fn (ValidationIssue $issue): array => $issue->toArray(), $this->validationIssues),
            'validation_summary' => [
                'total_issues' => \count($this->validationIssues),
                'by_severity' => $this->getValidationIssueCountsBySeverity(),
                'by_category' => array_map('count', $this->getValidationIssuesByCategory()),
                'has_blocking_issues' => $this->hasBlockingIssues(),
            ],
            'attempted_strategies' => $this->attemptedStrategies,
            'statistics' => $this->getStatistics(),
            'summary' => $this->getSummary(),
        ];
    }

    /**
     * Get validation issue counts by severity level.
     *
     * @return array<string, int> Issue counts by severity
     */
    private function getValidationIssueCountsBySeverity(): array
    {
        $counts = [
            'info' => 0,
            'warning' => 0,
            'error' => 0,
            'critical' => 0,
        ];

        foreach ($this->validationIssues as $issue) {
            $severity = $issue->getSeverity()->value;
            if (isset($counts[$severity])) {
                ++$counts[$severity];
            }
        }

        return $counts;
    }

    /**
     * Create result from array data.
     *
     * @param array<string, mixed> $data Array representation to deserialize from
     *
     * @return static Deserialized result instance
     */
    public static function fromArray(array $data): static
    {
        if ($data['successful']) {
            // Use Installation's own fromArray method for proper deserialization
            $installation = Installation::fromArray($data['installation']);

            return new self(
                $installation,
                true,
                '',
                null, // Strategy cannot be reconstructed from cache
                [], // Skip validation issues for cached results
                $data['attempted_strategies'] ?? [],
            );
        } else {
            return new self(
                null,
                false,
                $data['error_message'] ?? 'Unknown cached error',
                null,
                [],
                $data['attempted_strategies'] ?? [],
            );
        }
    }
}
