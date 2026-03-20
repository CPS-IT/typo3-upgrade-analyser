# Source Tree Analysis — TYPO3 Upgrade Analyzer

## Annotated Directory Tree

```
typo3-upgrade-analyser/
├── bin/
│   └── typo3-analyzer                          # CLI entry point (autoloader + AnalyzerApplication)
│
├── config/
│   └── services.yaml                           # Symfony DI container configuration (392 lines)
│
├── src/
│   ├── Application/                            # APPLICATION LAYER — CLI and orchestration
│   │   ├── AnalyzerApplication.php             # Main Symfony Console app, registers commands
│   │   └── Command/
│   │       ├── AnalyzeCommand.php              # 3-phase workflow: discover → analyze → report
│   │       ├── InitConfigCommand.php           # Generate YAML configuration files
│   │       ├── ListAnalyzersCommand.php        # Display registered analyzers
│   │       └── ListExtensionsCommand.php       # Display discovered extensions
│   │
│   ├── Domain/                                 # DOMAIN LAYER — Business logic, no framework deps
│   │   ├── Contract/
│   │   │   └── ResultInterface.php             # Unified result interface
│   │   ├── Entity/
│   │   │   ├── AnalysisResult.php              # Analysis output with metrics and risk scores
│   │   │   ├── DiscoveryResult.php             # Discovery operation result
│   │   │   ├── Extension.php                   # TYPO3 extension entity
│   │   │   ├── Installation.php                # TYPO3 installation entity
│   │   │   └── ReportingResult.php             # Report generation result
│   │   └── ValueObject/
│   │       ├── AnalysisContext.php              # Current/target versions context
│   │       ├── ConfigurationData.php           # Parsed configuration with typed accessors
│   │       ├── ConfigurationMetadata.php       # Configuration file metadata
│   │       ├── ExtensionMetadata.php           # Extension metadata (author, license, etc.)
│   │       ├── ExtensionType.php               # Enum: SYSTEM, LOCAL, COMPOSER
│   │       ├── InstallationMetadata.php        # Installation metadata (PHP, DB, paths)
│   │       ├── InstallationMode.php            # Enum: COMPOSER
│   │       ├── ParseResult.php                 # Config parsing result with errors/warnings
│   │       └── Version.php                     # Semantic version with comparisons
│   │
│   ├── Infrastructure/                         # INFRASTRUCTURE LAYER — Implementations
│   │   ├── Analyzer/                           # Pluggable analyzer system
│   │   │   ├── AnalyzerInterface.php           # Analyzer contract
│   │   │   ├── AnalyzerException.php           # Analyzer errors
│   │   │   ├── AbstractCachedAnalyzer.php      # Base with caching support
│   │   │   ├── VersionAvailabilityAnalyzer.php # TER + Packagist + Git checks
│   │   │   ├── Typo3RectorAnalyzer.php         # Deprecated code detection
│   │   │   ├── FractorAnalyzer.php             # TypoScript modernization
│   │   │   ├── LinesOfCodeAnalyzer.php         # Code complexity metrics
│   │   │   ├── Rector/                         # Rector integration
│   │   │   │   ├── RectorExecutor.php          # Runs rector binary
│   │   │   │   ├── RectorConfigGenerator.php   # Generates rector config
│   │   │   │   ├── RectorResultParser.php      # Parses rector JSON output
│   │   │   │   ├── RectorRuleRegistry.php      # Rule → severity mapping
│   │   │   │   ├── RectorFindingsCollection.php# Findings with filtering/grouping
│   │   │   │   ├── RectorExecutionResult.php   # Execution result wrapper
│   │   │   │   ├── RectorOutputResult.php      # Output result wrapper
│   │   │   │   ├── RectorChangeType.php        # Enum: BREAKING, DEPRECATION, etc.
│   │   │   │   └── RectorRuleSeverity.php      # Enum: severity levels
│   │   │   └── Fractor/                        # Fractor integration
│   │   │       ├── FractorExecutor.php          # Runs fractor binary
│   │   │       ├── FractorConfigGenerator.php   # Generates fractor config
│   │   │       ├── FractorExecutionResult.php   # Execution result
│   │   │       └── FractorExecutionException.php# Execution errors
│   │   │
│   │   ├── Cache/
│   │   │   ├── CacheService.php                # File-based JSON cache with TTL
│   │   │   └── SerializableInterface.php       # Serialization contract
│   │   │
│   │   ├── Configuration/
│   │   │   ├── ConfigurationServiceInterface.php
│   │   │   └── ConfigurationService.php        # YAML config management
│   │   │
│   │   ├── Discovery/                          # TYPO3 installation detection
│   │   │   ├── InstallationDiscoveryServiceInterface.php
│   │   │   ├── ExtensionDiscoveryServiceInterface.php
│   │   │   ├── DetectionStrategyInterface.php
│   │   │   ├── ValidationRuleInterface.php
│   │   │   ├── VersionStrategyInterface.php
│   │   │   ├── ComposerInstallationDetector.php # Composer-based detection
│   │   │   ├── ComposerVersionStrategy.php     # Version from composer constraints
│   │   │   ├── VersionExtractor.php            # Multi-strategy version detection
│   │   │   ├── VersionExtractionResult.php     # Version extraction result
│   │   │   ├── ConfigurationDiscoveryService.php# Config file discovery
│   │   │   ├── ValidationIssue.php             # Validation issue
│   │   │   └── ValidationSeverity.php          # Validation severity enum
│   │   │
│   │   ├── ExternalTool/                       # External API clients
│   │   │   ├── TerApiClient.php                # TER API facade
│   │   │   ├── TerApiHttpClient.php            # TER HTTP transport
│   │   │   ├── TerApiResponseParser.php        # TER response parsing
│   │   │   ├── TerApiException.php             # TER errors
│   │   │   ├── TerExtensionNotFoundException.php
│   │   │   ├── PackagistClient.php             # Packagist API client
│   │   │   ├── GitRepositoryAnalyzer.php       # Git repo analysis
│   │   │   ├── GitVersionParser.php            # Git tag → version parsing
│   │   │   ├── GitRepositoryInfo.php           # Repo info DTO
│   │   │   ├── GitRepositoryHealth.php         # Repo health DTO
│   │   │   ├── GitRepositoryMetadata.php       # Repo metadata DTO
│   │   │   ├── GitTag.php                      # Git tag DTO
│   │   │   ├── GitAnalysisException.php        # Git errors
│   │   │   ├── VersionCompatibilityChecker.php # Version constraint validation
│   │   │   ├── ExternalToolException.php       # External tool errors
│   │   │   └── GitProvider/
│   │   │       ├── GitProviderInterface.php    # Git provider contract
│   │   │       ├── GitProviderFactory.php      # Creates git providers
│   │   │       ├── AbstractGitProvider.php     # Base git provider
│   │   │       ├── GitProviderException.php    # Provider errors
│   │   │       └── GitHubClient.php            # GitHub REST API client
│   │   │
│   │   ├── Http/
│   │   │   ├── HttpClientServiceInterface.php
│   │   │   ├── HttpClientService.php           # HTTP wrapper with timeout
│   │   │   └── HttpClientException.php
│   │   │
│   │   ├── Parser/                             # Configuration file parsers
│   │   │   ├── ConfigurationParserInterface.php
│   │   │   ├── AbstractConfigurationParser.php # Base parser
│   │   │   ├── PhpConfigurationParser.php      # PHP files via AST
│   │   │   ├── YamlConfigurationParser.php     # YAML files
│   │   │   └── Exception/
│   │   │       ├── ParseException.php
│   │   │       ├── PhpParseException.php
│   │   │       └── YamlParseException.php
│   │   │
│   │   ├── Path/                               # Path resolution service
│   │   │   ├── PathResolutionServiceInterface.php
│   │   │   ├── PathResolutionService.php       # Main orchestrator
│   │   │   ├── DTO/
│   │   │   │   ├── PathConfiguration.php
│   │   │   │   ├── PathResolutionMetadata.php
│   │   │   │   ├── PathResolutionResponse.php
│   │   │   │   ├── PathResolutionRequestBuilder.php
│   │   │   │   ├── CacheOptions.php
│   │   │   │   └── FallbackStrategy.php
│   │   │   ├── Enum/
│   │   │   │   ├── InstallationTypeEnum.php
│   │   │   │   ├── ResolutionStatusEnum.php
│   │   │   │   └── StrategyPriorityEnum.php
│   │   │   ├── Exception/
│   │   │   │   ├── PathResolutionException.php
│   │   │   │   ├── InvalidRequestException.php
│   │   │   │   ├── NoCompatibleStrategyException.php
│   │   │   │   ├── PathNotFoundException.php
│   │   │   │   └── StrategyConflictException.php
│   │   │   ├── Strategy/
│   │   │   │   ├── PathResolutionStrategyInterface.php
│   │   │   │   ├── PathResolutionStrategyRegistry.php
│   │   │   │   ├── ExtensionPathResolutionStrategy.php
│   │   │   │   ├── VendorDirPathResolutionStrategy.php
│   │   │   │   ├── WebDirPathResolutionStrategy.php
│   │   │   │   ├── Typo3ConfDirPathResolutionStrategy.php
│   │   │   │   ├── ComposerInstalledPathResolutionStrategy.php
│   │   │   │   └── PackageStatesPathResolutionStrategy.php
│   │   │   ├── Cache/
│   │   │   │   ├── PathResolutionCacheInterface.php
│   │   │   │   ├── MultiLayerPathResolutionCache.php
│   │   │   │   └── PathResolutionCacheStats.php
│   │   │   ├── Validation/
│   │   │   │   └── PathResolutionValidator.php
│   │   │   └── Recovery/
│   │   │       └── ErrorRecoveryManager.php
│   │   │
│   │   ├── Reporting/                          # Report generation
│   │   │   ├── ReportService.php               # Orchestrates reporting
│   │   │   ├── ReportContextBuilder.php        # Assembles template context
│   │   │   ├── TemplateRenderer.php            # Renders Twig templates
│   │   │   └── ReportFileManager.php           # Manages output files
│   │   │
│   │   ├── Repository/
│   │   │   ├── RepositoryUrlHandlerInterface.php
│   │   │   └── RepositoryUrlException.php
│   │   │
│   │   └── Version/
│   │       ├── ComposerConstraintCheckerInterface.php
│   │       └── ComposerConstraintChecker.php   # Composer constraint validation
│   │
│   └── Shared/                                 # SHARED LAYER — Cross-cutting utilities
│       ├── Configuration/
│       │   └── EnvironmentLoader.php           # .env file loading
│       ├── Utility/
│       │   ├── ProjectRootResolver.php         # Resolves project root
│       │   └── BinaryPathResolver.php          # Resolves external binaries
│       └── Exception/                          # (excluded from coverage)
│
├── tests/
│   ├── Unit/                                   # ~110 unit test files
│   │   ├── Application/                        # Command tests
│   │   │   ├── AnalyzerApplicationTest.php
│   │   │   └── Command/
│   │   │       ├── AnalyzeCommandTest.php
│   │   │       ├── InitConfigCommandTest.php
│   │   │       ├── ListAnalyzersCommandTest.php
│   │   │       └── ListExtensionsCommandTest.php
│   │   ├── Domain/                             # Entity and VO tests
│   │   │   ├── Entity/
│   │   │   └── ValueObject/
│   │   ├── Infrastructure/                     # Infrastructure tests
│   │   │   ├── Analyzer/                       # All analyzer tests + Rector/Fractor
│   │   │   ├── Cache/
│   │   │   ├── Configuration/
│   │   │   ├── Discovery/
│   │   │   ├── ExternalTool/
│   │   │   ├── Parser/
│   │   │   ├── Path/                           # Path resolution tests
│   │   │   └── Reporting/
│   │   └── Shared/
│   │
│   ├── Integration/                            # ~30 integration test files
│   │   ├── Fixtures/TYPO3Installations/        # 7+ TYPO3 installation fixtures
│   │   │   ├── LegacyInstallation/
│   │   │   ├── ComposerInstallation/
│   │   │   ├── v11LegacyInstallation/
│   │   │   ├── v11ComposerCustomWebDir/
│   │   │   ├── v12Composer/
│   │   │   ├── v12ComposerCustomWebDir/
│   │   │   ├── v12ComposerCustomBothDirs/
│   │   │   └── BrokenInstallation/
│   │   ├── AbstractIntegrationTestCase.php
│   │   └── [feature-specific integration tests]
│   │
│   ├── Functional/                             # End-to-end tests
│   ├── Fixtures/                               # Shared test fixtures
│   │   ├── Configuration/                      # Config file fixtures
│   │   ├── test_extension/                     # Sample extension
│   │   ├── public/
│   │   └── typo3conf/
│   └── Helper/                                 # API test helper scripts
│       ├── test_ter_api_access.php
│       ├── test_packagist_api_access.php
│       ├── test_github_api_access.php
│       ├── ter_client_test.php
│       ├── validate_git_simple.php
│       └── validate_git_support.php
│
├── resources/
│   └── templates/                              # Report templates
│       ├── html/                               # HTML report templates
│       └── md/                                 # Markdown report templates
│
├── documentation/                              # Project documentation
│   ├── INSTALLATION.md                         # Setup instructions
│   ├── USAGE.md                                # Command reference
│   ├── configuration.example.yaml              # Example configuration
│   ├── developers/
│   │   ├── INTEGRATION_TESTS.md                # Integration test strategy
│   │   └── TER_API_FINDINGS.md                 # TER API behavior docs
│   ├── review/
│   │   └── 2025-08-19_architecture-review.md   # Architecture review
│   └── implementation/
│       ├── UpgradeAnalysisTool.md              # Overall tool spec
│       ├── development/
│       │   ├── MVP.md                          # MVP plan
│       │   └── feature/                        # Feature implementation plans
│       │       ├── ConfigurationParsingFramework.md
│       │       ├── GitRepositoryVersionSupport.md
│       │       ├── RectorFindingsTracking.md
│       │       ├── ClearCacheCommand.md
│       │       ├── RefactorReportingService.md
│       │       ├── phpstanAnalyser.md
│       │       ├── InstallationDiscoverySystem.md
│       │       ├── PathResolutionService.md
│       │       └── Typo3RectorAnalyser.md
│       └── feature/planned/
│           ├── StreamingAnalyzerOutput.md       # Planned: streaming output
│           └── StreamingTemplateRendering.md    # Planned: chunked rendering
│
├── .github/
│   ├── workflows/
│   │   ├── ci.yml                              # Main CI pipeline
│   │   ├── tests.yml                           # Test execution
│   │   ├── code-quality.yml                    # Linting + static analysis
│   │   ├── api-integration.yml                 # Scheduled API tests
│   │   └── rector-test.yml                     # Rector integration tests
│   ├── dependabot.yml                          # Dependency automation
│   └── ISSUE_TEMPLATE/                         # Bug report + feature request
│
├── composer.json                               # Package definition + dependencies
├── composer.lock                               # Locked dependency versions
├── phpunit.xml                                 # PHPUnit configuration
├── phpstan.neon                                # PHPStan Level 8 config
├── .php-cs-fixer.php                           # Code style config
├── rector.php                                  # Rector configuration
├── renovate.json                               # Renovate bot config
├── sbom.json                                   # Software bill of materials
├── .editorconfig                               # Editor settings
├── .env.example                                # Environment template
├── .env.local                                  # Local environment
├── README.md                                   # Project overview
├── CONTRIBUTING.md                             # Contribution guidelines
├── CLAUDE.md                                   # AI assistant instructions
└── ChangeLog                                   # Version history
```

## Critical Directories

| Directory | Purpose | Layer |
|---|---|---|
| `src/Application/Command/` | CLI commands — primary user interaction | Application |
| `src/Domain/Entity/` | Core business entities | Domain |
| `src/Domain/ValueObject/` | Immutable domain objects | Domain |
| `src/Infrastructure/Analyzer/` | Pluggable analyzer implementations | Infrastructure |
| `src/Infrastructure/Discovery/` | TYPO3 installation and extension detection | Infrastructure |
| `src/Infrastructure/ExternalTool/` | API clients for TER, Packagist, GitHub | Infrastructure |
| `src/Infrastructure/Path/` | Path resolution with strategies and caching | Infrastructure |
| `src/Infrastructure/Reporting/` | Report generation pipeline | Infrastructure |
| `src/Infrastructure/Parser/` | Configuration file parsing (PHP, YAML) | Infrastructure |
| `config/` | Symfony DI service definitions | Configuration |
| `resources/templates/` | Twig templates for report output | Templates |
| `tests/Integration/Fixtures/` | TYPO3 installation fixtures for testing | Testing |

## File Statistics

| Category | Count |
|---|---|
| PHP source files (src/) | ~100 |
| PHP test files (tests/) | ~140 |
| CI workflow files | 5 |
| Configuration files | 7 |
| Documentation files | 15+ |
| Template files | 4+ |
