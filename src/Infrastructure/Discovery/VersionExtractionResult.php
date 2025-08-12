<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Result of version extraction operation.
 *
 * Contains the extracted version (if successful), metadata about the extraction
 * process, and information about which strategies were attempted.
 */
final class VersionExtractionResult
{
    /**
     * @param Version|null                  $version             Extracted version (null if extraction failed)
     * @param bool                          $isSuccessful        Whether extraction was successful
     * @param string                        $errorMessage        Error message if extraction failed
     * @param VersionStrategyInterface|null $successfulStrategy  Strategy that succeeded
     * @param array<array<string, mixed>>   $attemptedStrategies Information about attempted strategies
     */
    private function __construct(
        private readonly ?Version $version,
        private readonly bool $isSuccessful,
        private readonly string $errorMessage,
        private readonly ?VersionStrategyInterface $successfulStrategy,
        private readonly array $attemptedStrategies,
    ) {
    }

    /**
     * Create a successful version extraction result.
     *
     * @param Version                     $version             Extracted version
     * @param VersionStrategyInterface    $strategy            Strategy that succeeded
     * @param array<array<string, mixed>> $attemptedStrategies Information about attempted strategies
     *
     * @return self Successful result
     */
    public static function success(
        Version $version,
        VersionStrategyInterface $strategy,
        array $attemptedStrategies = [],
    ): self {
        return new self($version, true, '', $strategy, $attemptedStrategies);
    }

    /**
     * Create a failed version extraction result.
     *
     * @param string                      $errorMessage        Error message describing the failure
     * @param array<array<string, mixed>> $attemptedStrategies Information about attempted strategies
     *
     * @return self Failed result
     */
    public static function failed(string $errorMessage, array $attemptedStrategies = []): self
    {
        return new self(null, false, $errorMessage, null, $attemptedStrategies);
    }

    /**
     * Get the extracted version.
     *
     * @return Version|null Version if extraction was successful, null otherwise
     */
    public function getVersion(): ?Version
    {
        return $this->version;
    }

    /**
     * Check if version extraction was successful.
     *
     * @return bool True if version was extracted successfully
     */
    public function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }

    /**
     * Get error message if extraction failed.
     *
     * @return string Error message (empty string if successful)
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Get the strategy that successfully extracted the version.
     *
     * @return VersionStrategyInterface|null Successful strategy, null if extraction failed
     */
    public function getSuccessfulStrategy(): ?VersionStrategyInterface
    {
        return $this->successfulStrategy;
    }

    /**
     * Get information about all attempted extraction strategies.
     *
     * @return array<array<string, mixed>> Array of strategy attempt information
     */
    public function getAttemptedStrategies(): array
    {
        return $this->attemptedStrategies;
    }

    /**
     * Get the reliability score of the successful extraction.
     *
     * @return float|null Reliability score (0.0-1.0), null if extraction failed
     */
    public function getReliabilityScore(): ?float
    {
        return $this->successfulStrategy?->getReliabilityScore();
    }

    /**
     * Get a human-readable summary of the extraction result.
     *
     * @return string Summary string
     */
    public function getSummary(): string
    {
        if ($this->isSuccessful) {
            $reliability = $this->getReliabilityScore();
            $strategyName = $this->successfulStrategy?->getName() ?? 'unknown';

            return \sprintf(
                'Version %s extracted using %s strategy (reliability: %.1f%%)',
                $this->version?->toString() ?? 'unknown',
                $strategyName,
                ($reliability ?? 0.0) * 100,
            );
        }

        $attemptedCount = \count($this->attemptedStrategies);
        $supportedCount = \count(array_filter($this->attemptedStrategies, fn ($attempt): mixed => $attempt['supported'] ?? false));

        return \sprintf(
            'Version extraction failed: %s (attempted %d strategies, %d supported)',
            $this->errorMessage,
            $attemptedCount,
            $supportedCount,
        );
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
            'version' => $this->version ? [
                'major' => $this->version->getMajor(),
                'minor' => $this->version->getMinor(),
                'patch' => $this->version->getPatch(),
                'suffix' => $this->version->getSuffix(),
                'string' => $this->version->toString(),
            ] : null,
            'version_string' => $this->version?->toString(),
            'error_message' => $this->errorMessage,
            'successful_strategy' => $this->successfulStrategy?->getName(),
            'reliability_score' => $this->getReliabilityScore(),
            'attempted_strategies' => $this->attemptedStrategies,
            'summary' => $this->getSummary(),
        ];
    }
}
