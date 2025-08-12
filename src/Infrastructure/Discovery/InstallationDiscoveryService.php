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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use Psr\Log\LoggerInterface;

/**
 * Main service for discovering TYPO3 installations.
 *
 * This service coordinates multiple detection strategies to identify and analyze
 * TYPO3 installations at given filesystem paths. It manages the detection process,
 * handles validation, and provides comprehensive installation discovery results.
 */
final class InstallationDiscoveryService implements InstallationDiscoveryServiceInterface
{
    /**
     * @var array<DetectionStrategyInterface> Available detection strategies
     */
    private readonly array $detectionStrategies;

    /**
     * @param iterable<DetectionStrategyInterface> $detectionStrategies           Available detection strategies
     * @param iterable<ValidationRuleInterface>    $validationRules               Installation validation rules
     * @param ConfigurationDiscoveryService|null   $configurationDiscoveryService Configuration discovery service
     * @param LoggerInterface                      $logger                        Logger instance
     * @param ConfigurationService                 $configService                 Configuration service for cache settings
     * @param CacheService                         $cacheService                  Cache service for result caching
     */
    public function __construct(
        iterable $detectionStrategies,
        private readonly iterable $validationRules,
        private readonly ?ConfigurationDiscoveryService $configurationDiscoveryService,
        private readonly LoggerInterface $logger,
        private readonly ConfigurationService $configService,
        private readonly CacheService $cacheService,
    ) {
        // Convert iterables to arrays and sort detection strategies by priority (highest first)
        $strategiesArray = iterator_to_array($detectionStrategies);
        usort($strategiesArray, fn (DetectionStrategyInterface $a, DetectionStrategyInterface $b): int => $b->getPriority() <=> $a->getPriority());
        $this->detectionStrategies = $strategiesArray;
    }

    /**
     * Discover TYPO3 installation at the given path.
     *
     * @param string $path                 Filesystem path to analyze
     * @param bool   $validateInstallation Whether to run validation rules
     *
     * @return InstallationDiscoveryResult Discovery result
     */
    public function discoverInstallation(string $path, bool $validateInstallation = true): InstallationDiscoveryResult
    {
        $this->logger->info('Starting installation discovery', ['path' => $path]);

        if (!is_dir($path)) {
            return InstallationDiscoveryResult::failed(
                'Path does not exist or is not a directory',
                [],
            );
        }

        // Check cache if enabled
        if ($this->configService->isResultCacheEnabled()) {
            $cacheKey = $this->cacheService->generateKey('installation_discovery', $path, ['validate' => $validateInstallation]);
            $cachedResult = $this->cacheService->get($cacheKey);

            if (null !== $cachedResult) {
                $this->logger->debug('Found cached installation discovery result', ['cache_key' => $cacheKey]);

                return $this->deserializeResult($cachedResult);
            }
        }

        $attemptedStrategies = [];

        // Try each detection strategy in priority order
        foreach ($this->detectionStrategies as $strategy) {
            $strategyName = $strategy->getName();
            $this->logger->debug('Evaluating detection strategy', ['strategy' => $strategyName]);

            // Quick pre-check using required indicators
            if (!$this->hasRequiredIndicators($path, $strategy)) {
                $this->logger->debug('Required indicators not found', [
                    'strategy' => $strategyName,
                    'indicators' => $strategy->getRequiredIndicators(),
                ]);
                $attemptedStrategies[] = [
                    'strategy' => $strategyName,
                    'supported' => false,
                    'reason' => 'Required indicators not found: ' . implode(', ', $strategy->getRequiredIndicators()),
                ];
                continue;
            }

            // Check if strategy supports this path
            if (!$strategy->supports($path)) {
                $this->logger->debug('Strategy does not support path', ['strategy' => $strategyName]);
                $attemptedStrategies[] = [
                    'strategy' => $strategyName,
                    'supported' => false,
                    'reason' => 'Strategy-specific support check failed',
                ];
                continue;
            }

            try {
                // Attempt installation detection
                $this->logger->debug('Attempting installation detection', ['strategy' => $strategyName]);
                $installation = $strategy->detect($path);

                if (null !== $installation) {
                    $this->logger->info('Installation detected successfully', [
                        'strategy' => $strategyName,
                        'version' => $installation->getVersion()->toString(),
                        'mode' => $installation->getMode()->value ?? 'unknown',
                    ]);

                    $attemptedStrategies[] = [
                        'strategy' => $strategyName,
                        'supported' => true,
                        'result' => 'success',
                        'priority' => $strategy->getPriority(),
                    ];

                    // Discover configuration files if configuration discovery service is available
                    if (null !== $this->configurationDiscoveryService) {
                        try {
                            $installation = $this->configurationDiscoveryService->discoverConfiguration($installation);
                        } catch (\Throwable $e) {
                            $this->logger->warning('Configuration discovery failed during installation discovery', [
                                'installation_path' => $installation->getPath(),
                                'exception_message' => $e->getMessage(),
                            ]);
                        }
                    }

                    // Run validation if requested
                    $validationIssues = [];
                    if ($validateInstallation) {
                        $validationIssues = $this->validateInstallation($installation);
                        $installation->setValidationErrors($validationIssues);
                    }

                    $result = InstallationDiscoveryResult::success(
                        $installation,
                        $strategy,
                        $validationIssues,
                        $attemptedStrategies,
                    );

                    // Cache the result if enabled
                    if ($this->configService->isResultCacheEnabled()) {
                        $cacheKey = $this->cacheService->generateKey('installation_discovery', $path, ['validate' => $validateInstallation]);
                        $this->cacheService->set($cacheKey, $this->serializeResult($result));
                    }

                    return $result;
                }

                $this->logger->debug('Strategy returned null installation', ['strategy' => $strategyName]);
                $attemptedStrategies[] = [
                    'strategy' => $strategyName,
                    'supported' => true,
                    'result' => 'no_installation_found',
                    'priority' => $strategy->getPriority(),
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('Detection strategy failed', [
                    'strategy' => $strategyName,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $attemptedStrategies[] = [
                    'strategy' => $strategyName,
                    'supported' => true,
                    'result' => 'error',
                    'error' => $e->getMessage(),
                    'priority' => $strategy->getPriority(),
                ];
            }
        }

        // No strategy succeeded
        $supportedStrategies = array_filter($attemptedStrategies, fn ($attempt): bool => $attempt['supported']);
        $errorMessage = empty($supportedStrategies)
            ? 'No detection strategies found applicable indicators for this path'
            : \sprintf('All %d supported strategies failed to detect a TYPO3 installation', \count($supportedStrategies));

        $this->logger->warning('Installation discovery failed', [
            'path' => $path,
            'attempted_strategies' => \count($attemptedStrategies),
            'supported_strategies' => \count($supportedStrategies),
        ]);

        return InstallationDiscoveryResult::failed($errorMessage, $attemptedStrategies);
    }

    /**
     * Get all available detection strategies.
     *
     * @return array<DetectionStrategyInterface> Detection strategies ordered by priority
     */
    public function getDetectionStrategies(): array
    {
        return $this->detectionStrategies;
    }

    /**
     * Get strategies that could potentially handle the given path.
     *
     * This performs a lightweight check based on required indicators only.
     *
     * @param string $path Path to check
     *
     * @return array<DetectionStrategyInterface> Potentially applicable strategies
     */
    public function getApplicableStrategies(string $path): array
    {
        return array_filter(
            $this->detectionStrategies,
            fn (DetectionStrategyInterface $strategy): bool => $this->hasRequiredIndicators($path, $strategy),
        );
    }

    /**
     * Get strategies that fully support the given path.
     *
     * This performs the full support check including strategy-specific validation.
     *
     * @param string $path Path to check
     *
     * @return array<DetectionStrategyInterface> Supported strategies
     */
    public function getSupportedStrategies(string $path): array
    {
        return array_filter(
            $this->detectionStrategies,
            fn (DetectionStrategyInterface $strategy): bool => $this->hasRequiredIndicators($path, $strategy) && $strategy->supports($path),
        );
    }

    /**
     * Check if installation discovery is possible for the given path.
     *
     * @param string $path Path to check
     *
     * @return bool True if at least one strategy can handle the path
     */
    public function canDiscoverInstallation(string $path): bool
    {
        return !empty($this->getApplicableStrategies($path));
    }

    /**
     * Get all validation rules.
     *
     * @return array<ValidationRuleInterface> Validation rules
     */
    public function getValidationRules(): array
    {
        return iterator_to_array($this->validationRules);
    }

    /**
     * Validate an installation using all applicable validation rules.
     *
     * @param Installation $installation Installation to validate
     *
     * @return array<ValidationIssue> Array of validation issues
     */
    public function validateInstallation(Installation $installation): array
    {
        $this->logger->debug('Starting installation validation', [
            'path' => $installation->getPath(),
            'version' => $installation->getVersion()->toString(),
        ]);

        $allIssues = [];

        foreach ($this->validationRules as $rule) {
            if (!$rule->appliesTo($installation)) {
                $this->logger->debug('Validation rule does not apply', [
                    'rule' => $rule->getName(),
                    'installation' => $installation->getPath(),
                ]);
                continue;
            }

            try {
                $this->logger->debug('Running validation rule', ['rule' => $rule->getName()]);
                $issues = $rule->validate($installation);

                if (!empty($issues)) {
                    $this->logger->debug('Validation rule found issues', [
                        'rule' => $rule->getName(),
                        'issues_count' => \count($issues),
                    ]);
                    $allIssues = array_merge($allIssues, $issues);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Validation rule failed', [
                    'rule' => $rule->getName(),
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                // Create an error issue for the failed validation rule
                $allIssues[] = new ValidationIssue(
                    $rule->getName(),
                    ValidationSeverity::ERROR,
                    \sprintf('Validation rule failed: %s', $e->getMessage()),
                    $rule->getCategory(),
                    ['exception' => $e->getMessage()],
                    [$installation->getPath()],
                    ['Check the installation structure and fix any obvious issues'],
                );
            }
        }

        $this->logger->info('Installation validation completed', [
            'installation' => $installation->getPath(),
            'total_issues' => \count($allIssues),
            'blocking_issues' => \count(array_filter($allIssues, fn (ValidationIssue $issue): bool => $issue->isBlockingAnalysis())),
        ]);

        return $allIssues;
    }

    /**
     * Check if path has required indicators for a detection strategy.
     *
     * @param string                     $path     Path to check
     * @param DetectionStrategyInterface $strategy Strategy to check
     *
     * @return bool True if all required indicators are present
     */
    private function hasRequiredIndicators(string $path, DetectionStrategyInterface $strategy): bool
    {
        $requiredIndicators = $strategy->getRequiredIndicators();

        if (empty($requiredIndicators)) {
            return true; // No specific requirements
        }

        foreach ($requiredIndicators as $indicator) {
            $indicatorPath = $path . '/' . ltrim($indicator, '/');

            if (!file_exists($indicatorPath)) {
                return false;
            }
        }

        return true;
    }

    private function serializeResult(InstallationDiscoveryResult $result): array
    {
        $data = $result->toArray();
        $data['cached_at'] = time();

        return $data;
    }

    private function deserializeResult(array $data): InstallationDiscoveryResult
    {
        $this->logger->info('Using cached installation discovery result', [
            'cached_at' => $data['cached_at'] ?? 'unknown',
        ]);

        return InstallationDiscoveryResult::fromArray($data);
    }
}
