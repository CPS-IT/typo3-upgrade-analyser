# Story 1.1: Version Profile Registry

Status: done

## Story

As a developer,
I want the tool to use a centralized registry of per-version installation profiles (v11–v14),
So that version-specific paths, core package identification, and discovery modes are defined in one auditable place and used consistently across all discovery services.

## Acceptance Criteria

1. `VersionProfile` (DTO), `VersionProfileRegistry`, and `VersionProfileRegistryFactory` are created in `src/Infrastructure/Discovery/`
2. Registry contains explicit profiles for TYPO3 v11, v12, v13, and v14, each defining:
   - default vendor dir (`defaultVendorDir`)
   - default web dir for Composer installations (`defaultWebDir`)
   - default web dir for classic/legacy installations (`legacyDefaultWebDir`)
   - core package prefix for Composer package identification (`corePackagePrefix`)
   - legacy core extension directory relative to web dir (`legacyCoreExtensionDir`)
   - supported discovery modes (`supportsComposerMode`, `supportsLegacyMode`)
3. `VersionProfileRegistry::getProfile(int $majorVersion)` returns the correct profile for a given major version integer
4. `VersionProfileRegistryFactory` is registered in `config/services.yaml` using factory syntax — never in `Shared/ContainerFactory`
5. ~~`composer.json` keys override profile defaults~~ — Moved to Story 1.2 (runtime override is a consumer concern, not a data-class concern)
6. `VersionProfile` is a `final readonly class` in `DTO\` namespace with zero framework imports
7. `VersionProfileRegistry` and `VersionProfile` have 100% unit test coverage
8. PHPStan Level 8 reports zero errors on all new classes

## Tasks / Subtasks

- [x] Create `VersionProfile` final readonly DTO class (AC: 1, 2, 6)
  - [x] Properties: `majorVersion: int`, `defaultVendorDir: string`, `defaultWebDir: string`, `legacyDefaultWebDir: string`, `corePackagePrefix: string`, `legacyCoreExtensionDir: string`, `supportsComposerMode: bool`, `supportsLegacyMode: bool`
  - [x] Namespace: `CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DTO`
  - [x] Zero imports from Symfony or any framework
- [x] Create `VersionProfileRegistry` class (AC: 3)
  - [x] Constructor accepts `array<int, VersionProfile> $profiles`
  - [x] `getProfile(int $majorVersion): VersionProfile` — throws `\InvalidArgumentException` for unknown versions
  - [x] `getSupportedVersions(): array<int>` — returns list of registered major version integers
- [x] Create `VersionProfileRegistryFactory` class (AC: 4)
  - [x] Static `create(): VersionProfileRegistry` method
  - [x] Registers v11, v12, v13, v14 profiles inline
  - [x] All versions (v11–v14): `supportsComposerMode: true`, `supportsLegacyMode: true`
  - [x] All profiles use `legacyDefaultWebDir: '.'` for classic installation web root
  - [x] All profiles use `corePackagePrefix: 'typo3/cms-'` for core extension identification
  - [x] All profiles use `legacyCoreExtensionDir: 'typo3/sysext'` for legacy installations
- [x] Register in `config/services.yaml` using factory syntax (AC: 4)
- [x] Write unit tests (AC: 7)
  - [x] `VersionProfileTest`: test all property values, prefix matching, discovery modes
  - [x] `VersionProfileRegistryTest`: test `getProfile()` for valid versions, exception for unknown version
  - [x] `VersionProfileRegistryFactoryTest`: test factory creates registry with all 4 versions, correct modes, correct prefix/paths
- [x] Run PHPStan Level 8 and fix all errors (AC: 8)
- [x] Run full test suite — all tests green

## Dev Notes

### Namespace and File Locations

- `VersionProfile`: `src/Infrastructure/Discovery/DTO/VersionProfile.php`
- `VersionProfileRegistry`: `src/Infrastructure/Discovery/VersionProfileRegistry.php`
- `VersionProfileRegistryFactory`: `src/Infrastructure/Discovery/VersionProfileRegistryFactory.php`
- Tests: `tests/Unit/Infrastructure/Discovery/VersionProfileTest.php`, `VersionProfileRegistryTest.php`, `VersionProfileRegistryFactoryTest.php`

### Design Decision: corePackagePrefix vs coreExtensionKeys

The original spec called for `coreExtensionKeys: array<string>` — a manually curated list of sysext short keys per version. Code review identified this as fundamentally flawed:

1. **Naming mismatch:** `ExtensionDiscoveryService` uses `str_starts_with($key, 'typo3/cms-')` (Composer vendor prefix). Short keys like `'backend'` cannot match this without a translation layer.
2. **Maintenance burden:** Manual lists are incomplete by definition (e.g., `extensionmanager` removed in v13, `reactions` added in v12) and require updates for every TYPO3 release.
3. **Proven alternative:** The `typo3/cms-` prefix pattern is already in production use at `ExtensionDiscoveryService:349,402` and automatically covers all core packages.

Replaced with `corePackagePrefix: string` (value: `'typo3/cms-'`). Consumers use `str_starts_with()` — the existing proven pattern.

### Design Decision: legacyCoreExtensionDir vs cmsPackageDir

The original `cmsPackageDir: 'vendor/typo3/cms-core'` was a single package path, not a core extension directory. Replaced with `legacyCoreExtensionDir: 'typo3/sysext'` — the actual directory containing core extensions in legacy installations. For Composer installations, core packages are under `<vendorDir>/typo3/cms-<name>/` which is derived from `defaultVendorDir` + the Composer package name.

### Integration Points (Story 1.2)

Overlap analysis with existing services identified these hardcoded values to replace:
- `ExtensionDiscoveryService:349,402` — `str_starts_with($key, 'typo3/cms-')` → `$profile->corePackagePrefix`
- `ExtensionDiscoveryService:288-289` — fallback `'vendor'`, `'public'` → `$profile->defaultVendorDir`, `$profile->defaultWebDir`
- `ExtensionDiscoveryService:543` — `str_contains($path, '/typo3/sysext/')` → `$profile->legacyCoreExtensionDir`
- `ComposerInstallationDetector:352-356` — hardcoded defaults → profile defaults
- `ComposerInstallationDetector:267` — `is_dir($path . '/typo3/sysext')` → profile value

### Architecture Rules

- `VersionProfile` is `final readonly class` in `DTO\` sub-namespace — consistent with `Infrastructure\Path\DTO\` convention
- No framework imports in `VersionProfile` or `VersionProfileRegistry`
- `VersionProfileRegistryFactory` is a plain PHP class with a static `create()` method

### References

- Factory syntax pattern: `config/services.yaml:101` (RectorExecutor)
- Auto-discovery scope: `config/services.yaml:240-243`
- Current hardcoded core exclusion: `src/Infrastructure/Discovery/ExtensionDiscoveryService.php:349,402`
- Architecture decision: `_bmad-output/planning-artifacts/architecture.md` — TYPO3 Version Matrix Strategy
- Epic source: `_bmad-output/planning-artifacts/epics.md` — Story 1.1

## Dev Agent Record

### Agent Model Used

claude-opus-4-6

### Debug Log References

None.

### Completion Notes List

- Created `VersionProfile` as `final readonly class` in `Infrastructure\Discovery\DTO\` namespace, zero framework imports (AC 1, 2, 6)
- Created `VersionProfileRegistry` with `getProfile()` (throws `\InvalidArgumentException` for unknown versions) and `getSupportedVersions()` (AC 3)
- Created `VersionProfileRegistryFactory::create()` with inline profiles for v11-v14 (AC 4)
  - All versions: composer + legacy modes (classic mode documented for v13/v14 per TYPO3 docs)
  - All profiles: `corePackagePrefix: 'typo3/cms-'`, `legacyCoreExtensionDir: 'typo3/sysext'`
- Registered `VersionProfileRegistry` in `config/services.yaml` using static factory syntax (AC 4)
- 35 unit tests across 3 test classes: VersionProfileTest (8), VersionProfileRegistryTest (5), VersionProfileRegistryFactoryTest (22) (AC 7)
- PHPStan Level 8: zero errors (AC 8)
- PHP-CS-Fixer: clean
- Full regression suite: 1556 tests, 0 failures
- Code review identified and resolved: coreExtensionKeys replaced with corePackagePrefix, cmsPackageDir replaced with legacyCoreExtensionDir, VersionProfile moved to DTO namespace, added final keyword
- Added `legacyDefaultWebDir` property to cover classic/legacy installation web root defaults
- Corrected `supportsLegacyMode` to `true` for v13/v14 — classic mode is documented in TYPO3 v13 and v14 installation guides
- Note: A `ClassicVersionStrategy` will be needed in future stories to handle classic installation discovery

### Change Log

- 2026-03-22: Story 1.1 spec updated after code review — AC 2 reflects actual properties, AC 5 moved to Story 1.2, AC 6 updated for DTO namespace
- 2026-03-22: VersionProfile moved to `Infrastructure\Discovery\DTO\` namespace per project convention
- 2026-03-22: Redesigned after code review — replaced `coreExtensionKeys` with `corePackagePrefix`, replaced `cmsPackageDir` with `legacyCoreExtensionDir`
- 2026-03-21: Initial implementation complete

### File List

- src/Infrastructure/Discovery/DTO/VersionProfile.php (new)
- src/Infrastructure/Discovery/VersionProfileRegistry.php (new)
- src/Infrastructure/Discovery/VersionProfileRegistryFactory.php (new)
- config/services.yaml (modified — added factory registration)
- tests/Unit/Infrastructure/Discovery/VersionProfileTest.php (new)
- tests/Unit/Infrastructure/Discovery/VersionProfileRegistryTest.php (new)
- tests/Unit/Infrastructure/Discovery/VersionProfileRegistryFactoryTest.php (new)
