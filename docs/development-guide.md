# Development Guide — TYPO3 Upgrade Analyzer

## Prerequisites

- PHP 8.3 or higher
- Composer 2.x
- Git

## Installation

```bash
git clone <repository-url>
cd typo3-upgrade-analyser
composer install
```

## Environment Setup

```bash
# Copy environment template
cp .env.example .env.local

# Edit .env.local with your settings (API tokens, paths, etc.)
```

## Running the Application

```bash
# Analyze a TYPO3 installation
./bin/typo3-analyzer analyze /path/to/typo3

# Analyze for specific target version
./bin/typo3-analyzer analyze /path/to/typo3 --target-version=13.0

# Generate configuration file
./bin/typo3-analyzer init-config

# Validate TYPO3 installation
./bin/typo3-analyzer validate /path/to/typo3

# List available analyzers
./bin/typo3-analyzer list-analyzers

# List discovered extensions
./bin/typo3-analyzer list-extensions --config=config.yaml
```

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run specific test suites
composer test:unit
composer test:integration
composer test:functional

# Run with coverage report
composer test:coverage

# Run specific test file
vendor/bin/phpunit tests/Unit/Infrastructure/Analyzer/VersionAvailabilityAnalyzerTest.php
```

### Test Configuration

PHPUnit is configured in `phpunit.xml`:
- Three test suites: Unit, Integration, Functional
- Coverage reports: HTML (`var/coverage/html/`) and Clover XML (`var/coverage/clover.xml`)
- Exception classes excluded from coverage requirements
- Environment variables for API timeouts and rate limiting

### Test Fixtures

TYPO3 installation fixtures are in `tests/Integration/Fixtures/TYPO3Installations/`:
- `LegacyInstallation` — Traditional TYPO3 installation
- `ComposerInstallation` — Composer-based installation
- `v11LegacyInstallation` — TYPO3 v11 legacy
- `v12Composer` — TYPO3 v12 Composer
- `v12ComposerCustomWebDir` — Custom web directory
- `v12ComposerCustomBothDirs` — Custom vendor + web dirs
- `BrokenInstallation` — Invalid installation for error testing

### API Integration Tests

```bash
# Run API-specific tests (requires network)
composer test:ter-api
composer test:github-api
composer test:real-world
```

## Code Quality

### Static Analysis (PHPStan Level 8)

```bash
# Run PHPStan
composer sca:php

# Or directly
vendor/bin/phpstan analyse --configuration=phpstan.neon
```

Configuration in `phpstan.neon`:
- Level 8 (maximum strictness)
- PHP version: 8.3
- Analyzes `src/` and `tests/`
- Excludes fixtures and test helpers
- PHPUnit extension enabled

### Code Style (PHP-CS-Fixer)

```bash
# Check code style (dry-run)
composer cs:check
# Or: composer lint:php

# Fix code style issues
composer cs:fix
# Or: composer fix
```

Configuration in `.php-cs-fixer.php`:
- PSR-12 + Symfony standards
- Risky rules enabled (strict comparison, native function invocation)
- PHP 8.1 migration rules
- Covers `src/` and `tests/` (excluding Fixtures, Helper)

### Rector

```bash
# Check for code migration issues
composer lint:rector
```

### All Quality Checks

```bash
# Run all linting checks
composer lint
```

## Adding New Analyzers

1. Create implementation in `src/Infrastructure/Analyzer/`:

```php
namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer;

class MyNewAnalyzer extends AbstractCachedAnalyzer
{
    public function getName(): string { return 'my-new-analyzer'; }
    public function getDescription(): string { return 'Description of what it does'; }
    public function supports(AnalysisContext $context): bool { return true; }
    public function getRequiredTools(): array { return []; }
    public function hasRequiredTools(): bool { return true; }

    protected function doAnalyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        // Implementation here
    }
}
```

2. Register in `config/services.yaml` with the `analyzer` tag (or rely on auto-discovery)
3. Add unit tests in `tests/Unit/Infrastructure/Analyzer/`
4. Analyzers are auto-discovered — no manual registration in the application needed

## Adding New Commands

1. Create command class in `src/Application/Command/`
2. Extend Symfony's `Command` class
3. Register in `AnalyzerApplication` constructor
4. Commands are automatically available as public services via DI

## Branch Strategy

- `main` — Production-ready
- `develop` — Integration branch
- `feature/*` — Feature development
- `bugfix/*` — Bug fixes

## Commit Message Convention

Use tags:
- `[FEATURE]` — New feature
- `[TASK]` — General task
- `[BUGFIX]` — Bug fix
- `[DOC]` — Documentation
- `[CI]` — CI/CD changes
- `[WIP]` — Work in progress
- `[SECURITY]` — Security fix
- `[DRAFT]` — Draft work
- `[DDEV]` — DDEV-related

## Feature Development Process

1. Create detailed plan in `documentation/implementation/development/feature/<FeatureName>.md`
2. Create feature branch `feature/<featureName>`
3. Implement with TDD approach (tests first)
4. Ensure all tests pass and PHPStan Level 8 compliance
5. Commit as `[WIP]` during development
6. Create pull request when feature is complete

## CI/CD Pipeline

GitHub Actions workflows:
- **ci.yml** — Main CI: validation, linting, tests, security audit (PHP 8.3/8.4 matrix)
- **tests.yml** — Unit, integration, functional, performance tests with coverage
- **code-quality.yml** — PHP-CS-Fixer, PHPStan, Rector, EditorConfig, Composer normalize
- **api-integration.yml** — Weekly scheduled API integration tests (TER, GitHub, Packagist)
- **rector-test.yml** — Rector integration with multiple TYPO3 version upgrade scenarios

## Quality Requirements

- PHPStan Level 8 compliance (zero errors)
- Zero code style violations
- Minimum 80% line coverage
- 100% coverage for critical business logic
- All tests passing before merge
