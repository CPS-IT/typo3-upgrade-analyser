# TYPO3 Upgrade Analyzer

A standalone tool for analyzing TYPO3 installations for upgrade readiness to the next major version.

## Overview

The TYPO3 Upgrade Analyzer is a comprehensive tool that evaluates TYPO3 installations externally without requiring installation into the target system. It provides objective risk measures and effort estimates through automated analysis.

## Features

- **Standalone Operation**: Operates completely independently of target TYPO3 installation
- **Cross-Version Compatibility**: Works with TYPO3 versions 6.x through 13.x
- **Comprehensive Analysis**: Multiple analyzer types for thorough evaluation
- **Multiple Output Formats**: HTML, JSON, CSV, and Markdown reports
- **Modular Architecture**: Extensible plugin system for custom analyzers

## Installation

### Via Composer (Global)

```bash
composer global require cpsit/typo3-upgrade-analyser
```

### Via Composer (Local)

```bash
composer require --dev cpsit/typo3-upgrade-analyser
```

### Via PHAR (Coming Soon)

```bash
wget https://github.com/cpsit/typo3-upgrade-analyser/releases/latest/typo3-analyzer.phar
chmod +x typo3-analyzer.phar
```

## Usage

### Basic Analysis

```bash
# Analyze a TYPO3 installation
typo3-analyzer analyze /path/to/typo3

# Analyze for specific target version
typo3-analyzer analyze /path/to/typo3 --target-version=13.0

# Generate specific report formats
typo3-analyzer analyze /path/to/typo3 --format=json --format=html
```

### Configuration

```bash
# Generate a configuration file
typo3-analyzer init-config

# Generate configuration interactively
typo3-analyzer init-config --interactive

# Use custom configuration
typo3-analyzer analyze /path/to/typo3 --config=custom-config.yaml
```

### Validation

```bash
# Validate TYPO3 installation
typo3-analyzer validate /path/to/typo3

# List available analyzers
typo3-analyzer list-analyzers
```

## Available Analyzers

- **Version Availability**: Checks if compatible versions exist in TER, Packagist, or Git
- **Static Analysis**: Runs PHPStan and other static analysis tools
- **Lines of Code**: Counts lines of code and calculates complexity metrics
- **PHP Compatibility**: Checks PHP version compatibility
- **Deprecation Scanner**: Scans for deprecated TYPO3 API usage
- **TCA Migration**: Checks for required TCA migrations
- **Rector Analysis**: Checks for available Rector migration rules
- **Fractor Analysis**: Analyzes TypoScript for modernization opportunities
- **TypoScript Lint**: Validates TypoScript configuration
- **Test Coverage**: Analyzes existing test coverage

## Output Structure

```
tests/upgradeAnalysis/
├── configuration/
│   ├── extensions.json        # Discovered extensions
│   ├── installation.json      # Installation metadata
│   └── analysis-config.yaml   # Used configuration
├── results/
│   ├── raw/                   # Raw analyzer results
│   ├── processed/             # Processed results with scores
│   └── summary.json           # Overall results summary
├── reports/
│   ├── html/                  # Interactive HTML reports
│   ├── json/                  # Machine-readable reports
│   ├── markdown/              # Documentation format
│   └── csv/                   # Spreadsheet exports
└── logs/
    ├── analysis.log           # Detailed execution log
    └── errors.log             # Errors and warnings
```

## Development

### Requirements

- PHP 8.1 or higher
- Composer

### Setup

```bash
git clone https://github.com/cpsit/typo3-upgrade-analyser.git
cd typo3-upgrade-analyser
composer install
```

### Testing

```bash
# Run all tests
composer test

# Run specific test suite
vendor/bin/phpunit tests/Unit

# Generate coverage report
vendor/bin/phpunit --coverage-html var/coverage
```

### Code Quality

```bash
# Check code style
composer cs:check

# Fix code style
composer cs:fix

# Run static analysis
composer static-analysis
```

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

- [Documentation](https://docs.cpsit.de/typo3-upgrade-analyser/)
- [Issue Tracker](https://github.com/cpsit/typo3-upgrade-analyser/issues)
- [TYPO3 Community](https://typo3.org/community)