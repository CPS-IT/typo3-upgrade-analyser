# Story 1.8: Fix Composer Installation Detection — Replace Filesystem Indicators

Status: done

## Story

As a developer,
I want `ComposerInstallationDetector::supports()` to determine TYPO3 presence from Composer artifacts (`composer.json`, `composer.lock`, `vendor/composer/installed.json`) instead of runtime filesystem directories,
so that detection works reliably on fresh Composer installs that have never been booted and CI passes without synthetic fixture directories.

## Acceptance Criteria

1. `supports()` determines TYPO3 presence solely from `composer.json` content (`require`/`require-dev` contains `typo3/cms-core`, `typo3/cms`, or `typo3/minimal`) — the filesystem indicator threshold (`foundIndicators >= 2`) is removed entirely. No `composer.lock` or `vendor/composer/installed.json` checks.
2. `composer.json` is parsed once per `detect()` call; the decoded array is passed to all downstream methods that need it. No method reads `composer.json` independently.
3. v14 and v13 fixture integration tests pass in CI without `var/log/.gitkeep` being required for detection. Fixture files like `config/system/settings.php` remain for metadata method tests but are not required for `supports()` to return `true`. Metadata methods (`detectDatabaseConfig`, `detectEnabledFeatures`) must handle absent files gracefully (return empty results, no exceptions).
4. All existing v11/v12/v13/v14 integration and unit tests pass. Integration tests for each version must be run after each task, not only at the end.
5. PHPStan Level 8 reports zero errors, `composer lint:php` reports zero violations.

## Tasks / Subtasks

- [x] Task 1: Parse `composer.json` once and pass decoded data (AC: 2)
  - [x] Add a private method `parseComposerJson(string $path): ?array` that reads and decodes `composer.json` once, returning `null` on missing/invalid file
  - [x] Refactor `detect()` to call `parseComposerJson()` at the start and pass the result to `supports()`, `hasTypo3Packages()`, `getWebDirectoryForSupportsCheck()`, `detectPhpVersions()`, `determineInstallationType()`, and `detectCustomPaths()`
  - [x] Remove all independent `file_get_contents`/`json_decode` calls from individual methods
  - [x] Update method signatures to accept `?array $composerData` parameter where needed
  - [x] Run all integration tests (v11/v12/v13/v14) to verify no regressions

- [x] Task 2: Replace filesystem indicators with composer.json package check (AC: 1)
  - [x] Remove the `$typo3Indicators` array and `$foundIndicators >= 2` threshold from `supports()`
  - [x] Extend `hasTypo3Packages()` to accept the decoded `composer.json` array (no re-read)
  - [x] Update `supports()` logic: `is_dir($path)` + `composer.json` exists + `hasTypo3Packages()` passes — nothing else
  - [x] Update unit tests for `supports()` to reflect simplified detection
  - [x] Run all integration tests (v11/v12/v13/v14) to verify no regressions

- [x] Task 3: Ensure metadata methods handle absent fixture files (AC: 3)
  - [x] Verify `detectDatabaseConfig()` returns empty array when `config/system/settings.php` is absent (it already does — confirm with test)
  - [x] Verify `detectEnabledFeatures()` returns empty array when directories are absent (it already does — confirm with test)
  - [x] Run `Typo3V14ComposerDiscoveryTest` and `Typo3V13ComposerDiscoveryTest` (if exists) in CI-equivalent state (no `var/log/`, no `public/typo3/`)

- [x] Task 4: Quality gate (AC: 4, 5)
  - [x] `composer test` — all tests green
  - [x] `composer sca` — zero PHPStan Level 8 errors
  - [x] `composer lint:php` — zero violations

## Dev Notes

### Root Cause: CI Failures on v14 Integration Tests

`Typo3V14ComposerDiscoveryTest` fails in GitHub Actions because `supports()` requires 2 of 5 filesystem indicators (`typo3conf`, `{webDir}/typo3conf`, `{webDir}/typo3`, `config/system`, `var/log`). These are runtime artifacts that don't exist after `composer install`. Locally, leftover `var/log/typo3.log` and `public/typo3/` directories accidentally satisfy the threshold.

The `.gitignore` rule `*.log` (line 43) prevents `var/log/typo3.log` from being tracked. Adding `.gitkeep` files is a workaround, not a fix.

### Detection Strategy: What Is Reliable

Detection relies solely on `composer.json` direct requirements: if `require` or `require-dev` contains `typo3/cms-core`, `typo3/cms`, or `typo3/minimal`, it is a TYPO3 Composer installation. If not, it is not.

`composer.lock` and `vendor/composer/installed.json` are not checked. They are generated artifacts that may be absent (`.gitignored`, fresh clone before `composer install`). Relying on them introduces the same class of problem we are fixing. Distribution packages that transitively pull in TYPO3 without a direct `composer.json` requirement are out of scope — the standard TYPO3 setup always lists a core package directly.

Filesystem directories (`var/log`, `config/system`, `public/typo3`) are runtime artifacts created by TYPO3's bootstrap or `typo3 setup`. They are NOT reliable for detection.

### Fixture Files and Metadata Methods

Fixture files like `config/system/settings.php` remain in fixtures for metadata method tests (`detectDatabaseConfig`, `detectEnabledFeatures`). These methods already return empty results when files are absent — this story verifies that behaviour with tests. The key constraint: `supports()` must not depend on these files.

### Existing Code Patterns to Follow

- `VersionExtractor` demonstrates the single-parse + pass-down pattern
- `DetectionStrategyInterface::getRequiredIndicators()` returns `['composer.json']` — this pre-filter remains correct
- Unit tests use `sys_get_temp_dir()` + real filesystem; mock `VersionExtractor` and `PathResolutionServiceInterface`
- Integration tests use fixtures in `tests/Integration/Fixtures/TYPO3Installations/`

### Key Files to Modify

| File | Change |
|------|--------|
| `src/Infrastructure/Discovery/ComposerInstallationDetector.php` | Remove indicator threshold, single-parse refactor |
| `tests/Unit/Infrastructure/Discovery/ComposerInstallationDetectorTest.php` | Update unit tests for new detection logic |
| `tests/Integration/Discovery/Typo3V14ComposerDiscoveryTest.php` | Verify CI-green without runtime dirs |

### Out of Scope (deferred to story 1.9)

- Web-dir path traversal validation (P3)
- `detectEnabledFeatures()` hardcoded `public/typo3conf/ext` (P4)
- Version-aware paths in `detectDatabaseConfig`/`detectEnabledFeatures` (P5)
- Docker classification removal from `determineInstallationType` (P6)
- Narrowing `catch (\Throwable)` to `catch (\RuntimeException)` (P7)
- Deferred findings D1–D5

### References

- [Source: code-review-composer-installation-detector-2026-03-24.md — findings P1, P8]
- [Source: src/Infrastructure/Discovery/ComposerInstallationDetector.php — lines 109–148 (supports), 178–218 (hasTypo3Packages)]
- [Source: tests/Unit/Infrastructure/Discovery/ComposerInstallationDetectorTest.php]
- [Source: tests/Integration/Discovery/Typo3V14ComposerDiscoveryTest.php]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- Added `parseComposerJson(string $path): ?array` — reads and JSON-decodes `composer.json` once, returns null on missing/invalid file; log warning on parse failure.
- Added `supportsInternal(string $path, ?array $composerData): bool` — contains the actual supports logic; called by both `supports()` and `detect()` to avoid double-parsing.
- `detect()` now calls `parseComposerJson()` once at entry, then passes `$composerData` to `supportsInternal()`, `createInstallationMetadata()`, and all downstream private methods.
- Removed `$typo3Indicators` array and `$foundIndicators >= 2` threshold. Detection now relies solely on `composer.json` `require`/`require-dev` containing `typo3/cms-core`, `typo3/cms`, or `typo3/minimal`.
- Removed dead `getWebDirectoryForSupportsCheck()` method (no longer called after indicator removal).
- All private methods (`hasTypo3Packages`, `detectPhpVersions`, `determineInstallationType`, `detectCustomPaths`, `createInstallationMetadata`) updated to accept `?array $composerData`.
- Unit tests updated: renamed `testSupportsReturnsFalseWithInsufficientTypo3Indicators` to `testSupportsReturnsFalseWithComposerJsonMissingTypo3Packages`; removed unnecessary `createFullTypo3Directory()` calls from supports tests; added `supportsReturnsTrueWithJustComposerJsonContainingTypo3Package` to confirm no runtime dirs needed.
- Added `detectReturnsDatabaseConfigEmptyWhenSettingsPhpAbsent` and `detectReturnsEnabledFeaturesEmptyWhenRuntimeDirsAbsent` to confirm graceful empty-return behaviour.
- All 1647 tests pass; PHPStan Level 8 zero errors; PHP-CS-Fixer zero violations.

### File List

- `src/Infrastructure/Discovery/ComposerInstallationDetector.php` (modified)
- `tests/Unit/Infrastructure/Discovery/ComposerInstallationDetectorTest.php` (modified)

## Change Log

- 2026-03-24: Implemented story 1.8 — replaced filesystem indicator threshold with pure composer.json package check; single-parse refactor for detect() call path; added graceful-absence tests for metadata methods. All 1647 tests green, PHPStan Level 8 zero errors, PHP-CS-Fixer zero violations.
