# Usage Guide

This comprehensive guide explains how to use the TYPO3 Upgrade Analyzer effectively for analyzing TYPO3 installations and planning upgrades.

## Table of Contents

- [Quick Start](#quick-start)
- [Command Reference](#command-reference)
- [Configuration](#configuration)
- [Analyzers](#analyzers)
- [Reports](#reports)
- [Advanced Usage](#advanced-usage)
- [Best Practices](#best-practices)

## Quick Start

### 1. Initial Setup

```bash
# Create configuration file
./bin/typo3-analyzer init-config

# Edit the generated configuration
nano typo3-analyzer.yaml
```

### 2. Basic Analysis

```bash
# Analyze TYPO3 installation with default settings
./bin/typo3-analyzer analyze

# View generated reports
open var/reports/html/main-report.html
```

### 3. Review Results

The analyzer generates comprehensive reports showing:
- Extension compatibility across repositories
- Code quality metrics and complexity
- Required migrations and refactoring opportunities
- Risk scores and upgrade recommendations

## Command Reference

### Core Commands

#### `init-config` - Initialize Configuration

Creates a new configuration file with sensible defaults.

```bash
# Create default configuration
./bin/typo3-analyzer init-config

# Create configuration interactively
./bin/typo3-analyzer init-config --interactive

# Create configuration in custom location
./bin/typo3-analyzer init-config --output=/custom/path/config.yaml
```

**Options:**
- `--interactive`, `-i`: Interactive configuration wizard
- `--output=PATH`, `-o`: Custom configuration file path

#### `analyze` - Run Analysis

Performs comprehensive analysis of a TYPO3 installation.

```bash
# Basic analysis using default configuration
./bin/typo3-analyzer analyze

# Use custom configuration file
./bin/typo3-analyzer analyze --config=/path/to/config.yaml

# Run specific analyzers only
./bin/typo3-analyzer analyze --analyzers=version_availability,fractor

```

**Options:**
- `--config=PATH`, `-c`: Configuration file path
- `--analyzers=LIST`, `-a`: Comma-separated list of analyzers to run
- `--verbose`, `-v`: Verbose output
- `--quiet`, `-q`: Suppress output

#### `list-analyzers` - List Available Analyzers

Shows all available analyzers and their current status.

```bash
# List all analyzers
./bin/typo3-analyzer list-analyzers
```

#### `list-extensions` - List Discovered Extensions

Shows all extensions discovered in the TYPO3 installation.

```bash
# List all discovered extensions
./bin/typo3-analyzer list-extensions

# List all discovered extension with custom config
./bin/typo3-analyzer list-extensions --config=path/to/config.yaml
```

**Options:**
- `--config=PATH`: Custom configuration file

#### `cache-clear` - Clear Cached Results

Removes all cached analysis results. Run this when you want to force a fresh analysis, for example after updating extensions or changing the target version.

```bash
./bin/typo3-analyzer cache-clear
```

## Configuration

### Configuration File Structure

The default configuration file is `typo3-analyzer.yaml` in the project root. Run `init-config` to generate it. Set `analysis.installationPath` before running `analyze`.

```yaml
analysis:
  installationPath: '/path/to/your/typo3/installation'
  targetVersion: '13.4'
  resultCache:
    enabled: true
    ttl: 3600
  phpVersions:
    - '8.3'
    - '8.4'
  analyzers:
    version_availability:
      enabled: true
      sources: [ter, packagist, git]
      timeout: 30
    static_analysis:
      enabled: true
      tools:
        phpstan:
          level: 6
          config: null
    deprecation_scanner:
      enabled: true
    tca_migration:
      enabled: true
    code_quality:
      enabled: true
      complexity_threshold: 10
      loc_threshold: 1000
    typo3_rector:
      enabled: true
    fractor:
      enabled: true

reporting:
  formats: [markdown]
  output_directory: var/reports/
  includeCharts: false

externalTools:
  rector:
    binary: vendor/bin/rector
    config: null
  fractor:
    binary: vendor/bin/fractor
    config: null
  typoscript_lint:
    binary: vendor/bin/typoscript-lint
    config: typoscript-lint.yml
```

### Configuration Templates

#### Minimal Template

```yaml
# Minimal configuration — version availability only
analysis:
  installationPath: '/path/to/typo3'
  targetVersion: '13.4'
  analyzers:
    version_availability:
      enabled: true
      sources: [ter, packagist]
    typo3_rector:
      enabled: false
    fractor:
      enabled: false
reporting:
  formats: [markdown]
  output_directory: var/reports/
```

#### Comprehensive Template

```yaml
# Comprehensive analysis configuration
analysis:
  installationPath: '/path/to/typo3'
  targetVersion: '13.4'
  analyzers:
    version_availability:
      enabled: true
      sources: [ter, packagist, git]
      timeout: 60
    typo3_rector:
      enabled: true
    fractor:
      enabled: true
reporting:
  formats: [html, markdown, json]
  output_directory: var/reports/
  includeCharts: false
```

## Analyzers

### Version Availability Analyzer (`version_availability`)

Checks extension compatibility across different repositories.

**Purpose**: Determine if extensions have compatible versions available for the target TYPO3 version.

**Data Sources**:
- TYPO3 Extension Repository (TER)
- Packagist (Composer packages)
- Git repositories (GitHub, GitLab)

**Configuration**:
```yaml
analysis:
  analyzers:
    version_availability:
      enabled: true
      sources: [ter, packagist, git]   # Sources to check
      timeout: 30                       # Timeout for external operations (seconds)
```

**Output**:
- Availability status for each repository
- Compatible version information
- Risk scoring based on availability
- Recommendations for upgrade path

> **Known TER API limitations (active bugs)**
>
> - [#650](https://git.typo3.org/services/t3o-sites/extensions.typo3.org/ter/-/issues/650): `typo3_versions` only includes LTS major versions. Extensions compatible with a non-LTS TYPO3 release (e.g. v14) will appear incompatible in TER results even if their `dependencies` field covers it.
> - [#653](https://git.typo3.org/services/t3o-sites/extensions.typo3.org/ter/-/issues/653): As of 2026-04-03, `typo3_versions` is empty for all extension versions returned by the TER API — a regression beyond #650.
>
> Include `packagist` and `git` in `sources` to compensate. TER results alone are not reliable for TYPO3 v14+ targets until these bugs are resolved upstream.

### TYPO3 Rector Analyzer (`typo3_rector`)

Uses TYPO3 Rector to analyze code for deprecated API usage and required migrations.

**Purpose**: Identify code that needs to be updated for TYPO3 version compatibility.

**Features**:
- Detects deprecated TYPO3 API usage
- Suggests automated refactoring
- Provides migration rules and recommendations
- Estimates refactoring effort

**Configuration**:
```yaml
analysis:
  analyzers:
    typo3_rector:
      enabled: true
```

**Output**:
- List of deprecated API usage
- Required migration rules
- Automated refactoring suggestions
- Code complexity analysis

### Fractor Analyzer (`fractor`)

Analyzes TypoScript for modernization opportunities.

**Purpose**: Identify outdated TypoScript patterns and suggest modern alternatives.

**Features**:
- TypoScript syntax analysis
- Modernization suggestions
- Performance improvements
- Best practice recommendations

**Configuration**:
```yaml
analysis:
  analyzers:
    fractor:
      enabled: true
```

**Output**:
- TypoScript modernization opportunities
- Performance improvement suggestions
- Syntax error detection
- Best practice violations

### Lines of Code Analyzer (`lines_of_code`)

Analyzes codebase size and complexity metrics.

**Purpose**: Provide metrics for maintenance effort estimation and code quality assessment.

**Features**:
- Total lines of code counting
- Code vs comments vs blank lines
- File type breakdown
- Complexity estimation

**Configuration**:
```yaml
analysis:
  analyzers:
    code_quality:
      enabled: true
      complexity_threshold: 10
      loc_threshold: 1000
```

**Output**:
- Total lines of code
- Code distribution by file type
- Comment ratio
- Maintenance effort estimation

## Reports

### Report Formats

#### HTML Reports

Interactive HTML reports with:
- Responsive design for all devices
- Interactive charts and graphs
- Sortable and filterable tables
- Detailed drill-down capabilities
- Export functionality

**Location**: `var/reports/html/`

#### Markdown Reports

Readable markdown format for:
- Documentation integration
- Version control tracking
- Easy sharing and collaboration
- Automated processing

**Location**: `var/reports/md/`

#### JSON Export (Optional)

Machine-readable format for:
- Custom report generation
- Integration with other tools
- Automated processing pipelines
- API consumption

**Location**: `var/reports/json/`

### Report Structure

#### Main Report

High-level overview including:
- Installation summary
- Overall risk assessment
- Extension count and categories
- Analyzer execution summary
- Key recommendations

#### Detailed Report

Comprehensive analysis results:
- Per-extension analysis results
- Detailed risk scoring
- Specific recommendations
- Code examples and fixes
- Migration paths

#### Extension Detail Report

Individual extension analysis:
- Version availability across repositories
- Code quality metrics
- Required migrations
- Effort estimation
- Upgrade recommendations

## Advanced Usage

### CI/CD Integration

Integrate analysis into continuous integration:

```yaml
# .github/workflows/typo3-analysis.yml
name: TYPO3 Upgrade Analysis
on: [push, pull_request]

jobs:
  analyze:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Install analyzer
        run: composer global require cpsit/typo3-upgrade-analyser

      - name: Run analysis
        run: typo3-analyzer analyze --config=ci-config.yaml

      - name: Archive reports
        uses: actions/upload-artifact@v3
        with:
          name: analysis-reports
          path: var/reports/
```

### Custom Analyzer Development

Create custom analyzers for specific needs:

```php
<?php
namespace Custom\Analyzer;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerInterface;
use CPSIT\UpgradeAnalyzer\Domain\Entity\{Extension, AnalysisResult};
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;

class CustomSecurityAnalyzer implements AnalyzerInterface
{
    public function getName(): string
    {
        return 'security_check';
    }

    public function getDescription(): string
    {
        return 'Custom security vulnerability analysis';
    }

    public function supports(Extension $extension): bool
    {
        return $extension->getType() === 'custom';
    }

    public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        // Custom analysis logic
        return new AnalysisResult(/* ... */);
    }

    public function getRequiredTools(): array
    {
        return ['security-scanner'];
    }
}
```

### Performance Optimization

For large installations, disable slow analyzers for an initial run and enable them incrementally:

```yaml
# config/performance-optimized.yaml
analysis:
  analyzers:
    version_availability:
      enabled: true
      sources: [ter, packagist]   # Omit git for faster initial run
    typo3_rector:
      enabled: false
    fractor:
      enabled: false
```

## Best Practices

### 1. Incremental Analysis

Start with lightweight analyzers and gradually enable more comprehensive ones:

```bash
# Phase 1: Basic analysis
./bin/typo3-analyzer analyze --analyzers=lines_of_code,version_availability

# Phase 2: Add static analysis
./bin/typo3-analyzer analyze --analyzers=lines_of_code,version_availability,fractor

# Phase 3: Full analysis
./bin/typo3-analyzer analyze  # All analyzers enabled
```

### 2. Regular Monitoring

Set up regular analysis runs for continuous monitoring:

```bash
# Monthly comprehensive analysis
0 3 1 * * /path/to/typo3-analyzer analyze --config=comprehensive-config.yaml
```

### 3. Version-Specific Analysis

Analyze for multiple TYPO3 versions to plan an upgrade strategy
using different configurations for the target versions.
