# Component Inventory — TYPO3 Upgrade Analyzer

## CLI Commands

| Command | Class | Purpose |
|---|---|---|
| `analyze` | `AnalyzeCommand` | Main analysis workflow: discover → analyze → report |
| `init-config` | `InitConfigCommand` | Generate YAML configuration files |
| `list-analyzers` | `ListAnalyzersCommand` | Display registered analyzers with status |
| `list-extensions` | `ListExtensionsCommand` | Display discovered extensions |

## Analyzers

| Analyzer | Class | External Deps | Caching |
|---|---|---|---|
| Version Availability | `VersionAvailabilityAnalyzer` | TER API, Packagist API, GitHub API | Yes (AbstractCachedAnalyzer) |
| TYPO3 Rector | `Typo3RectorAnalyzer` | Rector binary | Yes |
| Fractor | `FractorAnalyzer` | Fractor binary | Yes |
| Lines of Code | `LinesOfCodeAnalyzer` | None (filesystem) | Yes |

## Discovery Services

| Service | Interface | Purpose |
|---|---|---|
| `InstallationDiscoveryService` | `InstallationDiscoveryServiceInterface` | Discovers TYPO3 installations |
| `ExtensionDiscoveryService` | `ExtensionDiscoveryServiceInterface` | Discovers extensions within installations |
| `ComposerInstallationDetector` | `DetectionStrategyInterface` | Detects Composer-based installations |
| `VersionExtractor` | — | Multi-strategy TYPO3 version detection |
| `ComposerVersionStrategy` | `VersionStrategyInterface` | Extracts version from Composer constraints |
| `ConfigurationDiscoveryService` | — | Discovers and parses configuration files |

## External API Clients

| Client | API | Purpose |
|---|---|---|
| `TerApiClient` | TYPO3 Extension Repository | Extension metadata and version lookup |
| `TerApiHttpClient` | TER REST API | HTTP transport for TER |
| `TerApiResponseParser` | — | Parses TER JSON responses |
| `PackagistClient` | Packagist API | Composer package availability and versions |
| `GitRepositoryAnalyzer` | Git CLI + GitHub API | Repository health, tags, metadata |
| `GitHubClient` | GitHub REST API v3 | Repository info, releases, contributors |
| `GitProviderFactory` | — | Creates appropriate Git provider by URL |
| `VersionCompatibilityChecker` | — | Validates TYPO3 version constraints |

## Path Resolution Strategies

| Strategy | Priority | Resolves |
|---|---|---|
| `ComposerInstalledPathResolutionStrategy` | High | Paths from composer installed.json |
| `PackageStatesPathResolutionStrategy` | High | Paths from PackageStates.php |
| `ExtensionPathResolutionStrategy` | Medium | Convention-based extension paths |
| `VendorDirPathResolutionStrategy` | Medium | Vendor directory |
| `WebDirPathResolutionStrategy` | Medium | Web root directory |
| `Typo3ConfDirPathResolutionStrategy` | Low | typo3conf directory |

## Configuration Parsers

| Parser | Tag | Handles |
|---|---|---|
| `PhpConfigurationParser` | `configuration_parser` | PHP files (LocalConfiguration.php) via AST |
| `YamlConfigurationParser` | `configuration_parser` | YAML files (Services.yaml, site configs) |

## Reporting Components

| Component | Purpose |
|---|---|
| `ReportService` | Orchestrates report generation |
| `ReportContextBuilder` | Assembles template context from analysis results |
| `TemplateRenderer` | Renders Twig templates in multiple formats |
| `ReportFileManager` | Manages output directory and file writing |

## Report Templates

### HTML Templates (`resources/templates/html/`)

| Template | Purpose |
|---|---|
| `main-report.html.twig` | Main HTML report with all extensions |
| `extension-detail.html.twig` | Per-extension detail page |
| `detailed-report.html.twig` | Comprehensive detailed report |
| `rector-findings-detail.html.twig` | Rector findings detail page |

**Partials — Main Report:**
- `installation-overview.html.twig` — Installation info section
- `discovery-results.html.twig` — Discovery results summary
- `risk-distribution.html.twig` — Risk distribution chart
- `version-availability-table.html.twig` — Version availability matrix
- `rector-analysis-table.html.twig` — Rector findings summary table
- `fractor-analysis-table.html.twig` — Fractor findings summary table
- `lines-of-code-table.html.twig` — LOC metrics table
- `extension-links.html.twig` — Links to extension details
- `recommendations.html.twig` — Recommendations section

**Partials — Extension Detail:**
- `extension-overview.html.twig` — Extension info header
- `technical-details.html.twig` — Technical details section
- `version-availability-analysis.html.twig` — Version availability detail
- `rector-analysis.html.twig` — Rector analysis detail
- `fractor-analysis.html.twig` — Fractor analysis detail
- `lines-of-code-analysis.html.twig` — LOC analysis detail

**Partials — Rector Findings:**
- `summary-overview.html.twig` — Findings summary
- `findings-table.html.twig` — Detailed findings table

**Shared:**
- `styles.html.twig` — Embedded CSS styles

### Markdown Templates (`resources/templates/md/`)

| Template | Purpose |
|---|---|
| `main-report.md.twig` | Main Markdown report |
| `extension-detail.md.twig` | Per-extension detail page |
| `detailed-report.md.twig` | Comprehensive Markdown report |
| `rector-findings-detail.md.twig` | Rector findings detail page |

**Partials** mirror HTML structure with Markdown-specific formatting.

## Rector Integration Components

| Component | Purpose |
|---|---|
| `RectorExecutor` | Runs Rector binary via Symfony Process |
| `RectorConfigGenerator` | Generates temporary Rector config for target version |
| `RectorResultParser` | Parses Rector JSON output into findings |
| `RectorRuleRegistry` | Maps Rector rules to severity and change type |
| `RectorFindingsCollection` | Collection with filtering, grouping, statistics |
| `RectorExecutionResult` | Execution result wrapper |
| `RectorOutputResult` | Output result wrapper |
| `RectorChangeType` | Enum: BREAKING, DEPRECATION, etc. |
| `RectorRuleSeverity` | Enum: severity levels |

## Fractor Integration Components

| Component | Purpose |
|---|---|
| `FractorExecutor` | Runs Fractor binary |
| `FractorConfigGenerator` | Generates Fractor configuration |
| `FractorExecutionResult` | Execution result wrapper |
| `FractorExecutionException` | Execution error handling |

## Domain Entities

| Entity | Key Properties |
|---|---|
| `Installation` | path, version, type, extensions[], configuration, mode, metadata, valid |
| `Extension` | key, title, version, type, composerName, dependencies[], files[], metadata, repositoryUrl, active |
| `AnalysisResult` | analyzerName, extension, metrics[], riskScore (0-100), riskLevel, recommendations[] |
| `DiscoveryResult` | type, id, name, success, data, error |
| `ReportingResult` | type, id, name, success, value, error |

## Domain Value Objects

| Value Object | Key Properties |
|---|---|
| `Version` | major, minor, patch, suffix — with comparison methods |
| `AnalysisContext` | currentVersion, targetVersion, phpVersions, configuration |
| `ConfigurationData` | data (typed accessors: getString, getInt, getBool, getArray, getSection) |
| `ConfigurationMetadata` | filePath, format, fileSize, lastModified, parsedAt, parser, category |
| `ExtensionMetadata` | description, author, license, supportedPhpVersions, supportedTypo3Versions |
| `InstallationMetadata` | phpVersions, databaseConfig, enabledFeatures, customPaths, discoveryData |
| `ParseResult` | success, data, format, sourcePath, errors[], warnings[], metadata |
| `ExtensionType` | Enum: SYSTEM, LOCAL, COMPOSER |
| `InstallationMode` | Enum: COMPOSER |

## Shared Utilities

| Utility | Purpose |
|---|---|
| `ContainerFactory` | Creates Symfony DI container from services.yaml |
| `EnvironmentLoader` | Loads .env configuration files |
| `ProjectRootResolver` | Resolves project root directory |
| `BinaryPathResolver` | Resolves external binary locations (rector, fractor) |

## Caching Components

| Component | Purpose |
|---|---|
| `CacheService` | File-based JSON cache with TTL and key generation |
| `MultiLayerPathResolutionCache` | Memory + file caching for path resolution |
| `PathResolutionCacheStats` | Cache hit/miss statistics |
| `SerializableInterface` | Serialization contract for cacheable objects |

## HTTP Infrastructure

| Component | Purpose |
|---|---|
| `HttpClientService` | Wrapper with timeout, User-Agent, error handling |
| `HttpClientServiceInterface` | Contract for HTTP operations |
| `HttpClientException` | HTTP error handling |
