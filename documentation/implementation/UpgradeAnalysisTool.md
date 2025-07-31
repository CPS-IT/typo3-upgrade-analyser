# TYPO3 Upgrade Analysis Tool - Implementation Plan

## Project Overview

This document outlines the implementation plan for a **standalone** TYPO3 Upgrade Analysis Tool that evaluates TYPO3 installations externally without requiring installation into the target system. The tool operates independently as a composer package or PHAR application and analyzes TYPO3 installations through file system analysis, database inspection, and external tool integration.

## Core Design Principles

### Standalone Architecture
- **Zero TYPO3 Dependencies**: Tool operates completely independently of target TYPO3 installation
- **External Analysis**: Analyzes installations through file system and database inspection
- **Cross-Version Compatibility**: Works with TYPO3 versions 11.x through 13.x
- **No System Modification**: Read-only analysis with no changes to target installation

### Technical Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    CLI Application Layer                    │
│                   (Symfony Console)                         │
├─────────────────────────────────────────────────────────────┤
│                  Application Service Layer                  │
│  ┌───────────────┐ ┌──────────────┐ ┌──────────────────┐   │
│  │   Discovery   │ │   Analysis   │ │    Reporting     │   │
│  │   Service     │ │   Service    │ │    Service       │   │
│  └───────────────┘ └──────────────┘ └──────────────────┘   │
├─────────────────────────────────────────────────────────────┤
│                     Domain Layer                            │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────────────┐   │
│  │ Installation│ │  Extension  │ │      Report         │   │
│  │   Entity    │ │   Entity    │ │     Entity          │   │
│  └─────────────┘ └─────────────┘ └─────────────────────┘   │
├─────────────────────────────────────────────────────────────┤
│                 Infrastructure Layer                        │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────────────┐   │
│  │  External   │ │ File System │ │   Database          │   │
│  │   Tools     │ │   Parser    │ │   Analyzer          │   │
│  └─────────────┘ └─────────────┘ └─────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## Package Structure

```
typo3-upgrade-analyzer/
├── bin/
│   └── typo3-analyzer              # Executable entry point
├── src/
│   ├── Application/                # Application services
│   │   ├── Service/                # Core application services
│   │   ├── Command/                # Console commands
│   │   └── Handler/                # Event handlers
│   ├── Domain/                     # Business logic
│   │   ├── Entity/                 # Domain entities
│   │   ├── Service/                # Domain services
│   │   ├── Repository/             # Repository interfaces
│   │   ├── Event/                  # Domain events
│   │   └── ValueObject/            # Value objects
│   ├── Infrastructure/             # External concerns
│   │   ├── Discovery/              # TYPO3 installation discovery
│   │   ├── Parser/                 # Configuration parsers
│   │   ├── Analyzer/               # Analysis implementations
│   │   ├── ExternalTool/           # Tool integrations
│   │   ├── Database/               # Database analysis
│   │   ├── FileSystem/             # File operations
│   │   └── Report/                 # Report generators
│   └── Shared/                     # Shared utilities
│       ├── Configuration/          # Configuration handling
│       ├── Logger/                 # Logging utilities
│       └── Exception/              # Exception classes
├── config/                         # Configuration files
│   ├── services.yaml               # DI container configuration
│   ├── analyzers.yaml              # Analyzer configurations
│   └── templates/                  # Report templates
├── resources/                      # Static resources
│   ├── templates/                  # Report templates
│   └── assets/                     # Static assets
├── tests/                          # Test suite
├── composer.json                   # Package definition
└── README.md                       # Documentation
```

## Core Components

### 1. TYPO3 Installation Discovery

The discovery system identifies TYPO3 installations through multiple strategies:

```php
interface InstallationDiscoveryInterface
{
    public function discover(string $path): ?Installation;
    public function supports(string $path): bool;
    public function getRequiredFiles(): array;
}

class ComposerBasedDiscovery implements InstallationDiscoveryInterface
{
    public function discover(string $path): ?Installation
    {
        $composerPath = $path . '/composer.json';
        if (!file_exists($composerPath)) {
            return null;
        }

        $composer = json_decode(file_get_contents($composerPath), true);

        // Check for TYPO3 dependencies
        if ($this->hasTYPO3Dependency($composer)) {
            return $this->buildInstallation($path, $composer);
        }

        return null;
    }
}
```

### 2. External Configuration Parsing

Parse TYPO3 configurations without TYPO3 APIs:

```php
interface ConfigurationParserInterface
{
    public function parse(Installation $installation): Configuration;
    public function supports(string $typo3Version): bool;
}

class LocalConfigurationParser implements ConfigurationParserInterface
{
    public function parse(Installation $installation): Configuration
    {
        $configPath = $installation->getPath() . '/typo3conf/LocalConfiguration.php';

        // Parse PHP configuration file without executing it
        $ast = $this->phpParser->parse(file_get_contents($configPath));

        return $this->extractConfiguration($ast);
    }
}
```

### 3. External Analysis Framework

Pluggable analyzer system for external analysis:

```php
interface ExternalAnalyzerInterface
{
    public function getName(): string;
    public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult;
    public function getRequiredTools(): array;
    public function supports(Extension $extension): bool;
}

// Example: Version Availability Analyzer
class VersionAvailabilityAnalyzer implements ExternalAnalyzerInterface
{
    public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $result = new AnalysisResult($this->getName());

        // Check TER via API
        $terVersion = $this->terApiClient->getLatestVersion(
            $extension->getKey(),
            $context->getTargetVersion()
        );

        // Check Packagist via API
        $packagistVersion = $this->packagistClient->getLatestVersion(
            $extension->getComposerName(),
            $context->getTargetVersion()
        );

        // Check Git repositories
        $gitVersion = $this->gitAnalyzer->checkRemoteVersion($extension);

        $result->addMetric('ter_available', $terVersion !== null);
        $result->addMetric('packagist_available', $packagistVersion !== null);
        $result->addMetric('git_available', $gitVersion !== null);

        return $result;
    }
}
```

## Implementation Phases

### Phase 1: Foundation and Discovery (Weeks 1-2)

#### 1.1 Project Setup
- [ ] Create standalone composer package structure
- [ ] Set up Symfony Console application framework
- [ ] Implement dependency injection container
- [ ] Create basic CLI command structure
- [ ] Set up automated testing framework
- [ ] Set up github actions

#### 1.2 Installation Discovery System
- [ ] **Composer-based Discovery**: Detect TYPO3 through composer.json
- [ ] **Installation Validation**: Verify discovered installations are valid
- [ ] **Version Detection**: Determine TYPO3 version from files

#### 1.3 Configuration Parsing Framework
- [ ] **LocalConfiguration Parser**: Parse TYPO3 main configuration
- [ ] **Extension Configuration Parser**: Parse ext_localconf.php, Service.yaml files
- [ ] **TCA Parser**: Parse TCA files without TYPO3 bootstrap
- [ ] **TypoScript Parser**: Static TypoScript parsing
- [ ] **Package Manager Parser**: Parse PackageStates.php

### Phase 2: Core Analysis Infrastructure (Weeks 3-4)

#### 2.1 Extension Discovery and Metadata
- [ ] **Extension Scanner**: Discover all extensions in installation
- [ ] **Metadata Extraction**: Extract extension metadata from composer.json and ext_emconf.php
- [ ] **Dependency Analysis**: Parse extension dependencies
- [ ] **File Structure Analysis**: Analyze extension directory structure
- [ ] **Extension Classification**: Categorize extensions (system, local, TER)

#### 2.2 Database Analysis (Without TYPO3)
- [ ] **Database Connection**: Direct database connection handling
- [ ] **Schema Analysis**: Analyze database schema without TYPO3
- [ ] **TCA Validation**: Validate TCA against actual database structure
- [ ] **Migration Detection**: Detect required database migrations
- [ ] **Data Analysis**: Analyze content for compatibility issues

#### 2.3 File System Analysis
- [ ] **Code Scanner**: Scan PHP files for patterns and usage
- [ ] **Template Analysis**: Analyze Fluid templates and partials
- [ ] **Asset Analysis**: Analyze CSS, JS, and image assets
- [ ] **Configuration Analysis**: Parse YAML, XML configuration files
- [ ] **Documentation Scanning**: Extract inline documentation

### Phase 3: External Tool Integration (Weeks 5-6)

#### 3.1 Static Analysis Tools
- [ ] **PHPStan Integration**: Run static analysis on extensions
- [ ] **PHP CS Fixer Integration**: Code style analysis
- [ ] **PHPMD Integration**: Mess detection and complexity analysis
- [ ] **Custom Rule Sets**: TYPO3-specific analysis rules
- [ ] **Result Aggregation**: Combine results from multiple tools

#### 3.2 TYPO3-Specific Analysis Tools
- [ ] **Rector Integration**: Check for available migrations
- [ ] **Fractor Integration**: TypoScript modernization analysis
- [ ] **TypoScript Lint Integration**: TypoScript validation
- [ ] **TYPO3 Deprecation Scanner**: Custom deprecation detection
- [ ] **API Usage Scanner**: Scan for TYPO3 API usage patterns

#### 3.3 Code Quality Analysis
- [ ] **Lines of Code Counter**: Implement comprehensive code metrics
- [ ] **Cyclomatic Complexity**: Calculate method and class complexity
- [ ] **Change Risk Analysis**: Identify anti-patterns and code smells
- [ ] **Test Coverage Analysis**: Extract coverage from existing reports
- [ ] **Performance Impact**: Estimate performance implications

### Phase 4: Analysis Execution Engine (Weeks 7-8)

#### 4.1 Analysis Pipeline
- [ ] **Sequential Processing**: Process extensions one by one
- [ ] **Parallel Processing**: Analyze multiple extensions simultaneously
- [ ] **Progress Tracking**: Real-time progress indicators
- [ ] **Error Handling**: Graceful error handling and recovery
- [ ] **Caching System**: Cache expensive analysis results

#### 4.2 External API Integration
- [ ] **TER API Client**: Query TYPO3 Extension Repository
- [ ] **Packagist API Client**: Query Packagist for Composer packages
- [ ] **GitHub API Client**: Query Git repositories for versions
- [ ] **Rate Limiting**: Handle API rate limits gracefully
- [ ] **Offline Mode**: Work with cached data when APIs unavailable

#### 4.3 Result Processing
- [ ] **Result Aggregation**: Combine results from all analyzers
- [ ] **Risk Calculation**: Calculate risk scores for extensions
- [ ] **Effort Estimation**: Estimate upgrade effort based on metrics
- [ ] **Priority Ranking**: Rank extensions by upgrade priority
- [ ] **Dependency Impact**: Calculate impact of dependency changes

### Phase 5: Reporting and Output (Weeks 9-10)

#### 5.1 Report Generation System
- [ ] **Template Engine**: Twig-based template system
- [ ] **Multi-format Output**: HTML, JSON, XML, CSV, Markdown
- [ ] **Interactive Reports**: HTML reports with JavaScript interactivity
- [ ] **Chart Generation**: Charts and visualizations using Chart.js
- [ ] **Export Capabilities**: Export to various external formats

#### 5.2 Report Types
- [ ] **Extension-specific Reports**: Detailed analysis per extension
- [ ] **Summary Reports**: High-level installation overview
- [ ] **Risk Assessment Reports**: Focus on high-risk areas
- [ ] **Migration Roadmap**: Prioritized upgrade action plan
- [ ] **Progress Tracking**: Compare analyses over time

#### 5.3 CLI Interface Enhancement
- [ ] **Interactive Mode**: Step-by-step guided analysis
- [ ] **Configuration Wizard**: Generate analysis configurations
- [ ] **Batch Processing**: Analyze multiple installations
- [ ] **Watch Mode**: Monitor installations for changes
- [ ] **Integration Hooks**: Webhook and CI/CD integration

## Key Analyzers Implementation Detail

### 1. Version Availability Analyzer
```php
class VersionAvailabilityAnalyzer implements ExternalAnalyzerInterface
{
    private TerApiClient $terClient;
    private PackagistClient $packagistClient;
    private GitAnalyzer $gitAnalyzer;

    public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $targetVersion = $context->getTargetVersion();
        $result = new AnalysisResult($this->getName());

        // TER availability check
        $terAvailable = $this->terClient->hasVersionFor($extension->getKey(), $targetVersion);
        $result->addMetric('ter_available', $terAvailable);

        // Packagist availability check
        if ($extension->hasComposerName()) {
            $packagistAvailable = $this->packagistClient->hasVersionFor(
                $extension->getComposerName(),
                $targetVersion
            );
            $result->addMetric('packagist_available', $packagistAvailable);
        }

        // Git repository check
        if ($extension->hasGitRepository()) {
            $gitCompatible = $this->gitAnalyzer->checkCompatibility(
                $extension->getGitUrl(),
                $targetVersion
            );
            $result->addMetric('git_compatible', $gitCompatible);
        }

        // Calculate availability risk
        $riskScore = $this->calculateAvailabilityRisk($result->getMetrics());
        $result->setRiskScore($riskScore);

        return $result;
    }
}
```

### 2. TYPO3 API Deprecation Scanner
```php
class DeprecationScanner implements ExternalAnalyzerInterface
{
    private DeprecationDatabase $deprecationDb;
    private PhpParser $parser;

    public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $result = new AnalysisResult($this->getName());
        $deprecations = [];

        // Scan all PHP files in extension
        foreach ($extension->getPhpFiles() as $phpFile) {
            $ast = $this->parser->parse(file_get_contents($phpFile));

            // Find deprecated API calls
            $visitor = new DeprecationVisitor(
                $this->deprecationDb->getDeprecationsFor($context->getTargetVersion())
            );

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $deprecations = array_merge($deprecations, $visitor->getDeprecations());
        }

        $result->addMetric('deprecation_count', count($deprecations));
        $result->addMetric('deprecations', $deprecations);

        // Calculate deprecation risk
        $riskScore = $this->calculateDeprecationRisk($deprecations);
        $result->setRiskScore($riskScore);

        return $result;
    }
}
```

### 3. TCA Migration Checker
```php
class TcaMigrationChecker implements ExternalAnalyzerInterface
{
    private TcaParser $tcaParser;
    private MigrationRuleSet $migrationRules;

    public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $result = new AnalysisResult($this->getName());
        $migrations = [];

        // Parse TCA files
        foreach ($extension->getTcaFiles() as $tcaFile) {
            $tca = $this->tcaParser->parse($tcaFile);

            // Check for required migrations
            $requiredMigrations = $this->migrationRules->check(
                $tca,
                $context->getCurrentVersion(),
                $context->getTargetVersion()
            );

            $migrations = array_merge($migrations, $requiredMigrations);
        }

        $result->addMetric('required_migrations', $migrations);
        $result->addMetric('migration_count', count($migrations));

        // Calculate migration complexity
        $complexityScore = $this->calculateMigrationComplexity($migrations);
        $result->setRiskScore($complexityScore);

        return $result;
    }
}
```

## Configuration System

### Analysis Configuration
```yaml
# config/analysis.yaml
analysis:
  target_version: "12.4"
  php_versions: ["8.1", "8.2", "8.3"]

  analyzers:
    version_availability:
      enabled: true
      sources: ["ter", "packagist", "github"]
      timeout: 30

    static_analysis:
      enabled: true
      tools:
        phpstan:
          level: 6
          config: null
        php_cs_fixer:
          rules: "@TYPO3"

    deprecation_scanner:
      enabled: true
      database_path: "resources/deprecations.json"

    tca_migration:
      enabled: true
      migration_rules: "resources/tca-migrations.yaml"

    code_quality:
      enabled: true
      complexity_threshold: 10
      loc_threshold: 1000

reporting:
  formats: ["html", "json"]
  output_directory: "tests/upgradeAnalysis"
  template_directory: "resources/templates"
  include_charts: true

external_tools:
  rector:
    binary: "vendor/bin/rector"
    config: null
  fractor:
    binary: "vendor/bin/fractor"
    config: null
  typoscript_lint:
    binary: "vendor/bin/typoscript-lint"
    config: "typoscript-lint.yml"
```

## CLI Usage Examples

```bash
# Analyze single TYPO3 installation
./bin/typo3-analyzer analyze /path/to/typo3 --target-version=12.4

# Analyze with specific configuration
./bin/typo3-analyzer analyze /path/to/typo3 --config=custom-config.yaml

# Generate only JSON report
./bin/typo3-analyzer analyze /path/to/typo3 --format=json

# Analyze multiple installations
./bin/typo3-analyzer batch-analyze /path/to/projects --pattern="*/htdocs"

# Generate configuration file
./bin/typo3-analyzer init-config --interactive

# List available analyzers
./bin/typo3-analyzer list-analyzers

# Validate TYPO3 installation
./bin/typo3-analyzer validate /path/to/typo3
```

## Output Structure

```
tests/upgradeAnalysis/
├── configuration/
│   ├── extensions.json        # Discovered extensions
│   ├── installation.json      # Installation metadata
│   └── analysis-config.yaml   # Used configuration
├── results/
│   ├── raw/                   # Raw analyzer results
│   │   ├── version_availability.json
│   │   ├── static_analysis.json
│   │   └── deprecation_scanner.json
│   ├── processed/             # Processed results with scores
│   └── summary.json           # Overall results summary
├── reports/
│   ├── html/                  # Interactive HTML reports
│   │   ├── index.html
│   │   ├── extensions/        # Per-extension reports
│   │   └── assets/            # CSS, JS, images
│   ├── json/                  # Machine-readable reports
│   ├── markdown/              # Documentation format
│   └── csv/                   # Spreadsheet exports
└── logs/
    ├── analysis.log           # Detailed execution log
    └── errors.log             # Errors and warnings
```

## Deployment Options

### 1. Composer Package
```bash
composer global require typo3/upgrade-analyzer
typo3-analyzer analyze /path/to/installation
```

### 2. PHAR Distribution
```bash
wget https://github.com/typo3/upgrade-analyzer/releases/latest/typo3-analyzer.phar
php typo3-analyzer.phar analyze /path/to/installation
```

### 3. Docker Container
```bash
docker run -v /path/to/typo3:/app typo3/upgrade-analyzer analyze /app
```

## Integration Capabilities

### CI/CD Integration
```yaml
# .github/workflows/typo3-analysis.yml
- name: TYPO3 Upgrade Analysis
  run: |
    composer global require typo3/upgrade-analyzer
    typo3-analyzer analyze . --format=json --output=analysis-results.json
    # Process results in CI pipeline
```

### Webhook Integration
```bash
# Trigger analysis on deployment
typo3-analyzer analyze /var/www/typo3 --webhook=https://example.com/webhook
```

## Security and Safety

### Read-Only Operation
- All operations are read-only by default
- No modifications to target TYPO3 installation
- Safe to run on production systems
- Comprehensive logging of all operations

### External Tool Sandboxing
- External tools run in isolated processes
- Resource limits and timeouts
- Secure temporary file handling
- Clean up after analysis completion

## Performance Considerations

### Parallel Processing
- Multiple extensions analyzed simultaneously
- Configurable concurrency limits
- Progress reporting for long-running analyses
- Memory-efficient processing for large installations

### Caching Strategy
- Cache expensive external API calls
- Persistent result caching between runs
- Incremental analysis for changed files only
- Configurable cache retention policies

## Success Metrics

1. **Analysis Accuracy**: Reliable detection of upgrade issues
2. **Performance**: Fast analysis of large TYPO3 installations
3. **Compatibility**: Works with all supported TYPO3 versions
4. **Usability**: Clear, actionable upgrade recommendations
5. **Extensibility**: Easy addition of new analysis capabilities

## Conclusion

This standalone implementation provides a comprehensive, external TYPO3 upgrade analysis tool that operates independently of the target system. The architecture ensures safety, reliability, and extensibility while providing deep insights into upgrade readiness and required efforts.

The tool can be deployed as a composer package, PHAR archive, or Docker container, making it suitable for various development and operations workflows. The modular design allows for easy customization and extension while maintaining compatibility across TYPO3 versions.
