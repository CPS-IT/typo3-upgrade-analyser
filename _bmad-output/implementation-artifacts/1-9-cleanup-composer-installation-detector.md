# Story 1.9: Cleanup ComposerInstallationDetector — Hardening and Correctness

Status: done

## Story

As a developer,
I want `ComposerInstallationDetector` to use version-aware paths, validate external input, and avoid misclassification,
so that metadata detection is correct across TYPO3 v11–v14 and the code is robust against malformed input.

## Acceptance Criteria

1. Web-dir values from `composer.json` `extra.typo3/cms.web-dir` are validated: must be a relative path, must not contain `..`, must not be absolute. Invalid values fall back to `public`.
2. `detectEnabledFeatures()` uses the resolved web-dir instead of hardcoding `public/typo3conf/ext`.
3. `detectDatabaseConfig()` and `detectEnabledFeatures()` use version-aware paths: `config/system/settings.php` for v12+, `typo3conf/LocalConfiguration.php` for v11.
4. `determineInstallationType()` does not return `DOCKER_CONTAINER` based on `docker-compose.yml` or `Dockerfile` presence. Docker is orthogonal to installation type.
5. The `catch (\Throwable)` in `detect()` is narrowed to `\RuntimeException` (or a custom exception). `Error` subclasses propagate.
6. All existing v11/v12/v13/v14 integration and unit tests pass. No regressions.
7. PHPStan Level 8 reports zero errors, `composer lint:php` reports zero violations.

## Tasks / Subtasks

- [x] Task 1: Validate web-dir path (AC: 1)
  - [x] Add path validation in `getWebDirectoryForSupportsCheck()`: reject absolute paths (`/` or `\` prefix), reject `..` segments, reject empty string
  - [x] Fall back to `public` on invalid web-dir
  - [x] Add unit tests for path traversal attempts and empty string

- [x] Task 2: Version-aware path resolution in metadata methods (AC: 2, 3)
  - [x] Refactor `detectEnabledFeatures()` to accept resolved web-dir and version; check `{webDir}/typo3conf/ext` for v12+, `typo3conf/ext` for v11
  - [x] Refactor `detectDatabaseConfig()` to accept version; check `config/system/settings.php` for v12+, `typo3conf/LocalConfiguration.php` for v11
  - [x] Add unit tests for v11 path variants

- [x] Task 3: Remove Docker classification from installation type (AC: 4)
  - [x] Remove `docker-compose.yml` / `Dockerfile` check from `determineInstallationType()`
  - [x] Update unit tests that verify Docker classification

- [x] Task 4: Narrow exception handling (AC: 5)
  - [x] Change `catch (\Throwable $e)` in `detect()` to `catch (\RuntimeException $e)`
  - [x] Verify that `VersionExtractor` and `PathResolutionService` throw `\RuntimeException` (not bare `\Exception`) on operational failures — adapt if needed
  - [x] Update unit test `testDetectHandlesExtractionException` to use `\RuntimeException`

- [x] Task 5: Quality gate (AC: 6, 7)
  - [x] `composer test` — all tests green
  - [x] `composer sca` — zero PHPStan Level 8 errors
  - [x] `composer lint:php` — zero violations

## Dev Notes

### Origin

This story addresses code review findings P3–P7 from the adversarial review of `ComposerInstallationDetector` (2026-03-24). The CI-critical findings P1, P2, P8 are handled in story 1.8.

### Key Files to Modify

| File                                                              | Change                                                    |
|-------------------------------------------------------------------|-----------------------------------------------------------|
| `src/Infrastructure/Discovery/ComposerInstallationDetector.php`   | Web-dir validation, version-aware paths, Docker removal, exception narrowing |
| `tests/Unit/Infrastructure/Discovery/ComposerInstallationDetectorTest.php` | New and updated unit tests                       |

### Dependencies

- Depends on story 1.8 (single-parse refactor must be in place before modifying method signatures further)

### References

- [Source: code-review-composer-installation-detector-2026-03-24.md — findings P3, P4, P5, P6, P7]
- [Source: src/Infrastructure/Discovery/ComposerInstallationDetector.php]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- Added `getWebDirectoryForSupportsCheck()` private method to validate web-dir from composer.json — rejects absolute paths, `..` traversal, and empty strings, falls back to `public`.
- Refactored `detectEnabledFeatures()` to accept `$webDir` and `Version`: uses `{webDir}/typo3conf/ext` for v12+, `typo3conf/ext` at root for v11. Also uses version-aware settings file path.
- Refactored `detectDatabaseConfig()` to accept `Version`: checks `config/system/settings.php` / `config/system/additional.php` for v12+, `typo3conf/LocalConfiguration.php` / `typo3conf/AdditionalConfiguration.php` for v11.
- Removed Docker file detection (`docker-compose.yml`, `Dockerfile`) from `determineInstallationType()`. Docker is orthogonal to installation type.
- Narrowed `catch (\Throwable $e)` to `catch (\RuntimeException $e)` in `detect()`. `VersionExtractor` does not throw (returns result object). `PathResolutionService` does not throw in its implementation. `\LogicException` and `\Error` now propagate correctly.
- Added 12 new unit tests covering all ACs. All 1580 tests pass; PHPStan Level 8 zero errors; CS-Fixer zero violations.

### File List

- `src/Infrastructure/Discovery/ComposerInstallationDetector.php`
- `tests/Unit/Infrastructure/Discovery/ComposerInstallationDetectorTest.php`
