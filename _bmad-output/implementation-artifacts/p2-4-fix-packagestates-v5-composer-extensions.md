# Story P2-4: Fix Extension State Detection for Composer Projects (Issue #163)

Status: ready-for-dev

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

- [ ] Task 1: Understand the current discovery flow
  - [ ] Read `ExtensionDiscoveryService::discoverExtensions()` and `discoverFromPackageStates()` fully
  - [ ] Identify where the Composer vs legacy branch decision is (or is not) made before calling `discoverFromPackageStates()`
  - [ ] Identify what data source is used for activation state in Composer mode today

- [ ] Task 2: Implement installation-type guard
  - [ ] Add a condition so `discoverFromPackageStates()` is only invoked for legacy installations
  - [ ] For Composer installations, determine active state from `installed.json` packages (all resolved packages are considered active; or derive from `require` in `composer.json` if a direct/transitive distinction is needed — out of scope for this story)

- [ ] Task 3: Fix `createExtensionFromPackageData()` null safety
  - [ ] Add an `isset($packageData['state'])` guard or a typed accessor to prevent the undefined key warning regardless of call context
  - [ ] Define a sensible default (e.g., `true` / active) when state is not present in Composer data

- [ ] Task 4: Add regression test
  - [ ] Use an existing Composer fixture (e.g., v13 or v14) and assert extension active states
  - [ ] If no suitable fixture exists, add a minimal one under `tests/Fixtures/`

- [ ] Task 5: Quality gate
  - [ ] `composer test` — all tests green
  - [ ] `composer static-analysis` — zero errors
  - [ ] `composer lint:php` — zero violations

## Notes

TYPO3 version affected: 10.4 (and likely 11.5, 12, 13 in Composer mode). Fix must not break the legacy path.
