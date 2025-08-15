# TYPO3 Upgrade Analyzer

A standalone tool for analyzing TYPO3 installations for upgrade readiness to the next major version.

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Available Analyzers](#available-analyzers)
- [Development](#development)
- [Architecture](#architecture)
- [Contributing](#contributing)

## Documentation

- 📖 **[Installation Guide](documentation/INSTALLATION.md)** - Detailed installation instructions for all environments
- 📖 **[Usage Guide](documentation/USAGE.md)** - Comprehensive command reference and configuration
- 📖 **[Contributing Guide](CONTRIBUTING.md)** - Development workflow and contribution guidelines

## Overview

The TYPO3 Upgrade Analyzer is a comprehensive tool that evaluates TYPO3 installations externally without requiring installation into the target system. It provides objective risk measures and effort estimates through automated analysis.

## Features

- **Standalone Operation**: Operates completely independently of target TYPO3 installation
- **Cross-Version Compatibility**: Works with TYPO3 versions 6.x through 13.x
- **Comprehensive Analysis**: Multiple analyzer types for thorough evaluation
- **Multiple Output Formats**: HTML and Markdown reports
- **Clean Architecture**: Follows clean architecture principles with strict separation of concerns
- **Modular Analyzer System**: Dynamic analyzer discovery with pluggable implementations
- **Advanced Static Analysis**: Integrates TYPO3 Rector and Fractor for code modernization analysis
- **External API Integration**: Checks TYPO3 Extension Repository, Packagist, and Git repositories
- **Caching Support**: Built-in caching for improved performance on repeated analyses

## Requirements

- **PHP 8.3** or higher
- **Composer** for dependency management
- **External Tools** (automatically managed via Composer):
  - `ssch/typo3-rector` for TYPO3-specific code analysis
  - `a9f/typo3-fractor` for TypoScript modernization
  - Network access for external API calls (TER, Packagist, GitHub)

## Installation

### Via Composer (Recommended)

```bash
# For development/local use
composer require --dev cpsit/typo3-upgrade-analyser

# For global installation
composer global require cpsit/typo3-upgrade-analyser
```

### From Source

```bash
git clone https://github.com/cpsit/typo3-upgrade-analyser.git
cd typo3-upgrade-analyser
composer install
```

## Quick Start

1. **Create Configuration File**:
   ```bash
   ./bin/typo3-analyzer init-config
   ```

2. **Analyze TYPO3 Installation**:
   ```bash
   ./bin/typo3-analyzer analyze
   ```

3. **View Results**: Check the `var/reports/` directory for HTML and Markdown reports.

For detailed usage instructions, see the **[Usage Guide](documentation/USAGE.md)**.

## Available Analyzers

### Currently Implemented

- **Version Availability Analyzer** (`version_availability`)
  - Checks extension compatibility across TER, Packagist, and Git repositories
  - Analyzes version constraints and upgrade paths
  - Provides risk scoring based on availability

- **TYPO3 Rector Analyzer** (`typo3_rector`)
  - Uses `ssch/typo3-rector` for TYPO3-specific code analysis
  - Detects deprecated API usage and required migrations
  - Provides automated refactoring suggestions

- **Fractor Analyzer** (`fractor`)
  - Uses `a9f/typo3-fractor` for TypoScript modernization
  - Analyzes TypoScript patterns and suggests improvements
  - Detects outdated TypoScript syntax

- **Lines of Code Analyzer** (`lines_of_code`)
  - Counts total lines of code, comments, and blank lines
  - Calculates code complexity metrics
  - Provides maintenance effort estimates

### Analyzer Features

- **Dynamic Discovery**: All analyzers are automatically discovered and registered
- **Caching Support**: Results are cached to improve performance on repeated runs
- **Configurable**: Each analyzer can be enabled/disabled and configured independently
- **Extensible**: New analyzers can be added by implementing `AnalyzerInterface`

## Output Structure

The analyzer creates a comprehensive output structure in the configured output directory:

```
var/
├── log/
│   └── typo3-upgrade-analyzer.log      # Detailed execution logs
├── reports/
│   ├── html/                  # HTML reports with interactive features
│   │   ├── analysis-report.html        # Main overview report
│   │   └── extensions/                 # Individual extension reports
│   │       ├── extension1.html
│   │       └── extension2.html
│   └── md/                    # Markdown reports
│       ├── analysis-report.md          # Main overview in Markdown
│       └── extensions/                 # Individual extension reports
│           ├── extension1.md
│           └── extension2.md
├── results/                   # Cached analysis results
└── temp/                      # Temporary files (Rector/Fractor configs)
```

### Report Contents

- **Main Report**: High-level overview with summary statistics and risk assessment
- **Detailed Report**: Comprehensive analysis results for each extension including:
  - Version availability across repositories
  - Code quality metrics and complexity
  - Required migrations and refactoring opportunities
  - Risk scores and upgrade recommendations

## Development

### Requirements

- **PHP 8.3** or higher
- **Composer** for dependency management
- **Git** for version control

### Setup

```bash
git clone https://github.com/cpsit/typo3-upgrade-analyser.git
cd typo3-upgrade-analyser
composer install
```

### Available Scripts

```bash
# Testing
composer test              # Run unit tests only (fast)
composer test:unit         # Run unit tests
composer test:integration  # Run integration tests
composer test:functional   # Run functional tests
composer test:coverage     # Generate coverage report

# External API tests (require network access)
composer test:ter-api      # Test TER API integration
composer test:github-api   # Test GitHub API integration
composer test:real-world   # Test with real TYPO3 installations

# Code Quality
composer lint              # Run all linting checks
composer lint:php          # Check PHP coding standards
composer lint:composer     # Check composer.json format
composer fix               # Fix all code quality issues
composer fix:php           # Fix PHP coding standards
composer sca:php           # Run PHPStan static analysis (Level 8)
```

### Testing Strategy

- **Unit Tests**: Fast, isolated tests with mocking and VFS (Virtual File System)
- **Integration Tests**: Test component interactions with real dependencies
- **Functional Tests**: End-to-end testing of complete workflows
- **API Tests**: Real-world testing against external APIs (TER, Packagist, GitHub)
- **Performance Tests**: Benchmark critical operations

## Architecture

The tool follows a clean architecture with clear separation of concerns:

- **Application Layer**: Console commands and application services
- **Domain Layer**: Core business logic and entities
- **Infrastructure Layer**: External integrations and implementations
- **Shared Layer**: Common utilities and configuration

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Ensure all tests pass
6. Submit a pull request

## License

This project is licensed under the GPL-2.0-or-later license. See the LICENSE file for details.

## Support
-
- [Issue Tracker](https://github.com/cpsit/typo3-upgrade-analyser/issues)
