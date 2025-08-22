# ClearCacheCommand Feature Specification

**Feature**: ClearCacheCommand
**Status**: Specification Complete - Ready for Implementation
**Priority**: Medium - Operational Enhancement
**Estimated Effort**: 12-16 hours
**Date**: August 22, 2025

## Problem Statement

The TYPO3 Upgrade Analyzer accumulates various types of cache data during analysis operations to improve performance on subsequent runs. Currently, there is no standardized way to manage these caches, leading to several operational challenges:

**Current Cache Types in System:**
- Path resolution cache (`PathResolutionCacheInterface` - multi-layer caching in `var/cache/`)
- Analysis result cache (`CacheService` - JSON files in `var/results/`)
- Version detection cache (from `ComposerVersionStrategy`)
- Extension discovery cache (temporary metadata)
- Configuration parsing cache (when implemented)

**Current Problems:**
- **Manual Cache Management**: Users must manually delete cache directories to force fresh analysis
- **Debugging Complexity**: Cached results can mask issues during development and troubleshooting
- **Storage Accumulation**: Caches grow over time without automatic cleanup mechanisms
- **No Selective Clearing**: Cannot clear specific cache types while preserving others
- **Development Workflow**: No easy way to ensure clean state during analyzer development

## Solution Goals

1. **Unified Cache Management**: Single command to manage all cache types in the system
2. **Selective Operations**: Clear all caches or specific cache types based on user needs
3. **Safe Operations**: Dry-run capability to preview actions before execution
4. **Operational Visibility**: Clear output showing what was cleared and space reclaimed
5. **Development Support**: Easy cache clearing during development and testing workflows
6. **Backward Compatibility**: Works with existing cache infrastructure without breaking changes

## Architecture Overview

### Core Design Principles

1. **Service-Oriented Design**: Leverage existing cache services rather than bypassing them
2. **Strategy Pattern**: Pluggable cache clearing strategies for different cache types
3. **Safe-by-Default**: Conservative approach with clear user feedback
4. **Clean Architecture**: Command layer orchestrates infrastructure services
5. **Minimal Dependencies**: Use existing project infrastructure and patterns

### Key Architectural Components

#### Application Layer
- `ClearCacheCommand` - Main console command implementation
- Cache type enumeration and validation
- User interface and feedback management

#### Infrastructure Layer
- `CacheCoordinator` - Orchestrates cache clearing across different services
- `CacheClearingStrategy` - Strategy interface for different cache types
- Individual clearing strategies for each cache type

#### Existing Integration Points
- `CacheService` - Already provides `clear()` method for analysis results
- `PathResolutionCacheInterface` - Has `clear()` method for path resolution
- Symfony Console - Consistent command interface and output formatting

## Technical Specification

### Core Command Implementation

#### ClearCacheCommand

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Application\Command;

use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheCoordinator;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheTypeEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cache:clear',
    description: 'Clear application caches'
)]
class ClearCacheCommand extends Command
{
    public function __construct(
        private readonly CacheCoordinator $cacheCoordinator,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Specific cache type(s) to clear (available: ' . implode(', ', CacheTypeEnum::getAvailableTypes()) . ')'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be cleared without actually clearing'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force clearing even if cache files are locked or corrupted'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $requestedTypes = $input->getOption('type');
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        $io->title('TYPO3 Upgrade Analyzer - Cache Management');

        // Determine cache types to clear
        $cacheTypes = $this->determineCacheTypes($requestedTypes, $io);
        if (empty($cacheTypes)) {
            return Command::FAILURE;
        }

        // Preview mode
        if ($dryRun) {
            return $this->executeDryRun($cacheTypes, $io);
        }

        // Actual clearing
        return $this->executeClearOperation($cacheTypes, $force, $io);
    }

    /**
     * @param string[]|null $requestedTypes
     * @return CacheTypeEnum[]
     */
    private function determineCacheTypes(?array $requestedTypes, SymfonyStyle $io): array
    {
        if (empty($requestedTypes)) {
            $io->note('No specific types requested - will clear all cache types');
            return CacheTypeEnum::cases();
        }

        $validTypes = [];
        $invalidTypes = [];

        foreach ($requestedTypes as $type) {
            try {
                $validTypes[] = CacheTypeEnum::from($type);
            } catch (\ValueError) {
                $invalidTypes[] = $type;
            }
        }

        if (!empty($invalidTypes)) {
            $io->error(sprintf(
                'Invalid cache type(s): %s. Available types: %s',
                implode(', ', $invalidTypes),
                implode(', ', CacheTypeEnum::getAvailableTypes())
            ));
            return [];
        }

        return $validTypes;
    }

    /**
     * @param CacheTypeEnum[] $cacheTypes
     */
    private function executeDryRun(array $cacheTypes, SymfonyStyle $io): int
    {
        $io->section('Cache Clear Preview (Dry Run)');

        $totalSize = 0;
        $totalFiles = 0;

        foreach ($cacheTypes as $cacheType) {
            $stats = $this->cacheCoordinator->getCacheStats($cacheType);

            $io->definitionList(
                [sprintf('%s Cache', $cacheType->getDisplayName())] => [
                    'Files' => $stats->fileCount,
                    'Size' => $this->formatBytes($stats->sizeBytes),
                    'Location' => $stats->path,
                    'Status' => $stats->isAccessible ? 'Accessible' : 'Locked/Corrupted'
                ]
            );

            $totalSize += $stats->sizeBytes;
            $totalFiles += $stats->fileCount;
        }

        $io->note(sprintf(
            'Total: %d files (%s) would be cleared',
            $totalFiles,
            $this->formatBytes($totalSize)
        ));

        $io->info('Use --force to clear locked/corrupted caches');
        return Command::SUCCESS;
    }

    /**
     * @param CacheTypeEnum[] $cacheTypes
     */
    private function executeClearOperation(array $cacheTypes, bool $force, SymfonyStyle $io): int
    {
        $io->section('Clearing Caches');

        $results = [];
        $totalReclaimed = 0;

        foreach ($cacheTypes as $cacheType) {
            $io->text(sprintf('Clearing %s cache...', $cacheType->getDisplayName()));

            try {
                $result = $this->cacheCoordinator->clear($cacheType, $force);
                $results[$cacheType->value] = $result;

                if ($result->isSuccessful()) {
                    $io->text(sprintf(
                        '  ✓ Cleared %d files (%s)',
                        $result->getClearedCount(),
                        $this->formatBytes($result->getReclaimedBytes())
                    ));
                    $totalReclaimed += $result->getReclaimedBytes();
                } else {
                    $io->text(sprintf('  ✗ Failed: %s', $result->getError()));
                }
            } catch (\Exception $e) {
                $io->text(sprintf('  ✗ Exception: %s', $e->getMessage()));
                $this->logger->error('Cache clearing failed', [
                    'cache_type' => $cacheType->value,
                    'error' => $e->getMessage()
                ]);
                $results[$cacheType->value] = null;
            }
        }

        // Summary
        $successful = array_filter($results, fn($result) => $result?->isSuccessful() ?? false);
        $failed = array_filter($results, fn($result) => !($result?->isSuccessful() ?? false));

        if (!empty($successful)) {
            $io->success(sprintf(
                'Successfully cleared %d cache type(s), reclaimed %s',
                count($successful),
                $this->formatBytes($totalReclaimed)
            ));
        }

        if (!empty($failed)) {
            $io->warning(sprintf(
                'Failed to clear %d cache type(s): %s',
                count($failed),
                implode(', ', array_keys($failed))
            ));

            if (!$force) {
                $io->note('Use --force to attempt clearing locked/corrupted caches');
            }
        }

        return empty($failed) ? Command::SUCCESS : Command::FAILURE;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor(log($bytes, 1024));

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}
```

#### Supporting Infrastructure Components

#### CacheTypeEnum

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Cache;

/**
 * Enumeration of cache types supported by the system.
 */
enum CacheTypeEnum: string
{
    case ANALYSIS_RESULTS = 'analysis_results';
    case PATH_RESOLUTION = 'path_resolution';
    case VERSION_DETECTION = 'version_detection';
    case EXTENSION_DISCOVERY = 'extension_discovery';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::ANALYSIS_RESULTS => 'Analysis Results',
            self::PATH_RESOLUTION => 'Path Resolution',
            self::VERSION_DETECTION => 'Version Detection',
            self::EXTENSION_DISCOVERY => 'Extension Discovery',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::ANALYSIS_RESULTS => 'Cached analysis results from previous runs',
            self::PATH_RESOLUTION => 'Cached path resolution data for extensions and files',
            self::VERSION_DETECTION => 'Cached TYPO3 version detection results',
            self::EXTENSION_DISCOVERY => 'Cached extension metadata and discovery results',
        };
    }

    /**
     * @return string[]
     */
    public static function getAvailableTypes(): array
    {
        return array_map(fn(self $case) => $case->value, self::cases());
    }
}
```

#### CacheCoordinator

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Cache;

use Psr\Log\LoggerInterface;

/**
 * Coordinates cache clearing operations across different cache types.
 */
class CacheCoordinator
{
    /**
     * @param CacheClearingStrategyInterface[] $strategies
     */
    public function __construct(
        private readonly array $strategies,
        private readonly LoggerInterface $logger
    ) {
    }

    public function clear(CacheTypeEnum $type, bool $force = false): CacheClearingResult
    {
        $strategy = $this->getStrategyForType($type);

        if (!$strategy) {
            return CacheClearingResult::failed(
                sprintf('No clearing strategy available for cache type: %s', $type->value)
            );
        }

        $this->logger->info('Clearing cache', [
            'type' => $type->value,
            'force' => $force,
            'strategy' => $strategy::class
        ]);

        return $strategy->clear($force);
    }

    public function getCacheStats(CacheTypeEnum $type): CacheStats
    {
        $strategy = $this->getStrategyForType($type);

        if (!$strategy) {
            return new CacheStats(
                path: 'Unknown',
                fileCount: 0,
                sizeBytes: 0,
                isAccessible: false
            );
        }

        return $strategy->getStats();
    }

    /**
     * @return CacheTypeEnum[]
     */
    public function getAvailableCacheTypes(): array
    {
        return array_map(
            fn(CacheClearingStrategyInterface $strategy) => $strategy->getSupportedType(),
            $this->strategies
        );
    }

    private function getStrategyForType(CacheTypeEnum $type): ?CacheClearingStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->getSupportedType() === $type) {
                return $strategy;
            }
        }

        return null;
    }
}
```

#### CacheClearingStrategyInterface

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Cache;

/**
 * Strategy interface for clearing specific cache types.
 */
interface CacheClearingStrategyInterface
{
    /**
     * Clear the cache managed by this strategy.
     */
    public function clear(bool $force = false): CacheClearingResult;

    /**
     * Get statistics about the cache.
     */
    public function getStats(): CacheStats;

    /**
     * Get the cache type this strategy handles.
     */
    public function getSupportedType(): CacheTypeEnum;

    /**
     * Check if the strategy can operate (permissions, directories exist, etc.).
     */
    public function isOperational(): bool;
}
```

#### Supporting Value Objects

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Cache;

/**
 * Result of a cache clearing operation.
 */
readonly class CacheClearingResult
{
    private function __construct(
        public bool $successful,
        public int $clearedCount,
        public int $reclaimedBytes,
        public ?string $error = null,
        public array $warnings = []
    ) {
    }

    public static function successful(int $clearedCount, int $reclaimedBytes, array $warnings = []): self
    {
        return new self(true, $clearedCount, $reclaimedBytes, null, $warnings);
    }

    public static function failed(string $error): self
    {
        return new self(false, 0, 0, $error);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getClearedCount(): int
    {
        return $this->clearedCount;
    }

    public function getReclaimedBytes(): int
    {
        return $this->reclaimedBytes;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }
}

/**
 * Cache statistics for reporting.
 */
readonly class CacheStats
{
    public function __construct(
        public string $path,
        public int $fileCount,
        public int $sizeBytes,
        public bool $isAccessible
    ) {
    }
}
```

#### Concrete Strategy Implementations

#### AnalysisResultsCacheClearingStrategy

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Cache\Strategy;

use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheClearingResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheClearingStrategyInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheStats;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheTypeEnum;

/**
 * Strategy for clearing analysis results cache through existing CacheService.
 */
class AnalysisResultsCacheClearingStrategy implements CacheClearingStrategyInterface
{
    public function __construct(
        private readonly CacheService $cacheService,
        private readonly string $projectRoot
    ) {
    }

    public function clear(bool $force = false): CacheClearingResult
    {
        $stats = $this->getStats();

        try {
            $success = $this->cacheService->clear();

            if ($success) {
                return CacheClearingResult::successful(
                    $stats->fileCount,
                    $stats->sizeBytes
                );
            }

            return CacheClearingResult::failed('Cache service reported failure');
        } catch (\Exception $e) {
            return CacheClearingResult::failed($e->getMessage());
        }
    }

    public function getStats(): CacheStats
    {
        $cacheDir = $this->projectRoot . '/var/results';

        if (!is_dir($cacheDir)) {
            return new CacheStats($cacheDir, 0, 0, true);
        }

        $files = glob($cacheDir . '/*.json');
        if (false === $files) {
            return new CacheStats($cacheDir, 0, 0, false);
        }

        $totalSize = 0;
        foreach ($files as $file) {
            $size = filesize($file);
            if (false !== $size) {
                $totalSize += $size;
            }
        }

        return new CacheStats(
            path: $cacheDir,
            fileCount: count($files),
            sizeBytes: $totalSize,
            isAccessible: is_readable($cacheDir) && is_writable($cacheDir)
        );
    }

    public function getSupportedType(): CacheTypeEnum
    {
        return CacheTypeEnum::ANALYSIS_RESULTS;
    }

    public function isOperational(): bool
    {
        return true; // CacheService handles all operational concerns
    }
}
```

#### PathResolutionCacheClearingStrategy

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Cache\Strategy;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Cache\PathResolutionCacheInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheClearingResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheClearingStrategyInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheStats;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheTypeEnum;

/**
 * Strategy for clearing path resolution cache.
 */
class PathResolutionCacheClearingStrategy implements CacheClearingStrategyInterface
{
    public function __construct(
        private readonly PathResolutionCacheInterface $pathCache,
        private readonly string $projectRoot
    ) {
    }

    public function clear(bool $force = false): CacheClearingResult
    {
        $stats = $this->getStats();

        try {
            $this->pathCache->clear();

            return CacheClearingResult::successful(
                $stats->fileCount,
                $stats->sizeBytes
            );
        } catch (\Exception $e) {
            return CacheClearingResult::failed($e->getMessage());
        }
    }

    public function getStats(): CacheStats
    {
        $cacheDir = $this->projectRoot . '/var/cache';

        if (!is_dir($cacheDir)) {
            return new CacheStats($cacheDir, 0, 0, true);
        }

        // Estimate cache size - PathResolutionCache manages its own internal structure
        $pathCacheStats = $this->pathCache->getStats();

        return new CacheStats(
            path: $cacheDir,
            fileCount: $pathCacheStats->getEntryCount(),
            sizeBytes: $pathCacheStats->getEstimatedSize(),
            isAccessible: is_readable($cacheDir) && is_writable($cacheDir)
        );
    }

    public function getSupportedType(): CacheTypeEnum
    {
        return CacheTypeEnum::PATH_RESOLUTION;
    }

    public function isOperational(): bool
    {
        return $this->pathCache !== null;
    }
}
```

## Integration Points

### Application Integration

The command integrates seamlessly with the existing application structure:

```php
// In AnalyzerApplication constructor
$commands = [
    $this->container->get(AnalyzeCommand::class),
    $this->container->get(InitConfigCommand::class),
    $this->container->get(ListAnalyzersCommand::class),
    $this->container->get(ListExtensionsCommand::class),
    $this->container->get(ClearCacheCommand::class), // New addition
];
```

### Service Configuration

```yaml
# config/services.yaml
services:
  # Cache Coordinator and Strategies
  CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheCoordinator:
    arguments:
      $strategies: !tagged_iterator cache_clearing_strategy

  # Cache Clearing Strategies
  CPSIT\UpgradeAnalyzer\Infrastructure\Cache\Strategy\AnalysisResultsCacheClearingStrategy:
    tags: ['cache_clearing_strategy']

  CPSIT\UpgradeAnalyzer\Infrastructure\Cache\Strategy\PathResolutionCacheClearingStrategy:
    tags: ['cache_clearing_strategy']

  # Command
  CPSIT\UpgradeAnalyzer\Application\Command\ClearCacheCommand:
    public: true
```

### Command Usage Examples

```bash
# Clear all caches
./bin/typo3-analyzer cache:clear

# Preview what would be cleared
./bin/typo3-analyzer cache:clear --dry-run

# Clear specific cache types
./bin/typo3-analyzer cache:clear --type=analysis_results --type=path_resolution

# Force clear including locked/corrupted caches
./bin/typo3-analyzer cache:clear --force

# Clear specific type with preview
./bin/typo3-analyzer cache:clear --type=analysis_results --dry-run
```

## Implementation Benefits

### Operational Benefits
- **Consistent Interface**: Single command for all cache management operations
- **Safe Operations**: Dry-run mode prevents accidental data loss
- **Clear Feedback**: Detailed output shows exactly what was cleared and space reclaimed
- **Selective Control**: Clear only needed cache types without affecting others

### Development Benefits
- **Testing Support**: Easy cache clearing between test runs
- **Debugging Aid**: Force fresh analysis when troubleshooting issues
- **Performance Monitoring**: Track cache sizes and effectiveness
- **CI/CD Integration**: Scriptable cache management for automated workflows

### Maintenance Benefits
- **Extensible Design**: Easy to add new cache types through strategy pattern
- **Service Integration**: Leverages existing cache services without duplication
- **Error Handling**: Comprehensive error reporting and recovery options
- **Logging Integration**: Full operation logging for audit and debugging

## Implementation Plan

### Phase 1: Core Command and Infrastructure (6-8 hours)
- Create `ClearCacheCommand` with basic functionality
- Implement `CacheTypeEnum` and core value objects
- Create `CacheCoordinator` service
- Set up basic strategy interface

### Phase 2: Strategy Implementation (4-6 hours)
- Implement `AnalysisResultsCacheClearingStrategy`
- Implement `PathResolutionCacheClearingStrategy`
- Add comprehensive error handling and logging
- Create dry-run functionality

### Phase 3: Integration and Testing (2-4 hours)
- Register command in `AnalyzerApplication`
- Configure dependency injection services
- Add comprehensive unit and integration tests
- Update documentation and usage examples

## Success Criteria

### Functional Requirements
- Command clears all supported cache types successfully
- Selective cache clearing works for individual types
- Dry-run mode shows accurate preview without making changes
- Force mode handles locked/corrupted cache files appropriately
- Clear feedback shows files cleared and space reclaimed

### Quality Requirements
- 90%+ test coverage for all command and strategy components
- Integration tests with real cache scenarios
- Error handling covers all failure modes gracefully
- Performance: Command completes cache clearing in <10 seconds for typical installations
- Memory usage remains under 64MB during operation

### Integration Requirements
- Works seamlessly with existing `CacheService`
- Integrates cleanly with `PathResolutionCacheInterface`
- Maintains compatibility with current cache infrastructure
- Follows project patterns for commands and dependency injection
- Supports future cache types through extensible strategy pattern

This feature provides essential cache management capabilities while remaining focused on practical operational needs rather than over-engineered complexity.
