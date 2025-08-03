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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates TYPO3 version extraction from multiple sources
 * 
 * This class coordinates multiple version extraction strategies to determine
 * the TYPO3 version of an installation. It tries strategies in priority order
 * and returns the first successful result.
 */
final class VersionExtractor
{
    /**
     * @var array<VersionStrategyInterface> $strategies Version extraction strategies
     */
    private readonly array $strategies;

    /**
     * @param iterable<VersionStrategyInterface> $strategies Version extraction strategies
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(
        iterable $strategies,
        private readonly LoggerInterface $logger
    ) {
        // Convert iterable to array and sort strategies by priority (highest first)
        $strategiesArray = iterator_to_array($strategies);
        usort($strategiesArray, fn(VersionStrategyInterface $a, VersionStrategyInterface $b) => $b->getPriority() <=> $a->getPriority());
        $this->strategies = $strategiesArray;
    }

    /**
     * Extract TYPO3 version from installation path
     * 
     * Tries all available strategies in priority order until one succeeds.
     * 
     * @param string $installationPath Path to TYPO3 installation
     * @return VersionExtractionResult Result containing version and metadata
     */
    public function extractVersion(string $installationPath): VersionExtractionResult
    {
        $this->logger->debug('Starting version extraction', ['path' => $installationPath]);

        if (!is_dir($installationPath)) {
            return VersionExtractionResult::failed(
                'Installation path does not exist or is not a directory',
                []
            );
        }

        $attemptedStrategies = [];
        $supportedStrategies = [];

        foreach ($this->strategies as $strategy) {
            $strategyName = $strategy->getName();
            $this->logger->debug('Evaluating version strategy', ['strategy' => $strategyName]);

            // Check if strategy supports this installation
            if (!$strategy->supports($installationPath)) {
                $this->logger->debug('Strategy does not support installation', ['strategy' => $strategyName]);
                $attemptedStrategies[] = [
                    'strategy' => $strategyName,
                    'supported' => false,
                    'reason' => 'Required files not found: ' . implode(', ', $strategy->getRequiredFiles())
                ];
                continue;
            }

            $supportedStrategies[] = $strategyName;
            $attemptedStrategies[] = [
                'strategy' => $strategyName,
                'supported' => true,
                'priority' => $strategy->getPriority(),
                'reliability' => $strategy->getReliabilityScore()
            ];

            try {
                // Attempt version extraction
                $this->logger->debug('Attempting version extraction', ['strategy' => $strategyName]);
                $version = $strategy->extractVersion($installationPath);

                if ($version !== null) {
                    $this->logger->info('Version extracted successfully', [
                        'strategy' => $strategyName,
                        'version' => $version->toString(),
                        'reliability' => $strategy->getReliabilityScore()
                    ]);

                    return VersionExtractionResult::success(
                        $version,
                        $strategy,
                        $attemptedStrategies
                    );
                }

                $this->logger->debug('Strategy returned null version', ['strategy' => $strategyName]);
                $attemptedStrategies[array_key_last($attemptedStrategies)]['result'] = 'no_version_found';

            } catch (\Throwable $e) {
                $this->logger->warning('Version extraction strategy failed', [
                    'strategy' => $strategyName,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);

                $attemptedStrategies[array_key_last($attemptedStrategies)]['result'] = 'error';
                $attemptedStrategies[array_key_last($attemptedStrategies)]['error'] = $e->getMessage();
            }
        }

        // No strategy succeeded
        $errorMessage = empty($supportedStrategies) 
            ? 'No version extraction strategies supported this installation'
            : sprintf('All supported strategies failed to extract version: %s', implode(', ', $supportedStrategies));

        $this->logger->warning('Version extraction failed', [
            'path' => $installationPath,
            'attempted_strategies' => count($attemptedStrategies),
            'supported_strategies' => count($supportedStrategies)
        ]);

        return VersionExtractionResult::failed($errorMessage, $attemptedStrategies);
    }

    /**
     * Get all available version extraction strategies
     * 
     * @return array<VersionStrategyInterface> Array of strategies ordered by priority
     */
    public function getStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * Get strategies that support the given installation path
     * 
     * @param string $installationPath Path to check
     * @return array<VersionStrategyInterface> Supported strategies
     */
    public function getSupportedStrategies(string $installationPath): array
    {
        return array_filter(
            $this->strategies,
            fn(VersionStrategyInterface $strategy) => $strategy->supports($installationPath)
        );
    }

    /**
     * Check if any strategy can handle the given installation path
     * 
     * @param string $installationPath Path to check
     * @return bool True if at least one strategy supports the path
     */
    public function canExtractVersion(string $installationPath): bool
    {
        return !empty($this->getSupportedStrategies($installationPath));
    }
}