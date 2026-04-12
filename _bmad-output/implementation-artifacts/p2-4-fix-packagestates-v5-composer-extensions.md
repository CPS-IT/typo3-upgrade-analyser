# Story P2-4: Fix Extension State Detection for Composer Projects (Issue #163)

Status: done

## GitHub Issue

[#163 — Bug: Extension discovery fails for TYPO3 10 with PackageStates v5](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/163)

## Story

As a developer analyzing a Composer-based TYPO3 installation,
I want active extensions to be correctly marked as active in the analysis report,
so that the upgrade risk assessment reflects the actual extension activation state.

## Root Cause

`ExtensionDiscoveryService::discoverFromPackageStates()` is called for both Composer and legacy installations. It passes `PackageStates.php` data directly to `createExtensionFromPackageData()` (line 353), where line 475 reads `$packageData['state']`. In a Composer installation, `PackageStates.php` only contains the package path — there is no `state` key. This causes an `Undefined array key "state"` warning, and `'active' === null` evaluates to `false`, marking all extensions inactive.

The fix direction from the issue report is correct: `PackageStates.php` must only be read for non-Composer installations. For Composer installations, extension activation state must come from a different source.

## Acceptance Criteria

1. For Composer installations, `ExtensionDiscoveryService` does not read `PackageStates.php` to determine extension state.
2. For Composer installations, extension activation state is determined from `vendor/composer/installed.json` or the `composer.lock` packages list (extensions present in the resolved dependency tree are considered active).
3. For legacy installations, `PackageStates.php` is still used as the authoritative source for activation state.
4. The "Undefined array key state" warning no longer occurs for Composer projects.
5. A Composer-mode integration test fixture that omits `PackageStates.php` (or has one without `state` keys) passes with all extensions correctly marked active.
6. Existing legacy installation tests are unaffected.
7. PHPStan Level 8 zero errors, `composer lint:php` zero violations, `composer test` green.

## Tasks / Subtasks

- [x] Task 1: Understand the current discovery flow
  - [x] Read `ExtensionDiscoveryService::discoverExtensions()` and `discoverFromPackageStates()` fully
  - [x] Identify where the Composer vs legacy branch decision is (or is not) made before calling `discoverFromPackageStates()`
  - [x] Identify what data source is used for activation state in Composer mode today

- [x] Task 2: Implement installation-type guard
  - [x] Add a condition so `discoverFromPackageStates()` is only invoked for legacy installations
  - [x] For Composer installations, determine active state from `installed.json` packages (all resolved packages are considered active; or derive from `require` in `composer.json` if a direct/transitive distinction is needed — out of scope for this story)

- [x] Task 3: Fix `createExtensionFromPackageData()` null safety
  - [x] Add an `isset($packageData['state'])` guard or a typed accessor to prevent the undefined key warning regardless of call context
  - [x] Define a sensible default (e.g., `true` / active) when state is not present in Composer data

- [x] Task 4: Add regression test
  - [x] Use an existing Composer fixture (e.g., v13 or v14) and assert extension active states
  - [x] If no suitable fixture exists, add a minimal one under `tests/Fixtures/`

- [x] Task 5: Quality gate
  - [x] `composer test` — all tests green
  - [x] `composer static-analysis` — zero errors
  - [x] `composer lint:php` — zero violations

## Notes

TYPO3 version affected: 10.4 (and likely 11.5, 12, 13 in Composer mode). Fix must not break the legacy path.

## Dev Agent Record

### Implementation Plan

Root cause confirmed: `discoverExtensions()` called `discoverFromPackageStates()` unconditionally. In Composer mode, PackageStates.php v5 entries contain only `packagePath` — no `state` key. Accessing `$packageData['state']` produced an `E_WARNING` and `null !== 'active'` evaluated to `false`, marking all extensions inactive. The merge guard then prevented `installed.json` (correct source) from overriding those stale inactive entries.

Two targeted changes in `ExtensionDiscoveryService`:
1. `discoverExtensions()`: call `determineInstallationType()` before discovery, skip `discoverFromPackageStates()` when type is `COMPOSER_STANDARD` or `COMPOSER_CUSTOM`.
2. `createExtensionFromPackageData()` line 475: guarded `$packageData['state']` access with `isset()` — defensive against any future call path where state is absent.

### Completion Notes

- 2 new unit tests: `testComposerInstallationSkipsPackageStatesAndUsesInstalledJson` (regression), `testPackageStatesEntryWithoutStateKeyDefaultsToInactive` (null safety).
- Added v5-format `PackageStates.php` (no `state` keys) to `v13Composer` integration fixture.
- Added active-state assertions to `Typo3V13ComposerDiscoveryTest::testV13ComposerExtensionDiscoveryFindsThirdPartyExtensionsAndExcludesCore`.
- All ACs satisfied. PHPStan level 8, lint, unit (1586), and integration (80) all green.

## File List

- `src/Infrastructure/Discovery/ExtensionDiscoveryService.php`
- `tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php`
- `tests/Integration/Discovery/Typo3V13ComposerDiscoveryTest.php`
- `tests/Integration/Fixtures/TYPO3Installations/v13Composer/public/typo3conf/PackageStates.php` (new)
- `_bmad-output/implementation-artifacts/p2-4-fix-packagestates-v5-composer-extensions.md`
- `_bmad-output/implementation-artifacts/sprint-status.yaml`

## Change Log

- 2026-03-25: Fixed issue #163 — Composer installations no longer read PackageStates.php for extension active state; added isset guard for missing state key; added regression tests and v13Composer fixture.
- 2026-03-25: Code review patches applied — eliminated duplicate `determineInstallationType()` call (P1); added PackageStates skip entry to discoveryMetadata for Composer installations (P2). Deferred findings D1/D2 tracked in sprint-status.yaml kickoff checklist under F-D-04.
