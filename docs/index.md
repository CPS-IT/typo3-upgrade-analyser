# TYPO3 Upgrade Analyzer — Documentation Index

**Generated:** 2026-03-20
**Scan Level:** Exhaustive
**Project Type:** Monolith CLI Tool (PHP 8.3+)

## Project Overview

- **Package:** `cpsit/typo3-upgrade-analyser`
- **Type:** Standalone PHP CLI Tool
- **Architecture:** Clean Architecture (Application / Domain / Infrastructure / Shared)
- **Primary Language:** PHP 8.3+
- **Framework:** Symfony Components (Console, DI, HttpClient, Process)
- **Entry Point:** `bin/typo3-analyzer`

## Quick Reference

- **Tech Stack:** PHP 8.3+ / Symfony 7.0 / Twig 3.8 / PHPUnit 12.3 / PHPStan Level 8
- **Entry Point:** `bin/typo3-analyzer`
- **Architecture Pattern:** Clean Architecture with DI container, strategy pattern, plugin system
- **Analyzers:** 4 (VersionAvailability, Typo3Rector, Fractor, LinesOfCode)
- **External APIs:** TER, Packagist, GitHub

## Generated Documentation

- [Project Overview](./project-overview.md) — Purpose, tech stack, capabilities, project status
- [Architecture](./architecture.md) — Layer structure, design patterns, data flow, DI configuration
- [Source Tree Analysis](./source-tree-analysis.md) — Annotated directory tree, critical directories, file statistics
- [Component Inventory](./component-inventory.md) — All commands, analyzers, services, templates, entities, value objects
- [Development Guide](./development-guide.md) — Setup, testing, code quality, CI/CD, contribution workflow

## Existing Project Documentation

- [README.md](../README.md) — Project overview and quick start
- [CONTRIBUTING.md](../CONTRIBUTING.md) — Branch strategy, code standards, PR requirements
- [CLAUDE.md](../CLAUDE.md) — AI assistant behavioral instructions
- [ChangeLog](../ChangeLog) — Version history

### User Documentation

- [Installation Guide](../documentation/INSTALLATION.md) — Setup instructions
- [Usage Guide](../documentation/USAGE.md) — Command reference and configuration
- [Example Configuration](../documentation/configuration.example.yaml) — Sample YAML config

### Developer Documentation

- [Integration Tests](../documentation/developers/INTEGRATION_TESTS.md) — Integration test strategy
- [TER API Findings](../documentation/developers/TER_API_FINDINGS.md) — TER API behavior documentation
- [Architecture Review](../documentation/review/2025-08-19_architecture-review.md) — Architecture review (Aug 2025)

### Implementation Plans

- [MVP Plan](../documentation/implementation/development/MVP.md) — Minimum viable product plan
- [Overall Tool Specification](../documentation/implementation/UpgradeAnalysisTool.md) — Full tool specification

#### Feature Specifications (Implemented / In Progress)

- [Configuration Parsing Framework](../documentation/implementation/development/feature/ConfigurationParsingFramework.md)
- [Git Repository Version Support](../documentation/implementation/development/feature/GitRepositoryVersionSupport.md)
- [Rector Findings Tracking](../documentation/implementation/development/feature/RectorFindingsTracking.md)
- [Clear Cache Command](../documentation/implementation/development/feature/ClearCacheCommand.md)
- [Refactor Reporting Service](../documentation/implementation/development/feature/RefactorReportingService.md)
- [PHPStan Analyser](../documentation/implementation/development/feature/phpstanAnalyser.md)
- [Installation Discovery System](../documentation/implementation/development/feature/InstallationDiscoverySystem.md)
- [Path Resolution Service](../documentation/implementation/development/feature/PathResolutionService.md)
- [TYPO3 Rector Analyser](../documentation/implementation/development/feature/Typo3RectorAnalyser.md)

#### Feature Specifications (Planned)

- [Streaming Analyzer Output](../documentation/implementation/feature/planned/StreamingAnalyzerOutput.md) — File-based streaming for large output (High priority)
- [Streaming Template Rendering](../documentation/implementation/feature/planned/StreamingTemplateRendering.md) — Chunked rendering for large datasets (Medium priority)

## GitHub Issues

Repository: [CPS-IT/typo3-upgrade-analyser](https://github.com/CPS-IT/typo3-upgrade-analyser)

### Open Issues

| # | Title | Labels |
|---|---|---|
| [#163](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/163) | Extension discovery fails for TYPO3 10 with PackageStates v5 | bug |
| [#150](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/150) | List direct and indirect extensions | enhancement |
| [#149](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/149) | Exclude local packages for external version checks | enhancement |
| [#7](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/7) | Dependency Dashboard | — |

## Getting Started

```bash
composer install
./bin/typo3-analyzer analyze /path/to/typo3
./bin/typo3-analyzer list-analyzers
```

See [Development Guide](./development-guide.md) for full setup and contribution instructions.
