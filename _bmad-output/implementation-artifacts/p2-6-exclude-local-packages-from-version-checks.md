# Story P2-6: Exclude Local Packages from External Version Checks (Issue #149)

Status: backlog

## GitHub Issue

[#149 — Feature: Exclude local packages for external version checks](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/149)

## Placement Recommendation

This story fits best in **Epic 5** (Hardened CI/CD Pipeline Integration) alongside non-interactive mode and environment variable overrides, as it is fundamentally a configuration/filtering feature. Alternatively it can be a standalone pre-Epic-2 story if users are actively reporting it as blocking. Currently marked `backlog` — evaluate at Epic 2 kickoff.

## Story

As a developer analyzing a TYPO3 installation with local site packages,
I want to configure a list of package names to exclude from external version checks (Packagist, TER),
so that local packages that are never published to external repositories do not generate spurious "not found" errors or warnings in the report.

## Problem

Local site packages (e.g., `my-company/site-package`) are private, path-sourced, or not published to any registry. The tool currently queries Packagist and TER for every discovered extension. This produces noise: "not found on Packagist" warnings for packages that the developer intentionally keeps private.

## Acceptance Criteria

1. The analyzer configuration file (or CLI option) accepts an `excludeFromVersionChecks` list of Composer package name patterns (exact names and glob patterns like `my-company/*`).
2. Extensions matching any pattern in the list are skipped entirely for external API calls (Packagist, TER).
3. Skipped extensions are still listed in the report with a clear "excluded from version check" marker — they are not silently omitted.
4. Extensions with `"type": "path"` in `composer.json` `repositories` are optionally auto-excluded (opt-in configuration flag, default: `false`).
5. The exclusion list is documented in the config file schema and the `init-config` command generates an example entry.
6. PHPStan Level 8 zero errors, all tests green.

## Tasks / Subtasks

- [ ] Task 1: Add `excludeFromVersionChecks` to configuration schema
  - [ ] Update `ConfigurationService` / config file structure to support the new key
  - [ ] Support both exact names and glob patterns
  - [ ] Update `init-config` command to generate a commented example

- [ ] Task 2: Implement exclusion filter in `VersionAvailabilityAnalyzer`
  - [ ] Before querying Packagist or TER, check if the extension Composer name matches any exclusion pattern
  - [ ] If matched, skip API calls and set result as "excluded" with appropriate status

- [ ] Task 3: Implement auto-exclusion of `path` type sources (opt-in)
  - [ ] Read `repositories` from `composer.json` and identify `"type": "path"` entries
  - [ ] If the config flag `autoExcludePathSources: true` is set, add those package names to the exclusion set

- [ ] Task 4: Update report output
  - [ ] Excluded extensions appear in the report with a distinct "excluded" status
  - [ ] HTML report: visual badge or row style for excluded extensions
  - [ ] JSON report: `"status": "excluded"` in the extension entry

- [ ] Task 5: Tests and quality gate
  - [ ] Unit test: exclusion pattern matching (exact, glob)
  - [ ] Unit test: auto-exclusion of path sources
  - [ ] Integration test: fixture with a mix of public and excluded packages
  - [ ] `composer test` green, `composer static-analysis` zero errors

## Notes

The `path` type auto-exclusion links to P2-2 fixture work — `path` source fixtures should be covered there.
