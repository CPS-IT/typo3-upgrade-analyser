# Architecture — TYPO3 Upgrade Analyzer

## Executive Summary

The TYPO3 Upgrade Analyzer follows Clean Architecture principles with four layers: Application, Domain, Infrastructure, and Shared. Dependencies point inward — Infrastructure depends on Domain, Application orchestrates both, and Shared provides cross-cutting utilities. The Symfony DI Container wires everything together via YAML configuration with auto-wiring and tagged service discovery.

## Architecture Pattern

**Style:** Clean Architecture (Hexagonal / Ports & Adapters variant)
**DI Strategy:** Symfony DI Container with YAML configuration, auto-wiring, and service tagging
**Plugin System:** Analyzers and parsers are auto-discovered via DI tags

```
┌─────────────────────────────────────────────────┐
│                  Application                     │
│  (Commands, AnalyzerApplication)                │
│                                                  │
│  ┌─────────────────────────────────────────────┐│
│  │                 Domain                       ││
│  │  (Entities, Value Objects, Contracts)        ││
│  │                                              ││
│  └─────────────────────────────────────────────┘│
│                                                  │
│  ┌─────────────────────────────────────────────┐│
│  │              Infrastructure                  ││
│  │  (Analyzers, API Clients, Parsers,          ││
│  │   Discovery, Reporting, Path Resolution)    ││
│  └─────────────────────────────────────────────┘│
│                                                  │
│  ┌─────────────────────────────────────────────┐│
│  │                 Shared                       ││
│  │  (ContainerFactory, Utilities, Env Loader)  ││
│  └─────────────────────────────────────────────┘│
└─────────────────────────────────────────────────┘
```

## Layer Details

### Application Layer (`src/Application/`)

Entry point and orchestration. Contains the Symfony Console application and CLI commands.

| Class | Purpose |
|---|---|
| `AnalyzerApplication` | Main console application; registers commands, holds DI container |
| `AnalyzeCommand` | Orchestrates 3-phase workflow: discovery → analysis → reporting |
| `InitConfigCommand` | Generates YAML configuration files (interactive or default) |
| `ListAnalyzersCommand` | Lists registered analyzers with status and required tools |
| `ListExtensionsCommand` | Lists discovered extensions with version and status info |

**AnalyzeCommand Workflow:**
1. **Discovery Phase**: Detect TYPO3 installation, extract version, discover extensions
2. **Analysis Phase**: Run each enabled analyzer against each extension
3. **Reporting Phase**: Build report context, render templates, write output files

### Domain Layer (`src/Domain/`)

Pure business logic. No framework dependencies. Defines the language of the system.

**Contracts:**
- `ResultInterface` — Unified interface for all result types (discovery, analysis, reporting)

**Entities:**
| Entity | Purpose |
|---|---|
| `Installation` | TYPO3 installation with version, extensions, configuration, mode (Composer/Legacy) |
| `Extension` | Extension with key, version, type, files, dependencies, metadata, repository URL |
| `AnalysisResult` | Analysis output with metrics, risk score (0-100), risk level, recommendations |
| `DiscoveryResult` | Discovery operation result |
| `ReportingResult` | Report generation result |

**Value Objects (immutable):**
| Value Object | Purpose |
|---|---|
| `Version` | Semantic version with comparison operations |
| `AnalysisContext` | Current/target versions, PHP versions, configuration |
| `ConfigurationData` | Parsed configuration data with typed accessors |
| `ConfigurationMetadata` | File metadata (path, size, format, modification time) |
| `ExtensionMetadata` | Extension metadata (author, license, supported versions) |
| `InstallationMetadata` | Installation metadata (PHP versions, DB config, custom paths) |
| `ParseResult` | Configuration parsing result with error/warning tracking |
| `ExtensionType` | Enum: SYSTEM, LOCAL, COMPOSER |
| `InstallationMode` | Enum: COMPOSER |

### Infrastructure Layer (`src/Infrastructure/`)

Implementations, external integrations, and technical concerns. This is the largest layer.

#### Analyzer Subsystem (`Infrastructure/Analyzer/`)

**Core:**
- `AnalyzerInterface` — Contract: `getName()`, `supports()`, `analyze()`, `getRequiredTools()`, `hasRequiredTools()`
- `AbstractCachedAnalyzer` — Base class with file-based caching, TTL, cache key generation
- `AnalyzerException` — Analyzer-specific errors

**Concrete Analyzers:**

| Analyzer | Dependencies | What It Does |
|---|---|---|
| `VersionAvailabilityAnalyzer` | TerApiClient, PackagistClient, GitRepositoryAnalyzer | Checks extension availability across 3 sources |
| `Typo3RectorAnalyzer` | RectorExecutor, RectorConfigGenerator, RectorResultParser, RectorRuleRegistry | Detects deprecated PHP code patterns |
| `FractorAnalyzer` | FractorExecutor, FractorConfigGenerator, FractorResultParser | Detects TypoScript modernization needs |
| `LinesOfCodeAnalyzer` | PathResolutionService, Symfony Finder | Measures code complexity metrics |

**Rector Support (`Infrastructure/Analyzer/Rector/`):**
- `RectorExecutor` — Runs Rector binary via Symfony Process
- `RectorConfigGenerator` — Generates temporary Rector config for target TYPO3 version
- `RectorResultParser` — Parses Rector JSON output into findings
- `RectorRuleRegistry` — Maps Rector rules to severity levels and change types
- `RectorFindingsCollection` — Collection of `RectorFinding` objects with filtering/grouping
- `RectorChangeType` (enum) — BREAKING, DEPRECATION, etc.
- `RectorRuleSeverity` (enum) — Severity levels for rules

**Fractor Support (`Infrastructure/Analyzer/Fractor/`):**
- `FractorExecutor` — Runs Fractor binary
- `FractorConfigGenerator` — Generates Fractor configuration
- `FractorExecutionResult` — Execution result wrapper

#### Discovery Subsystem (`Infrastructure/Discovery/`)

| Class | Purpose |
|---|---|
| `InstallationDiscoveryService` | Discovers TYPO3 installations using detection strategies |
| `ExtensionDiscoveryService` | Discovers extensions within an installation |
| `ComposerInstallationDetector` | Detects Composer-based installations via composer.json/lock |
| `VersionExtractor` | Multi-strategy TYPO3 version detection |
| `ComposerVersionStrategy` | Extracts version from Composer constraints |
| `ConfigurationDiscoveryService` | Discovers and parses configuration files |
| `ValidationIssue` / `ValidationSeverity` | Validation result types |

**Strategy Pattern:** `DetectionStrategyInterface`, `VersionStrategyInterface`, `ValidationRuleInterface`

#### External Tool Subsystem (`Infrastructure/ExternalTool/`)

| Client | API | Purpose |
|---|---|---|
| `TerApiClient` | TYPO3 Extension Repository | Extension metadata, version lookup |
| `TerApiHttpClient` | TER REST API | HTTP transport for TER |
| `TerApiResponseParser` | — | Parses TER JSON responses |
| `PackagistClient` | Packagist API | Composer package availability |
| `GitRepositoryAnalyzer` | Git CLI + GitHub API | Repository health, tags, metadata |
| `GitHubClient` | GitHub REST API | Repository info via GitHub API |
| `GitProviderFactory` | — | Creates appropriate Git provider |
| `VersionCompatibilityChecker` | — | TYPO3 version constraint validation |

#### Path Resolution Subsystem (`Infrastructure/Path/`)

Sophisticated path resolution with 6 strategies, multi-layer caching, validation, and error recovery.

**Core:** `PathResolutionService` orchestrates resolution via `PathResolutionStrategyRegistry`

**Strategies (priority-ordered):**
1. `ComposerInstalledPathResolutionStrategy` — From composer installed.json
2. `PackageStatesPathResolutionStrategy` — From PackageStates.php
3. `ExtensionPathResolutionStrategy` — Convention-based extension paths
4. `VendorDirPathResolutionStrategy` — Vendor directory resolution
5. `WebDirPathResolutionStrategy` — Web root resolution
6. `Typo3ConfDirPathResolutionStrategy` — typo3conf directory resolution

**Supporting Classes:**
- DTOs: `PathConfiguration`, `PathResolutionResponse`, `PathResolutionRequestBuilder`, `CacheOptions`, `FallbackStrategy`
- Enums: `InstallationTypeEnum`, `ResolutionStatusEnum`, `StrategyPriorityEnum`
- `MultiLayerPathResolutionCache` — Memory + file caching
- `PathResolutionValidator` — Validates resolved paths
- `ErrorRecoveryManager` — Handles resolution failures gracefully

#### Configuration Parsing (`Infrastructure/Parser/`)

| Parser | Handles |
|---|---|
| `PhpConfigurationParser` | LocalConfiguration.php (via nikic/php-parser AST) |
| `YamlConfigurationParser` | Services.yaml, site configs |
| `AbstractConfigurationParser` | Base class with validation |

#### Reporting Subsystem (`Infrastructure/Reporting/`)

| Class | Purpose |
|---|---|
| `ReportService` | Orchestrates report generation |
| `ReportContextBuilder` | Assembles template context from analysis results |
| `TemplateRenderer` | Renders Twig templates (HTML, MD, JSON formats) |
| `ReportFileManager` | Manages output directory creation and file writing |

#### Other Infrastructure

- `CacheService` — File-based JSON cache with TTL
- `HttpClientService` — HTTP client wrapper with timeout and User-Agent
- `ConfigurationService` — Application YAML configuration management
- `ComposerConstraintChecker` — Validates Composer version constraints

### Shared Layer (`src/Shared/`)

| Class | Purpose |
|---|---|
| `ContainerFactory` | Creates and compiles Symfony DI container from services.yaml |
| `EnvironmentLoader` | Loads .env files |
| `ProjectRootResolver` | Resolves project root directory |
| `BinaryPathResolver` | Resolves external binary locations (rector, fractor) |

## Design Patterns

| Pattern | Where Used |
|---|---|
| Clean Architecture | Overall layer structure |
| Strategy | Path resolution, version extraction, detection |
| Template Method | `AbstractCachedAnalyzer` (subclasses implement `doAnalyze()`) |
| Factory | `ContainerFactory`, `GitProviderFactory` |
| Plugin / Service Locator | Analyzer and parser auto-discovery via DI tags |
| Value Object | All domain value objects (immutable, self-validating) |
| Repository | Configuration storage and query |
| Builder | `ReportContextBuilder`, `PathResolutionRequestBuilder` |
| Multi-layer Cache | `MultiLayerPathResolutionCache` (memory + file) |

## Dependency Injection Configuration

Services are defined in `config/services.yaml`:
- **Auto-wiring** enabled for `CPSIT\UpgradeAnalyzer\` namespace
- **Analyzers** tagged with `analyzer` tag, injected via `!tagged_iterator`
- **Parsers** tagged with `configuration_parser` tag
- **Commands** registered as public services
- **Twig** configured with template directory at `resources/templates/`

## Data Flow

```
CLI Input
    │
    ▼
AnalyzeCommand
    │
    ├──► InstallationDiscoveryService ──► Installation entity
    │         │
    │         ├──► ComposerInstallationDetector
    │         ├──► VersionExtractor
    │         └──► ConfigurationDiscoveryService
    │
    ├──► ExtensionDiscoveryService ──► Extension[] entities
    │
    ├──► For each Extension × Analyzer:
    │         │
    │         ├──► VersionAvailabilityAnalyzer ──► TER/Packagist/Git APIs
    │         ├──► Typo3RectorAnalyzer ──► Rector binary
    │         ├──► FractorAnalyzer ──► Fractor binary
    │         └──► LinesOfCodeAnalyzer ──► File system scan
    │         │
    │         └──► AnalysisResult (metrics, risk score, recommendations)
    │
    └──► ReportService
              │
              ├──► ReportContextBuilder (assembles context)
              ├──► TemplateRenderer (renders Twig templates)
              └──► ReportFileManager (writes files)
                        │
                        └──► HTML / Markdown / JSON / CSV output
```

## Entry Points

| Entry Point | Path | Purpose |
|---|---|---|
| CLI binary | `bin/typo3-analyzer` | Loads autoloader, creates `AnalyzerApplication`, runs it |
| Application | `src/Application/AnalyzerApplication.php` | Registers commands, holds DI container |
| DI Container | `src/Shared/Configuration/ContainerFactory.php` | Builds container from `config/services.yaml` |
