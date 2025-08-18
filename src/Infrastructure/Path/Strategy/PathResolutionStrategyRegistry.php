<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception\NoCompatibleStrategyException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception\StrategyConflictException;
use Psr\Log\LoggerInterface;

/**
 * Registry for managing path resolution strategies with priority-based selection.
 */
final class PathResolutionStrategyRegistry
{
    /** @var array<string, PathResolutionStrategyInterface[]> */
    private array $strategiesByPathType = [];

    /** @var array<string, PathResolutionStrategyInterface> */
    private array $strategiesById = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        iterable $strategies = [],
    ) {
        foreach ($strategies as $strategy) {
            $this->registerStrategy($strategy);
        }
    }

    public function registerStrategy(PathResolutionStrategyInterface $strategy): void
    {
        $identifier = $strategy->getIdentifier();

        if (isset($this->strategiesById[$identifier])) {
            throw new StrategyConflictException("Strategy with identifier '{$identifier}' is already registered");
        }

        $this->strategiesById[$identifier] = $strategy;

        foreach ($strategy->getSupportedPathTypes() as $pathType) {
            $this->strategiesByPathType[$pathType->value][] = $strategy;
        }

        $this->sortStrategiesByPriority();

        $this->logger->debug('Registered path resolution strategy', [
            'identifier' => $identifier,
            'supported_path_types' => array_map(fn ($pt) => $pt->value, $strategy->getSupportedPathTypes()),
            'supported_installation_types' => array_map(fn ($it) => $it->value, $strategy->getSupportedInstallationTypes()),
        ]);
    }

    public function getStrategy(PathResolutionRequest $request): PathResolutionStrategyInterface
    {
        return $this->selectStrategy($request);
    }

    public function selectStrategy(PathResolutionRequest $request): PathResolutionStrategyInterface
    {
        $pathType = $request->pathType;
        $installationType = $request->installationType;

        $candidateStrategies = $this->strategiesByPathType[$pathType->value] ?? [];

        if (empty($candidateStrategies)) {
            throw new NoCompatibleStrategyException("No strategies registered for path type: {$pathType->value}");
        }

        // Filter by compatibility and capability
        $compatibleStrategies = array_filter(
            $candidateStrategies,
            fn ($strategy): bool => $this->isStrategyCompatible($strategy, $request),
        );

        if (empty($compatibleStrategies)) {
            throw new NoCompatibleStrategyException("No compatible strategies found for path type '{$pathType->value}' and installation type '{$installationType->value}'");
        }

        // Select strategy with highest priority
        $selectedStrategy = $this->selectHighestPriorityStrategy($compatibleStrategies, $pathType, $installationType);

        $this->logger->debug('Selected path resolution strategy', [
            'strategy' => $selectedStrategy->getIdentifier(),
            'path_type' => $pathType->value,
            'installation_type' => $installationType->value,
            'priority' => $selectedStrategy->getPriority($pathType, $installationType)->value,
            'candidates_count' => \count($candidateStrategies),
            'compatible_count' => \count($compatibleStrategies),
        ]);

        return $selectedStrategy;
    }

    public function getRegisteredStrategies(): array
    {
        return $this->strategiesById;
    }

    public function getStrategiesForPathType(PathTypeEnum $pathType): array
    {
        return $this->strategiesByPathType[$pathType->value] ?? [];
    }

    public function hasStrategyFor(PathTypeEnum $pathType, ?InstallationTypeEnum $installationType = null): bool
    {
        $strategies = $this->getStrategiesForPathType($pathType);

        if (null === $installationType) {
            return !empty($strategies);
        }

        foreach ($strategies as $strategy) {
            if (\in_array($installationType, $strategy->getSupportedInstallationTypes(), true)) {
                return true;
            }
        }

        return false;
    }

    public function getSupportedPathTypes(): array
    {
        return array_map(
            fn ($value) => PathTypeEnum::from($value),
            array_keys($this->strategiesByPathType),
        );
    }

    public function getCapabilities(): array
    {
        $capabilities = [
            'strategy_count' => \count($this->strategiesById),
            'supported_path_types' => array_map(fn ($pt) => $pt->value, $this->getSupportedPathTypes()),
            'supported_installation_types' => [],
            'strategy_details' => [],
        ];

        foreach ($this->strategiesById as $identifier => $strategy) {
            $pathTypes = array_map(fn ($pt) => $pt->value, $strategy->getSupportedPathTypes());
            $installationTypes = array_map(fn ($it) => $it->value, $strategy->getSupportedInstallationTypes());

            $capabilities['supported_path_types'] = array_unique(
                array_merge($capabilities['supported_path_types'], $pathTypes),
            );

            $capabilities['supported_installation_types'] = array_unique(
                array_merge($capabilities['supported_installation_types'], $installationTypes),
            );

            $capabilities['strategy_details'][$identifier] = [
                'path_types' => $pathTypes,
                'installation_types' => $installationTypes,
                'required_config' => $strategy->getRequiredConfiguration(),
            ];
        }

        return $capabilities;
    }

    private function isStrategyCompatible(
        PathResolutionStrategyInterface $strategy,
        PathResolutionRequest $request,
    ): bool {
        // Check basic installation type compatibility
        if (!\in_array($request->installationType, $strategy->getSupportedInstallationTypes(), true)) {
            return false;
        }

        // Check if strategy can handle the specific request
        if (!$strategy->canHandle($request)) {
            return false;
        }

        // Validate environment requirements
        $environmentErrors = $strategy->validateEnvironment();
        if (!empty($environmentErrors)) {
            $this->logger->warning('Strategy failed environment validation', [
                'strategy' => $strategy->getIdentifier(),
                'errors' => $environmentErrors,
            ]);

            return false;
        }

        return true;
    }

    private function selectHighestPriorityStrategy(
        array $strategies,
        PathTypeEnum $pathType,
        InstallationTypeEnum $installationType,
    ): PathResolutionStrategyInterface {
        usort($strategies, function ($a, $b) use ($pathType, $installationType): int {
            $priorityA = $a->getPriority($pathType, $installationType)->value;
            $priorityB = $b->getPriority($pathType, $installationType)->value;

            if ($priorityA === $priorityB) {
                // If priorities are equal, prefer by identifier for deterministic selection
                return strcmp($a->getIdentifier(), $b->getIdentifier());
            }

            return $priorityB <=> $priorityA; // Descending order (highest first)
        });

        return $strategies[0];
    }

    private function sortStrategiesByPriority(): void
    {
        foreach ($this->strategiesByPathType as &$strategies) {
            // Sort will be done dynamically based on installation type during selection
            // This ensures we always get the right priority order for the specific context
            // We need this method to maintain a side effect for strategy ordering
            ksort($this->strategiesByPathType);
        }
    }
}
