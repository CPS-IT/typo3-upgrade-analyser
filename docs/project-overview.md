# TYPO3 Upgrade Analyzer — Project Overview

**Package:** `cpsit/typo3-upgrade-analyser`
**Type:** Standalone PHP CLI Tool
**License:** GPL-2.0-or-later
**Author:** Dirk Wenzel (CPSIT)
**PHP Version:** ^8.3
**Status:** Phase 1 Foundation Complete; Phase 2 Core Discovery and Parsing in progress

## Purpose

The TYPO3 Upgrade Analyzer is a standalone command-line tool that analyzes TYPO3 installations for upgrade readiness to the next major version. It operates independently of the target TYPO3 installation and provides objective risk measures and effort estimates through automated analysis.

## Key Capabilities

- **Installation Discovery**: Automatically detects TYPO3 installations (Composer-based and legacy), determines current version, discovers all installed extensions
- **Extension Analysis**: Runs multiple analyzers per extension — version availability (TER, Packagist, Git), code migration analysis (Rector, Fractor), and lines-of-code complexity metrics
- **Risk Scoring**: Calculates risk scores per extension based on analysis results, with human-readable risk levels and recommendations
- **Report Generation**: Produces reports in HTML, Markdown, JSON, and CSV formats with per-extension detail pages and summary overviews

## Architecture Classification

| Property | Value |
|---|---|
| Repository Type | Monolith |
| Architecture Style | Clean Architecture (4-layer) |
| Primary Language | PHP 8.3+ |
| Framework | Symfony Components (Console, DI, Config, HttpClient, Process) |
| Template Engine | Twig 3.8 |
| DI Container | Symfony DI with YAML configuration and auto-wiring |
| Testing Framework | PHPUnit 12.3 |
| Static Analysis | PHPStan Level 8 |
| Code Style | PHP-CS-Fixer (PSR-12 + Symfony) |

## Technology Stack

| Category | Technology | Version | Purpose |
|---|---|---|---|
| Language | PHP | ^8.3 | Primary language |
| CLI Framework | Symfony Console | 7.0 | Command-line interface |
| DI Container | Symfony DI | 7.0 | Service wiring |
| HTTP Client | Guzzle | 7.8 | API calls (TER, Packagist, GitHub) |
| HTTP Client | Symfony HttpClient | 7.0 | Alternative HTTP transport |
| Template Engine | Twig | 3.8 | Report rendering |
| PHP Parser | nikic/php-parser | 5.0 | AST analysis |
| Code Migration | ssch/typo3-rector | 3.6 | TYPO3 deprecated code detection |
| TypoScript Migration | a9f/typo3-fractor | 0.5.6 | TypoScript modernization |
| Logging | Monolog | 3.5 | Structured logging |
| Testing | PHPUnit | 12.3 | Unit/Integration/Functional tests |
| Static Analysis | PHPStan | 2.0 | Level 8 strictness |
| Code Style | PHP-CS-Fixer | 3.45 | PSR-12 + Symfony |
| VFS | mikey179/vfsstream | 1.6 | Virtual filesystem for tests |

## External Integrations

| Service | Client | Purpose |
|---|---|---|
| TYPO3 Extension Repository (TER) | `TerApiClient` | Extension metadata and version lookup |
| Packagist | `PackagistClient` | Composer package availability |
| GitHub API | `GitHubClient` | Repository metadata, tags, health |

## Project Structure (High Level)

```
typo3-upgrade-analyser/
├── bin/typo3-analyzer          # CLI entry point
├── config/services.yaml        # Symfony DI configuration
├── src/
│   ├── Application/            # Console commands, app entry point
│   ├── Domain/                 # Entities, Value Objects, Contracts
│   ├── Infrastructure/         # Analyzers, API clients, parsers, reporting
│   └── Shared/                 # Utilities, environment, container factory
├── tests/
│   ├── Unit/                   # ~110 test files
│   ├── Integration/            # ~30 test files with TYPO3 fixtures
│   └── Functional/             # End-to-end tests
├── resources/templates/        # Twig templates (HTML, Markdown)
└── documentation/              # Implementation plans, developer docs
```

## Current Analyzers

| Analyzer | Purpose | External Dependencies |
|---|---|---|
| VersionAvailabilityAnalyzer | Checks extension availability across TER, Packagist, Git | TER API, Packagist API, GitHub API |
| Typo3RectorAnalyzer | Detects deprecated code patterns and breaking changes | ssch/typo3-rector binary |
| FractorAnalyzer | Detects TypoScript modernization opportunities | a9f/typo3-fractor binary |
| LinesOfCodeAnalyzer | Measures codebase complexity per extension | None (file system scan) |

## Development Phase Status

### Phase 1: Foundation (Complete)
- CLI application framework with Symfony Console
- Dependency injection with auto-wiring
- Analyzer plugin system with auto-discovery
- Version availability analysis (TER + Packagist + Git)
- Rector and Fractor integration
- Lines of code analysis
- Multi-format report generation
- Path resolution service with 6 strategies
- Configuration parsing (PHP and YAML)
- Cache service with TTL
- Comprehensive test suite

### Phase 2: Core Discovery and Parsing (In Progress)
- Installation discovery coordinator
- Enhanced Composer installation detection
- Multi-strategy version extraction
- Extension scanner improvements
- Configuration parsing framework enhancements

### Planned Features
- **StreamingAnalyzerOutput**: File-based streaming for large analyzer output to prevent memory exhaustion (High priority)
- **StreamingTemplateRendering**: Chunked template rendering for large datasets (Medium priority)

## Getting Started

```bash
# Install dependencies
composer install

# Run the analyzer
./bin/typo3-analyzer analyze /path/to/typo3

# Run with specific target version
./bin/typo3-analyzer analyze /path/to/typo3 --target-version=13.0

# Generate configuration file
./bin/typo3-analyzer init-config

# List available analyzers
./bin/typo3-analyzer list-analyzers

# List discovered extensions
./bin/typo3-analyzer list-extensions --config=config.yaml
```

## Links to Detailed Documentation

- [Architecture](./architecture.md)
- [Source Tree Analysis](./source-tree-analysis.md)
- [Development Guide](./development-guide.md)
- [Existing Documentation Index](../documentation/)
