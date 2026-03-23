# Story 1.2: TYPO3 v11 Core Extension Accurate Exclusion

Status: done

## Story

As a developer,
I want the tool to correctly identify and exclude core extensions in TYPO3 v11 installations,
So that risk scores for v11 projects are accurate and the tool can be trusted as the basis for upgrade estimates.

## Acceptance Criteria

1. All extensions matching the version profile's `corePackagePrefix` (Composer mode) OR located under `legacyCoreExtensionDir` (legacy mode) are classified as `core` type and excluded from availability checks and code analysis.
2. `ExtensionDiscoveryService` receives `VersionProfileRegistry` as a constructor dependency and uses the version-aware profile instead of hardcoded `'typo3/cms-'` prefix for core extension filtering.
3. `ComposerInstallationDetector` receives `VersionProfileRegistry` and uses profile defaults (`defaultVendorDir`, `defaultWebDir`) instead of hardcoded `'vendor'`, `'public'` fallback values.
4. The v11 legacy test fixture (`v11LegacyInstallation`) correctly excludes core extensions that use short key names (e.g., `core`, `backend`, `extbase`) in PackageStates.php.
5. The v11 Composer test fixtures (`v11ComposerCustomWebDir`, `v11ComposerAppVendor`) continue to pass without regression.
6. All existing tests across v11 and v12 fixtures pass without modification (regression-free).
7. PHPStan Level 8 reports zero errors on all modified and new files.

## Tasks / Subtasks

- [x] Task 1: Inject `VersionProfileRegistry` into `ExtensionDiscoveryService` (AC: 2)
  - [x] Add `VersionProfileRegistry` as constructor parameter
  - [x] Update `config/services.yaml` if needed (auto-wiring should handle it)
  - [x] Replace hardcoded `str_starts_with($packageKey, 'typo3/cms-')` at line ~349 (PackageStates discovery) with profile-based check using `$profile->corePackagePrefix`
  - [x] Replace hardcoded `str_starts_with($packageData['name'], 'typo3/cms-')` at line ~402 (installed.json discovery) with profile-based check
  - [x] Replace hardcoded `'/typo3/sysext/'` check at line ~543 with `$profile->legacyCoreExtensionDir`
- [x] Task 2: Fix legacy v11 PackageStates core extension detection (AC: 1, 4)
  - [x] In PackageStates parsing path (~line 349): add secondary check — if key does NOT match `corePackagePrefix`, check whether `packagePath` starts with `legacyCoreExtensionDir` (e.g., `typo3/sysext/`)
  - [x] This handles the v11 legacy format where keys are short names (`core`, `backend`) but paths are `typo3/sysext/core/`
- [x] Task 3: Inject `VersionProfileRegistry` into `ComposerInstallationDetector` (AC: 3)
  - [x] Add `VersionProfileRegistry` as constructor parameter
  - [x] Replace hardcoded `'vendor'` default at line ~353 with `$profile->defaultVendorDir`
  - [x] Replace hardcoded `'public'` default at line ~354 with `$profile->defaultWebDir`
  - [x] Replace hardcoded `is_dir($path . '/typo3/sysext')` at line ~267 with profile value — N/A: this check does not exist in ComposerInstallationDetector, only in ExtensionDiscoveryService where it was already addressed in Task 1
- [x] Task 4: Update unit tests for `ExtensionDiscoveryService` (AC: 4, 5, 6)
  - [x] Update existing test mocks to provide `VersionProfileRegistry`
  - [x] Add test case: v11 legacy PackageStates with short keys correctly excludes core extensions
  - [x] Add test case: v11 Composer installation correctly excludes core extensions via prefix
  - [x] Verify existing test `testTypo3CoreExtensionsAreFilteredOut` still passes
- [x] Task 5: Update unit tests for `ComposerInstallationDetector` (AC: 6)
  - [x] Update existing test mocks to provide `VersionProfileRegistry`
  - [x] Verify all existing detector tests pass
- [x] Task 6: Run full quality pipeline (AC: 6, 7)
  - [x] `composer test` — all 1563 tests green (3 new)
  - [x] `composer sca` — zero PHPStan errors at Level 8
  - [x] `composer fix:php --dry-run` — zero style violations

## Dev Notes

### The Bug

TYPO3 v11 legacy installations use PackageStates.php where core extensions have short keys like `'core'`, `'backend'`, `'frontend'` — without the `typo3/cms-` Composer vendor prefix. The current filtering logic only checks `str_starts_with($packageKey, 'typo3/cms-')`, so legacy v11 core extensions are NOT excluded. They appear as regular extensions in analysis results, receiving incorrect risk scores.

Real-world impact: confirmed in `verkehrswendeplattform` project (v11 installation).

### Fix Strategy

Two-pronged core extension detection:
1. **Composer mode (all versions):** Check `str_starts_with($name, $profile->corePackagePrefix)` — existing logic, just parameterized via profile
2. **Legacy mode (v11/v12):** Check whether `packagePath` in PackageStates starts with `$profile->legacyCoreExtensionDir` (e.g., `typo3/sysext/`) — this catches short-key entries

Both checks use values from `VersionProfileRegistry` (created in Story 1.1). No hardcoded strings remain.

### Key Files to Modify

| File | Change |
|---|---|
| `src/Infrastructure/Discovery/ExtensionDiscoveryService.php` | Add `VersionProfileRegistry` dependency, replace 3 hardcoded checks (~lines 349, 402, 543) |
| `src/Infrastructure/Discovery/ComposerInstallationDetector.php` | Add `VersionProfileRegistry` dependency, replace hardcoded defaults (~lines 267, 353-356) |
| `tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php` | Update mocks, add v11 legacy core exclusion test cases |
| `tests/Unit/Infrastructure/Discovery/ComposerInstallationDetectorTest.php` | Update mocks for new dependency |
| `config/services.yaml` | Likely no changes needed (auto-wiring), verify |

### Existing Code References

- **Hardcoded prefix (PackageStates):** `ExtensionDiscoveryService.php:349` — `str_starts_with($packageKey, 'typo3/cms-')`
- **Hardcoded prefix (installed.json):** `ExtensionDiscoveryService.php:402` — `str_starts_with($packageData['name'], 'typo3/cms-')`
- **Hardcoded sysext path:** `ExtensionDiscoveryService.php:543` — `str_contains($path, '/typo3/sysext/')`
- **Hardcoded detector defaults:** `ComposerInstallationDetector.php:352-356` — `'vendor'`, `'public'`
- **Hardcoded sysext dir check:** `ComposerInstallationDetector.php:267` — `is_dir($path . '/typo3/sysext')`

### VersionProfile Properties Used (from Story 1.1)

- `$profile->corePackagePrefix` — `'typo3/cms-'` (same for all versions currently)
- `$profile->legacyCoreExtensionDir` — `'typo3/sysext'`
- `$profile->defaultVendorDir` — `'vendor'`
- `$profile->defaultWebDir` — `'public'`
- `$profile->legacyDefaultWebDir` — `'.'`

### Test Fixtures Available

- `tests/Integration/Fixtures/TYPO3Installations/v11LegacyInstallation/` — v11 legacy with PackageStates using short keys (the failing case)
- `tests/Integration/Fixtures/TYPO3Installations/v11ComposerCustomWebDir/` — v11 Composer with custom web-dir
- `tests/Integration/Fixtures/TYPO3Installations/v11ComposerAppVendor/` — v11 Composer with custom vendor-dir
- `tests/Integration/Fixtures/TYPO3Installations/v12Composer/` — v12 standard Composer
- `tests/Integration/Fixtures/TYPO3Installations/v12ComposerCustomWebDir/` — v12 custom web-dir
- `tests/Integration/Fixtures/TYPO3Installations/v12ComposerCustomBothDirs/` — v12 custom both dirs

### Architecture Compliance

- `VersionProfileRegistry` is already in `Infrastructure/Discovery/` — same layer as consumers. No layer boundary issues.
- `VersionProfile` is a `final readonly class` DTO — no framework imports, safe to pass anywhere in Infrastructure layer.
- Mock `VersionProfileRegistry` interface in unit tests — or since it's a concrete class, create real instances via `VersionProfileRegistryFactory::create()` in tests (it has no external dependencies).
- Do NOT change `src/Domain/` — core extension filtering is an Infrastructure concern.

### Previous Story Intelligence (Story 1.1)

- `VersionProfile` lives in `src/Infrastructure/Discovery/DTO/VersionProfile.php`
- `VersionProfileRegistry` in `src/Infrastructure/Discovery/VersionProfileRegistry.php`
- `VersionProfileRegistryFactory` in `src/Infrastructure/Discovery/VersionProfileRegistryFactory.php`
- Factory registered in `config/services.yaml` using static factory syntax
- Design decision: `corePackagePrefix: string` (not `coreExtensionKeys: array<string>`) — uses `str_starts_with()` matching
- Design decision: `legacyCoreExtensionDir: 'typo3/sysext'` for legacy installation core extension path
- All 35 unit tests passing, PHPStan clean
- The overlap analysis in Story 1.1 dev notes explicitly lists the lines to replace in this story

### Testing Approach

- Use `VersionProfileRegistryFactory::create()` to get real registry instances in unit tests — no mocking needed since it has no external dependencies
- For `ExtensionDiscoveryService` tests: mock all other dependencies as before, add real `VersionProfileRegistry`
- Key assertion: v11 legacy PackageStates entry with key `'core'` and path `'typo3/sysext/core/'` must be classified as core and excluded
- Key assertion: v11 Composer entry `'typo3/cms-core'` must still be classified as core (regression check)
- Key assertion: a third-party extension `'my-vendor/my-ext'` with path `'typo3conf/ext/my_ext/'` must NOT be classified as core

### Open Question: Version Number Threading

`VersionProfileRegistry::getProfile(int $majorVersion)` requires the TYPO3 major version integer. Before replacing hardcoded checks, verify where this integer is available at the point core extension filtering happens in `ExtensionDiscoveryService`. It likely comes from the `Installation` entity or `AnalysisContext` passed into the discovery methods. If the version is not yet available at the filtering call site, you need to thread it through — check method signatures and call chains before starting the replacement.

### Project Structure Notes

- Alignment with existing DI patterns: auto-wiring handles `VersionProfileRegistry` injection automatically since it's already a registered service
- `ExtensionDiscoveryService` is registered at `config/services.yaml:250-257` — constructor change should be auto-wired
- `ComposerInstallationDetector` registration should also auto-wire

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.2]
- [Source: _bmad-output/planning-artifacts/architecture.md#TYPO3 Version Matrix Strategy]
- [Source: _bmad-output/planning-artifacts/architecture.md#VersionProfileRegistry Pattern]
- [Source: _bmad-output/implementation-artifacts/1-1-version-profile-registry.md#Integration Points (Story 1.2)]
- [Source: _bmad-output/planning-artifacts/prd.md — MVP priority 1: v11 core extension bug fix]

## Dev Agent Record

### Agent Model Used

Claude Opus 4.6

### Debug Log References

None.

### Completion Notes List

- Injected `VersionProfileRegistry` into `ExtensionDiscoveryService` and `ComposerInstallationDetector` as constructor dependencies
- Replaced 3 hardcoded string checks in `ExtensionDiscoveryService` with profile-based values from `VersionProfileRegistry`
- Added two-pronged core extension detection: Composer prefix check AND legacy path check via `isCorePackage()` helper method
- Replaced hardcoded `'vendor'` and `'public'` defaults in `ComposerInstallationDetector::detectCustomPaths()` with profile values
- Updated `config/services.yaml` to wire `VersionProfileRegistry` into both services and exclude it from auto-discovery (prevents autowiring conflict with factory-created instance)
- Updated both test classes to provide real `VersionProfileRegistry` instances via `VersionProfileRegistryFactory::create()`
- Added 3 new test cases: v11 legacy exclusion, v11 Composer exclusion, third-party non-exclusion
- Updated 2 existing tests (`testExtensionTypeDetection`, `testCreateExtensionFromPackageDataWithDifferentPathTypes`) that previously tested sysext extensions as non-core — these are now correctly excluded
- Note: Task 3 subtask "Replace hardcoded `is_dir($path . '/typo3/sysext')` at line ~267" was N/A — that check does not exist in `ComposerInstallationDetector`
- Version threading: used `getSupportedVersions()[0]` to get a default profile since `corePackagePrefix` and `legacyCoreExtensionDir` are identical across all versions. When version-specific discovery is implemented, this can be refined.

### Change Log

- 2026-03-22: Implemented Story 1.2 — v11 core extension accurate exclusion using VersionProfileRegistry

### File List

- `src/Infrastructure/Discovery/ExtensionDiscoveryService.php` (modified)
- `src/Infrastructure/Discovery/ComposerInstallationDetector.php` (modified)
- `config/services.yaml` (modified)
- `tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php` (modified)
- `tests/Unit/Infrastructure/Discovery/ComposerInstallationDetectorTest.php` (modified)
