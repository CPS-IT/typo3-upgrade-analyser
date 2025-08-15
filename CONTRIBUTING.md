# Contributing to TYPO3 Upgrade Analyzer

Thank you for your interest in contributing to the TYPO3 Upgrade Analyzer! This document provides guidelines and instructions for contributors.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Workflow](#development-workflow)
- [Architecture Guidelines](#architecture-guidelines)
- [Testing Requirements](#testing-requirements)
- [Code Quality Standards](#code-quality-standards)
- [Documentation Guidelines](#documentation-guidelines)
- [Pull Request Process](#pull-request-process)

## Code of Conduct

This project follows the TYPO3 Community Code of Conduct. By participating, you agree to uphold this code.

## Getting Started

### Prerequisites

- **PHP 8.3** or higher
- **Composer** for dependency management
- **Git** for version control
- Basic understanding of TYPO3 architecture and upgrade processes

### Development Setup

1. **Fork and Clone**:
   ```bash
   git clone https://github.com/your-username/typo3-upgrade-analyser.git
   cd typo3-upgrade-analyser
   ```

2. **Install Dependencies**:
   ```bash
   composer install
   ```

3. **Verify Setup**:
   ```bash
   composer test
   composer sca:php
   composer lint
   ```

## Development Workflow

### Branch Strategy

- `main`: Production-ready code
- `develop`: Integration branch for features
- `feature/*`: Feature development branches
- `bugfix/*`: Bug fix branches

### Feature Development Process

1. **Create Feature Branch**:
   ```bash
   git checkout develop
   git pull origin develop
   git checkout -b feature/your-feature-name
   ```

2. **Plan Implementation**:
   - Create detailed plan in `documentation/implementation/development/feature/YourFeature.md`
   - Include architecture decisions, API design, and testing strategy

3. **Implement with Tests First**:
   - Write comprehensive tests before implementation (TDD approach)
   - Ensure all tests pass before committing as [WIP]

4. **Commit Standards**:
   ```bash
   [WIP] Add initial implementation of feature
   Fix failing integration tests and enhance analyzer functionality
   Add comprehensive documentation for new analyzer
   ```

### Naming Conventions

- **Classes**: PascalCase (`VersionAvailabilityAnalyzer`)
- **Methods**: camelCase (`analyzeExtension()`)
- **Constants**: SCREAMING_SNAKE_CASE (`ANALYZER_NAME`)
- **Files**: Match class names
- **Directories**: PascalCase for namespaces, lowercase for configs

## Architecture Guidelines

### Clean Architecture Principles

The project follows clean architecture with strict layer separation:

```
src/
├── Application/     # Console commands, application services
├── Domain/          # Core business logic, entities, value objects
├── Infrastructure/  # External integrations, implementations
└── Shared/          # Common utilities, configuration
```

### Layer Dependencies

- **Application** → **Domain** + **Infrastructure**
- **Infrastructure** → **Domain**
- **Domain** → No external dependencies
- **Shared** → Can be used by any layer

### Key Patterns

1. **Dependency Injection**: All services use constructor injection
2. **Interface Segregation**: Prefer small, focused interfaces
3. **Immutable Value Objects**: Domain objects should be immutable
4. **Single Responsibility**: Each class has one clear purpose

### Adding New Analyzers

1. **Implement AnalyzerInterface**:
   ```php
   class YourAnalyzer implements AnalyzerInterface
   {
       public function getName(): string { }
       public function getDescription(): string { }
       public function supports(Extension $extension): bool { }
       public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult { }
       public function getRequiredTools(): array { }
   }
   ```

2. **Register in services.yaml**:
   ```yaml
   YourAnalyzer:
     tags: ['analyzer']
   ```

3. **Add Comprehensive Tests**:
   - Unit tests with mocking
   - Integration tests with real dependencies
   - Performance tests for heavy operations

## Testing Requirements

### Test Structure

```
tests/
├── Unit/           # Isolated unit tests with mocking
├── Integration/    # Component integration tests
└── Functional/     # End-to-end workflow tests
```

### Testing Standards

1. **Coverage Requirements**:
   - Minimum 80% line coverage
   - 100% coverage for critical business logic
   - Exclude exception classes from coverage

2. **Test Categories**:
   ```php
   /**
    * @group unit
    */
   class YourClassTest extends TestCase { }

   /**
    * @group integration
    * @group ter-api
    */
   class ApiIntegrationTest extends TestCase { }
   ```

3. **Mocking Guidelines**:
   - Mock external dependencies (HTTP clients, file system)
   - Use VFS (Virtual File System) for file operations
   - Mock time-dependent operations

### Running Tests

```bash
# Fast unit tests only
composer test

# All test suites
composer test:unit
composer test:integration
composer test:functional

# External API tests (require network)
composer test:ter-api
composer test:github-api
composer test:real-world

# Coverage reporting
composer test:coverage
```

## Code Quality Standards

### PHP Standards

- **PHP Version**: 8.3+ with strict types
- **Coding Standard**: PSR-12 with custom rules
- **Static Analysis**: PHPStan Level 8 (maximum strictness)

### Code Quality Tools

1. **PHP CS Fixer**:
   ```bash
   composer lint:php    # Check violations
   composer fix:php     # Fix violations
   ```

2. **PHPStan Analysis**:
   ```bash
   composer sca:php     # Run static analysis
   ```

3. **Composer Validation**:
   ```bash
   composer lint:composer  # Check composer.json
   composer fix:composer   # Fix formatting
   ```

### Quality Requirements

- **No warnings** in PHPStan analysis
- **Zero violations** in coding standards
- **All tests passing** before merge
- **Documentation** for all public APIs

## Documentation Guidelines

### Code Documentation

1. **Class Documentation**:
   ```php
   /**
    * Analyzer that checks version availability across repositories.
    *
    * This analyzer queries TER, Packagist, and Git repositories to determine
    * if compatible versions of extensions exist for the target TYPO3 version.
    */
   class VersionAvailabilityAnalyzer { }
   ```

2. **Method Documentation**:
   ```php
   /**
    * Analyzes extension version availability.
    *
    * @param Extension $extension The extension to analyze
    * @param AnalysisContext $context Analysis context with target version
    * @return AnalysisResult Analysis results with risk scoring
    * @throws AnalyzerException When external API calls fail
    */
   public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult
   ```

### Feature Documentation

For new features, create detailed documentation in `documentation/implementation/development/feature/`:

1. **Problem Statement**: What problem does this solve?
2. **Architecture Design**: How does it fit into the system?
3. **API Specification**: Public interfaces and contracts
4. **Testing Strategy**: How will it be tested?
5. **Implementation Notes**: Key decisions and trade-offs

## Pull Request Process

### Before Submitting

1. **Ensure Quality**:
   ```bash
   composer test:coverage  # Verify test coverage
   composer lint           # Check code style
   composer sca:php        # Run static analysis
   ```

2. **Update Documentation**:
   - Update README.md if adding new features
   - Add/update inline documentation
   - Create feature documentation if applicable

3. **Test Thoroughly**:
   - Run full test suite including integration tests
   - Test with real TYPO3 installations if relevant
   - Verify no performance regressions

### PR Requirements

1. **Clear Description**:
   - What changes are included?
   - Why are these changes needed?
   - How have they been tested?

2. **Quality Checklist**:
   - [ ] All tests passing
   - [ ] Code coverage maintained/improved
   - [ ] PHPStan Level 8 compliance
   - [ ] No coding standard violations
   - [ ] Documentation updated
   - [ ] Feature branch up to date with develop

3. **Review Process**:
   - At least one code review required
   - All CI checks must pass
   - No merge conflicts

### Merge Strategy

- **Squash and merge** for feature branches
- **Merge commit** for release branches
- **Linear history** required in main/develop branches

## Release Process

1. **Version Bumping**: Follow semantic versioning (SemVer)
2. **Changelog**: Update CHANGELOG.md with new features/fixes
3. **Testing**: Full regression testing on multiple TYPO3 versions
4. **Documentation**: Update installation and usage docs
5. **Release**: Tag and create GitHub release

## Getting Help

- **Issues**: Use GitHub issues for bug reports and feature requests

## Recognition

Contributors will be recognized in:
- CHANGELOG.md for significant contributions
- README.md contributors section
- Git commit history

Thank you for contributing to making TYPO3 upgrades easier for everyone!
