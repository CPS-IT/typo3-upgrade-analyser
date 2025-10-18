# PathResolutionService Feature Specification

|--------------|-------------------------------------------------|
| **Status**   | Specification Complete, Implementation Complete |
| **Priority** | High - Critical Refactoring                     |

## Problem Statement

The codebase currently contains ~200 lines of duplicated path resolution logic scattered across multiple analyzers and
services. This duplication creates maintenance overhead, inconsistent error handling, and makes the system fragile to
changes in TYPO3 installation structures.

**Current Duplication Locations:**

- `Typo3RectorAnalyzer::getExtensionPath()` (45 lines)
- `ExtensionDiscoveryService::resolvePaths()` (20 lines)
- `RectorConfigGenerator::getExtensionPath()` (35 lines)
- `FractorAnalyzer::getExtensionPath()` (40 lines)
- Additional path resolution methods scattered across other components

**Current Problems:**

- **Inconsistent Error Handling**: Different components handle path resolution failures differently
- **Duplicate Logic**: Same resolution logic implemented multiple times with variations
- **Testing Complexity**: Path resolution tested separately in each consumer
- **Configuration Fragmentation**: Path configuration scattered across multiple services
- **Installation Type Confusion**: Inconsistent handling of Composer vs. legacy installations

## Solution Goals

1. **Eliminate Code Duplication**: Consolidate all path resolution logic into a single, testable service
2. **Improve Consistency**: Ensure uniform path resolution behavior across all consumers
3. **Enhance Testability**: Centralize path logic for easier unit testing and mocking
4. **Support Multiple Installation Types**: Handle Composer-based, legacy, and custom installations
5. **Future-Proof Design**: Create extensible system for additional path types
6. **Maintain Backward Compatibility**: Safe integration without breaking existing functionality

## Architecture Overview

### Core Design Principles

1. **Strong Typing**: All interfaces use strongly typed objects instead of mixed types
2. **Immutability**: All value objects are immutable with validation
3. **Separation of Concerns**: Clear boundaries between layers
4. **Extensibility**: Plugin architecture for strategies and path types
5. **Performance**: Built-in caching and optimization
6. **Error Recovery**: Comprehensive error handling with fallback strategies

### Key Architectural Components

#### Core Services

- `PathResolutionService` - Main service interface
- `PathResolutionCoordinator` - Orchestration and caching
- `PathResolutionStrategyRegistry` - Strategy management

#### Value Objects & Transfer Objects

- `PathResolutionRequest` - Strongly typed requests
- `PathResolutionResponse` - Rich response objects
- `ExtensionIdentifier` - Clean data transfer
- `PathConfiguration` - Configuration encapsulation

#### Strategy System

- `PathResolutionStrategyInterface` - Strategy contract
- `ComposerExtensionPathStrategy` - Composer-specific resolution
- `LegacyExtensionPathStrategy` - Legacy installation support
- `StrategyPriorityEnum` - Priority-based resolution

#### Error Handling

- `PathResolutionException` - Base exception
- `ExtensionNotFoundPathException` - Specific failures
- `ErrorRecoveryManager` - Recovery strategies
- `PathResolutionValidator` - Early validation

#### Performance & Caching

- `MultiLayerPathResolutionCache` - Persistent + memory caching
- `BatchPathResolutionProcessor` - Efficient batch processing
- Performance monitoring and resource management

## Technical Specification

### Core Value Objects and Data Transfer Objects

#### PathResolutionRequest

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;

/**
 * Immutable data transfer object for path resolution requests.
 * Replaces mixed types with strongly typed, validated objects.
 */
final readonly class PathResolutionRequest
{
    private function __construct(
        public PathTypeEnum $pathType,
        public string $installationPath,
        public InstallationTypeEnum $installationType,
        public PathConfiguration $pathConfiguration,
        public ?ExtensionIdentifier $extensionIdentifier = null,
        public array $validationRules = [],
        public array $fallbackStrategies = [],
        public CacheOptions $cacheOptions = new CacheOptions(),
    ) {}

    public static function builder(): PathResolutionRequestBuilder
    {
        return new PathResolutionRequestBuilder();
    }

    public function getCacheKey(): string
    {
        return sprintf(
            'path_resolution:%s:%s:%s:%s',
            $this->pathType->value,
            $this->installationType->value,
            hash('sha256', $this->installationPath),
            hash('sha256', serialize($this->pathConfiguration))
        );
    }

    public function withExtensionIdentifier(ExtensionIdentifier $identifier): self
    {
        return new self(
            $this->pathType,
            $this->installationPath,
            $this->installationType,
            $this->pathConfiguration,
            $identifier,
            $this->validationRules,
            $this->fallbackStrategies,
            $this->cacheOptions
        );
    }
}
```

#### PathResolutionRequestBuilder

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception\InvalidRequestException;

/**
 * Builder for creating validated PathResolutionRequest objects.
 * Implements comprehensive validation at construction time.
 */
final class PathResolutionRequestBuilder
{
    private ?PathTypeEnum $pathType = null;
    private ?string $installationPath = null;
    private ?InstallationTypeEnum $installationType = null;
    private ?PathConfiguration $pathConfiguration = null;
    private ?ExtensionIdentifier $extensionIdentifier = null;
    private array $validationRules = [];
    private array $fallbackStrategies = [];
    private ?CacheOptions $cacheOptions = null;

    public function pathType(PathTypeEnum $pathType): self
    {
        $this->pathType = $pathType;
        return $this;
    }

    public function installationPath(string $path): self
    {
        if (!is_dir($path) && !is_file($path)) {
            throw new InvalidRequestException("Installation path does not exist: {$path}");
        }

        $this->installationPath = realpath($path) ?: $path;
        return $this;
    }

    public function installationType(InstallationTypeEnum $type): self
    {
        $this->installationType = $type;
        return $this;
    }

    public function pathConfiguration(PathConfiguration $config): self
    {
        $this->pathConfiguration = $config;
        return $this;
    }

    public function extensionIdentifier(ExtensionIdentifier $identifier): self
    {
        $this->extensionIdentifier = $identifier;
        return $this;
    }

    public function addValidationRule(string $rule, array $parameters = []): self
    {
        $this->validationRules[$rule] = $parameters;
        return $this;
    }

    public function addFallbackStrategy(string $strategy, int $priority = 100): self
    {
        $this->fallbackStrategies[] = new FallbackStrategy($strategy, $priority);
        return $this;
    }

    public function cacheOptions(CacheOptions $options): self
    {
        $this->cacheOptions = $options;
        return $this;
    }

    public function build(): PathResolutionRequest
    {
        $this->validateRequiredFields();
        $this->validateFieldCompatibility();

        return new PathResolutionRequest(
            $this->pathType,
            $this->installationPath,
            $this->installationType,
            $this->pathConfiguration ?? PathConfiguration::createDefault(),
            $this->extensionIdentifier,
            $this->validationRules,
            $this->fallbackStrategies,
            $this->cacheOptions ?? new CacheOptions()
        );
    }

    private function validateRequiredFields(): void
    {
        $missing = [];

        if ($this->pathType === null) $missing[] = 'pathType';
        if ($this->installationPath === null) $missing[] = 'installationPath';
        if ($this->installationType === null) $missing[] = 'installationType';

        if (!empty($missing)) {
            throw new InvalidRequestException(
                'Missing required fields: ' . implode(', ', $missing)
            );
        }
    }

    private function validateFieldCompatibility(): void
    {
        if ($this->pathType && $this->installationType) {
            if (!$this->pathType->isCompatibleWith($this->installationType)) {
                throw new InvalidRequestException(
                    "Path type {$this->pathType->value} is not compatible with installation type {$this->installationType->value}"
                );
            }
        }
    }
}
```

#### PathResolutionResponse

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\ResolutionStatusEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;

/**
 * Immutable response object with strongly typed results and metadata.
 * Replaces mixed return types with comprehensive response data.
 */
final readonly class PathResolutionResponse
{
    private function __construct(
        public ResolutionStatusEnum $status,
        public PathTypeEnum $pathType,
        public ?string $resolvedPath,
        public PathResolutionMetadata $metadata,
        public array $alternativePaths = [],
        public array $warnings = [],
        public array $errors = [],
        public ?string $cacheKey = null,
        public ?float $resolutionTime = null,
    ) {}

    public static function success(
        PathTypeEnum $pathType,
        string $resolvedPath,
        PathResolutionMetadata $metadata,
        array $alternativePaths = [],
        array $warnings = [],
        ?string $cacheKey = null,
        ?float $resolutionTime = null
    ): self {
        return new self(
            ResolutionStatusEnum::SUCCESS,
            $pathType,
            $resolvedPath,
            $metadata,
            $alternativePaths,
            $warnings,
            [],
            $cacheKey,
            $resolutionTime
        );
    }

    public static function notFound(
        PathTypeEnum $pathType,
        PathResolutionMetadata $metadata,
        array $alternativePaths = [],
        array $warnings = [],
        ?string $cacheKey = null,
        ?float $resolutionTime = null
    ): self {
        return new self(
            ResolutionStatusEnum::NOT_FOUND,
            $pathType,
            null,
            $metadata,
            $alternativePaths,
            $warnings,
            [],
            $cacheKey,
            $resolutionTime
        );
    }

    public static function error(
        PathTypeEnum $pathType,
        PathResolutionMetadata $metadata,
        array $errors,
        array $warnings = [],
        ?string $cacheKey = null,
        ?float $resolutionTime = null
    ): self {
        return new self(
            ResolutionStatusEnum::ERROR,
            $pathType,
            null,
            $metadata,
            [],
            $warnings,
            $errors,
            $cacheKey,
            $resolutionTime
        );
    }

    public function isSuccess(): bool
    {
        return $this->status === ResolutionStatusEnum::SUCCESS;
    }

    public function isNotFound(): bool
    {
        return $this->status === ResolutionStatusEnum::NOT_FOUND;
    }

    public function isError(): bool
    {
        return $this->status === ResolutionStatusEnum::ERROR;
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getBestAlternative(): ?string
    {
        return $this->alternativePaths[0] ?? null;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'pathType' => $this->pathType->value,
            'resolvedPath' => $this->resolvedPath,
            'metadata' => $this->metadata->toArray(),
            'alternativePaths' => $this->alternativePaths,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'cacheKey' => $this->cacheKey,
            'resolutionTime' => $this->resolutionTime,
        ];
    }
}
```

#### Supporting Data Transfer Objects

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO;

/**
 * Immutable configuration object replacing loose array configurations.
 */
final readonly class PathConfiguration
{
    private function __construct(
        public array $customPaths = [],
        public array $searchDirectories = [],
        public array $excludePatterns = [],
        public bool $followSymlinks = true,
        public bool $validateExists = true,
        public int $maxDepth = 10,
    ) {}

    public static function createDefault(): self
    {
        return new self();
    }

    public static function fromArray(array $config): self
    {
        return new self(
            $config['customPaths'] ?? [],
            $config['searchDirectories'] ?? [],
            $config['excludePatterns'] ?? [],
            $config['followSymlinks'] ?? true,
            $config['validateExists'] ?? true,
            $config['maxDepth'] ?? 10
        );
    }

    public function toArray(): array
    {
        return [
            'customPaths' => $this->customPaths,
            'searchDirectories' => $this->searchDirectories,
            'excludePatterns' => $this->excludePatterns,
            'followSymlinks' => $this->followSymlinks,
            'validateExists' => $this->validateExists,
            'maxDepth' => $this->maxDepth,
        ];
    }

    public function getCustomPath(string $key): ?string
    {
        return $this->customPaths[$key] ?? null;
    }
}

/**
 * Extension identifier object replacing domain entity dependencies.
 */
final readonly class ExtensionIdentifier
{
    public function __construct(
        public string $key,
        public ?string $version = null,
        public ?string $type = null,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'type' => $this->type,
        ];
    }
}

/**
 * Cache options object for fine-grained cache control.
 */
final readonly class CacheOptions
{
    public function __construct(
        public bool $enabled = true,
        public int $ttlSeconds = 300,
        public bool $useMemoryCache = true,
        public bool $usePersistentCache = false,
        public array $invalidationTriggers = [],
    ) {}
}

/**
 * Fallback strategy object for ordered fallback handling.
 */
final readonly class FallbackStrategy
{
    public function __construct(
        public string $strategyClass,
        public int $priority,
        public array $options = [],
    ) {}
}

/**
 * Comprehensive metadata object with detailed resolution information.
 */
final readonly class PathResolutionMetadata
{
    public function __construct(
        public PathTypeEnum $pathType,
        public InstallationTypeEnum $installationType,
        public string $usedStrategy,
        public int $strategyPriority,
        public array $attemptedPaths = [],
        public array $strategyChain = [],
        public float $cacheHitRatio = 0.0,
        public bool $wasFromCache = false,
        public ?string $fallbackReason = null,
    ) {}

    public function toArray(): array
    {
        return [
            'pathType' => $this->pathType->value,
            'installationType' => $this->installationType->value,
            'usedStrategy' => $this->usedStrategy,
            'strategyPriority' => $this->strategyPriority,
            'attemptedPaths' => $this->attemptedPaths,
            'strategyChain' => $this->strategyChain,
            'cacheHitRatio' => $this->cacheHitRatio,
            'wasFromCache' => $this->wasFromCache,
            'fallbackReason' => $this->fallbackReason,
        ];
    }
}
```

### Enumerations

#### PathTypeEnum

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum;

/**
 * Enumeration of supported path types with validation capabilities.
 */
enum PathTypeEnum: string
{
    case EXTENSION = 'extension';
    case TYPO3CONF_DIR = 'typo3conf_dir';
    case VENDOR_DIR = 'vendor_dir';
    case WEB_DIR = 'web_dir';
    case LOCAL_CONFIGURATION = 'local_configuration';
    case PACKAGE_STATES = 'package_states';
    case COMPOSER_INSTALLED = 'composer_installed';
    case TEMPLATE_DIR = 'template_dir';
    case CACHE_DIR = 'cache_dir';
    case LOG_DIR = 'log_dir';
    case TYPOSCRIPT_DIR = 'typoscript_dir';
    case SYSTEM_EXTENSION = 'system_extension';

    /**
     * Get compatible installation types for this path type.
     */
    public function getCompatibleInstallationTypes(): array
    {
        return match ($this) {
            self::EXTENSION, self::TYPO3CONF_DIR, self::WEB_DIR => InstallationTypeEnum::cases(),
            self::VENDOR_DIR, self::COMPOSER_INSTALLED => [
                InstallationTypeEnum::COMPOSER_STANDARD,
                InstallationTypeEnum::COMPOSER_CUSTOM,
                InstallationTypeEnum::CUSTOM
            ],
            self::SYSTEM_EXTENSION => [
                InstallationTypeEnum::COMPOSER_STANDARD,
                InstallationTypeEnum::LEGACY_SOURCE,
                InstallationTypeEnum::CUSTOM
            ],
            default => InstallationTypeEnum::cases(),
        };
    }

    /**
     * Check if this path type is compatible with an installation type.
     */
    public function isCompatibleWith(InstallationTypeEnum $installationType): bool
    {
        return match ($this) {
            self::EXTENSION, self::TYPO3CONF_DIR, self::WEB_DIR => true,
            self::VENDOR_DIR, self::COMPOSER_INSTALLED =>
                $installationType !== InstallationTypeEnum::LEGACY_SOURCE,
            self::SYSTEM_EXTENSION =>
                $installationType !== InstallationTypeEnum::DOCKER_CONTAINER,
            default => true,
        };
    }

    /**
     * Get required validation rules for this path type.
     */
    public function getRequiredValidationRules(): array
    {
        return match ($this) {
            self::EXTENSION => ['extension_identifier_required', 'directory_exists'],
            self::LOCAL_CONFIGURATION, self::PACKAGE_STATES => ['file_exists', 'readable'],
            self::VENDOR_DIR, self::WEB_DIR => ['directory_exists', 'readable'],
            default => ['exists'],
        };
    }

    /**
     * Get default fallback strategies for this path type.
     */
    public function getDefaultFallbackStrategies(): array
    {
        return match ($this) {
            self::EXTENSION => [
                'ComposerExtensionPathStrategy',
                'LegacyExtensionPathStrategy',
                'CustomExtensionPathStrategy',
            ],
            self::VENDOR_DIR => [
                'ComposerVendorDirStrategy',
                'LegacyVendorDirStrategy',
            ],
            default => ['GenericPathResolutionStrategy'],
        };
    }
}
```

#### InstallationTypeEnum and Supporting Enums

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum;

/**
 * Installation type enumeration with detection capabilities.
 */
enum InstallationTypeEnum: string
{
    case COMPOSER_STANDARD = 'composer_standard';
    case COMPOSER_CUSTOM = 'composer_custom';
    case LEGACY_SOURCE = 'legacy_source';
    case DOCKER_CONTAINER = 'docker_container';
    case CUSTOM = 'custom';
    case AUTO_DETECT = 'auto_detect';

    /**
     * Get typical directory structure for this installation type.
     */
    public function getTypicalDirectories(): array
    {
        return match ($this) {
            self::COMPOSER_STANDARD => ['vendor', 'public', 'var'],
            self::COMPOSER_CUSTOM => ['vendor', 'web', 'var'],
            self::LEGACY_SOURCE => ['typo3_src', 'typo3conf', 'fileadmin'],
            self::DOCKER_CONTAINER => ['app', 'vendor', 'public'],
            default => [],
        };
    }
}

/**
 * Resolution status enumeration for response objects.
 */
enum ResolutionStatusEnum: string
{
    case SUCCESS = 'success';
    case NOT_FOUND = 'not_found';
    case ERROR = 'error';
    case PARTIAL = 'partial';
}

/**
 * Strategy priority enumeration for conflict resolution.
 */
enum StrategyPriorityEnum: int
{
    case HIGHEST = 100;
    case HIGH = 75;
    case NORMAL = 50;
    case LOW = 25;
    case LOWEST = 10;
}
```

### Strategy System

#### PathResolutionStrategyInterface

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\StrategyPriorityEnum;

/**
 * Enhanced strategy interface with priority system and capabilities.
 */
interface PathResolutionStrategyInterface
{
    /**
     * Resolve path according to this strategy.
     */
    public function resolve(PathResolutionRequest $request): PathResolutionResponse;

    /**
     * Get supported path types for this strategy.
     *
     * @return PathTypeEnum[]
     */
    public function getSupportedPathTypes(): array;

    /**
     * Get supported installation types for this strategy.
     *
     * @return InstallationTypeEnum[]
     */
    public function getSupportedInstallationTypes(): array;

    /**
     * Get strategy priority for given path and installation type.
     */
    public function getPriority(
        PathTypeEnum $pathType,
        InstallationTypeEnum $installationType
    ): StrategyPriorityEnum;

    /**
     * Check if strategy can handle the specific request.
     */
    public function canHandle(PathResolutionRequest $request): bool;

    /**
     * Get strategy identifier for logging and debugging.
     */
    public function getIdentifier(): string;

    /**
     * Get strategy configuration requirements.
     */
    public function getRequiredConfiguration(): array;

    /**
     * Validate that strategy can operate with current system state.
     */
    public function validateEnvironment(): array;
}
```

### Service Interfaces

#### PathResolutionServiceInterface

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;

/**
 * Main service interface for path resolution operations.
 */
interface PathResolutionServiceInterface
{
    /**
     * Resolve a single path based on the provided request.
     */
    public function resolvePath(PathResolutionRequest $request): PathResolutionResponse;

    /**
     * Resolve multiple paths in batch for optimization.
     *
     * @param PathResolutionRequest[] $requests
     * @return PathResolutionResponse[]
     */
    public function resolveMultiplePaths(array $requests): array;

    /**
     * Check if a path type is supported by any registered strategy.
     */
    public function supportsPathType(PathTypeEnum $pathType): bool;

    /**
     * Get available path types for a given installation type.
     *
     * @return PathTypeEnum[]
     */
    public function getAvailablePathTypes(InstallationTypeEnum $installationType): array;

    /**
     * Get service capabilities and configuration.
     */
    public function getResolutionCapabilities(): array;
}
```

### Exception Hierarchy

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;

/**
 * Base exception for all path resolution operations.
 * Provides context preservation and recovery strategy support.
 */
abstract class PathResolutionException extends \RuntimeException
{
    protected ?PathResolutionRequest $request = null;
    protected array $context = [];
    protected array $recoveryStrategies = [];

    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?PathResolutionRequest $request = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->request = $request;
        $this->context = $context;
    }

    public function getRequest(): ?PathResolutionRequest
    {
        return $this->request;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function addContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    public function getRecoveryStrategies(): array
    {
        return $this->recoveryStrategies;
    }

    public function addRecoveryStrategy(string $strategy, array $parameters = []): self
    {
        $this->recoveryStrategies[] = ['strategy' => $strategy, 'parameters' => $parameters];
        return $this;
    }

    abstract public function getErrorCode(): string;
    abstract public function getRetryable(): bool;
    abstract public function getSeverity(): string;
}

/**
 * Exception for invalid request construction.
 */
class InvalidRequestException extends PathResolutionException
{
    public function getErrorCode(): string
    {
        return 'INVALID_REQUEST';
    }

    public function getRetryable(): bool
    {
        return false;
    }

    public function getSeverity(): string
    {
        return 'error';
    }
}

/**
 * Exception when no compatible strategy can be found.
 */
class NoCompatibleStrategyException extends PathResolutionException
{
    public function getErrorCode(): string
    {
        return 'NO_COMPATIBLE_STRATEGY';
    }

    public function getRetryable(): bool
    {
        return false;
    }

    public function getSeverity(): string
    {
        return 'error';
    }
}

/**
 * Exception when path cannot be found despite valid request.
 */
class PathNotFoundException extends PathResolutionException
{
    private array $attemptedPaths = [];
    private array $suggestedPaths = [];

    public function getErrorCode(): string
    {
        return 'PATH_NOT_FOUND';
    }

    public function getRetryable(): bool
    {
        return true;
    }

    public function getSeverity(): string
    {
        return 'warning';
    }

    public function setAttemptedPaths(array $paths): self
    {
        $this->attemptedPaths = $paths;
        return $this;
    }

    public function getAttemptedPaths(): array
    {
        return $this->attemptedPaths;
    }

    public function setSuggestedPaths(array $paths): self
    {
        $this->suggestedPaths = $paths;
        return $this;
    }

    public function getSuggestedPaths(): array
    {
        return $this->suggestedPaths;
    }

    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null, ?PathResolutionRequest $request = null, array $context = [])
    {
        parent::__construct($message, $code, $previous, $request, $context);
        $this->addRecoveryStrategy('alternative_path_search');
        $this->addRecoveryStrategy('configuration_update_suggestion');
        $this->addRecoveryStrategy('fallback_to_default_paths');
    }
}
```

## Architecture Integration

### Clean Architecture Layers

**Domain Layer Integration:**

- No changes required - existing entities (Extension, Installation) remain unchanged
- AnalysisContext continues to provide configuration values

**Infrastructure Layer:**

- New `Infrastructure\Path\` namespace contains all path resolution logic
- Strategies in `Infrastructure\Path\Strategy\` for different resolution approaches
- Integration with existing DI container system

**Application Layer:**

- No direct changes - analyzers use PathResolutionService through DI
- Commands continue to work without modification

### Dependency Injection Configuration

```yaml
# config/services.yaml
services:
    # Path Resolution Service
    CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionServiceInterface:
        class: CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionService
        arguments:
            $strategies: !tagged_iterator path_resolution_strategy

    CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionService:
        alias: CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionServiceInterface

    # Path Resolution Strategies
    CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ExtensionPathResolutionStrategy:
        tags: [ 'path_resolution_strategy' ]

    CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ConfigurationPathResolutionStrategy:
        tags: [ 'path_resolution_strategy' ]

    CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\DirectoryPathResolutionStrategy:
        tags: [ 'path_resolution_strategy' ]
```

## Implementation Benefits

### Code Quality

- **Eliminates 200+ lines** of duplicated code
- **Improves testability** with dependency injection
- **Enhances maintainability** through single responsibility
- **Increases type safety** with strong typing throughout

### Performance

- **Multi-layer caching** reduces filesystem operations
- **Batch processing** optimizes multiple requests
- **Resource management** prevents memory issues
- **Performance monitoring** enables optimization

### Extensibility

- **Strategy pattern** supports new installation types
- **Request/Response objects** enable new path types
- **Priority system** handles strategy conflicts
- **Clean interfaces** support future enhancements

## Implementation Plan

### Phase 1: Foundation (8-10 hours)

- Core interfaces and transfer objects
- Validation and error handling
- Basic strategy framework

### Phase 2: Strategy Implementation (10-12 hours)

- Extension path resolution strategies
- Multi-layer caching system
- Batch processing optimization

### Next Steps

1. **Begin Phase 1 implementation** with core interfaces
2. **Set up comprehensive test coverage**
3. **Implement extension path resolution strategy**
4. **Integrate with existing analyzers**

This specification provides a comprehensive foundation for eliminating path resolution duplication while creating a
robust, extensible system for future enhancements.
