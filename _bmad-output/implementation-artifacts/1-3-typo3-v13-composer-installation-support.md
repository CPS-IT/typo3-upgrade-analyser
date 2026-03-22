# Story 1.3: TYPO3 v13 Composer Installation Support

Status: done

## Story

As a developer,
I want the tool to correctly discover extensions in a TYPO3 v13 Composer installation,
so that I can analyze projects targeting the current LTS release and the tool formally supports v13.

## Acceptance Criteria

1. A new integration test fixture at `tests/Integration/Fixtures/TYPO3Installations/v13Composer/` represents a standard TYPO3 v13 Composer layout as physical files (no dynamic generation).
2. A second fixture `tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/` uses a non-standard `web-dir` read from `composer.json`, overriding the profile default.
3. `InstallationDiscoveryService` correctly detects both fixtures as TYPO3 v13 Composer mode.
4. `ExtensionDiscoveryService` correctly discovers third-party extensions from `vendor/composer/installed.json` and excludes core extensions (prefix `typo3/cms-`) in both fixtures.
5. The v13 profile in `VersionProfileRegistry` retains `supportsLegacyMode: true` — v13 continues to support both Composer and classic installation modes per official TYPO3 documentation.
6. All existing tests (v11, v12 fixtures) continue to pass without modification.
7. PHPStan Level 8 reports zero errors.

## Tasks / Subtasks

- [x] Task 1: Create `v13Composer` fixture (AC: 1, 3, 4)
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v13Composer/composer.json` — require `typo3/cms-*: ^13.4`, `extra.typo3/cms.web-dir: "public"`
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v13Composer/composer.lock` — minimal valid lock file
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v13Composer/vendor/composer/installed.json` — include `typo3/cms-core` (v13.4.0, type `typo3-cms-framework`), `georgringer/news` (third-party, type `typo3-cms-extension`), `example/powermail` (third-party)
  - [x] Create stub PHP files mirroring v12Composer structure (e.g., `vendor/georgringer/news/Classes/Controller/NewsController.php`, `composer.json`, `ext_emconf.php`)
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v13Composer/public/typo3/index.php` (web dir marker)
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v13Composer/config/system/settings.php`
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v13Composer/var/log/typo3.log`
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v13Composer/test-expectations.json` — `installation_type: "composer_standard"`, list third-party extensions as `should_exist: true`, core packages as `should_exist: false`

- [x] Task 2: Create `v13ComposerCustomWebDir` fixture (AC: 2, 3, 4)
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/composer.json` — require `typo3/cms-*: ^13.4`, `extra.typo3/cms.web-dir: "web"` (non-standard)
  - [x] Create `composer.lock`, `vendor/composer/installed.json` (same packages as v13Composer, v13 versions)
  - [x] Create stub PHP files using `web/` as web directory instead of `public/`
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/test-expectations.json` — `installation_type: "composer_custom"`, `path_configuration: "web-dir-web"`

- [x] Task 3: Add integration tests for v13 fixtures (AC: 3, 4, 6)
  - [x] Add test class `tests/Integration/Discovery/Typo3V13ComposerDiscoveryTest.php`
  - [x] Test: `v13Composer` fixture — `InstallationDiscoveryService` detects v13 Composer mode; `ExtensionDiscoveryService` finds third-party extensions, excludes core
  - [x] Test: `v13ComposerCustomWebDir` fixture — custom `web-dir: "web"` read from `composer.json` overrides the profile default `"public"`
  - [x] Verify `PathResolutionServiceFixturesTest` auto-discovers v13 fixtures — no code change needed, it scans for `test-expectations.json` automatically
  - [x] Run full test suite — all green

- [x] Task 4: Quality gate (AC: 6, 7)
  - [x] `composer test` — all tests green (including new integration tests)
  - [x] `composer sca:php` — zero PHPStan Level 8 errors
  - [x] `composer lint:php` — zero style violations

## Dev Notes

### Critical Pre-Existing State (read before coding)

**v13 profile already exists and `supportsLegacyMode: true` is CORRECT — do not change it:**
- `src/Infrastructure/Discovery/VersionProfileRegistryFactory.php` lines 42-51: v13 profile with `supportsLegacyMode: true` is accurate. TYPO3 v13 officially supports both Composer and classic installation modes. The TYPO3 v13 docs state both methods are "fully supported" with "no official plan to deprecate the classic installation method."
- `tests/Unit/Infrastructure/Discovery/VersionProfileRegistryFactoryTest.php` line 62: `'v13 supports composer and legacy' => [13, true, true]` is correct — leave it unchanged.
- The architecture document (`_bmad-output/planning-artifacts/architecture.md`) contains an error in the "Version-specific notes" section: it states "v13+: Composer-only; legacy discovery path not implemented." **This is factually wrong.** That note has been corrected separately. Do not use the architecture doc as the source of truth for this.

**No v13 fixtures exist yet:**
- `tests/Integration/Fixtures/TYPO3Installations/` currently has: `v11LegacyInstallation`, `v11ComposerCustomWebDir`, `v11ComposerAppVendor`, `v12Composer`, `v12ComposerCustomWebDir`, `v12ComposerCustomBothDirs`
- v13 fixtures must be created as physical file trees

**Auto-discovery test coverage:**
- `tests/Integration/Infrastructure/Path/PathResolutionServiceFixturesTest.php` auto-discovers every fixture directory containing `test-expectations.json` via `scandir`. Creating v13 fixtures with a valid `test-expectations.json` adds them automatically — no code change needed.
- `test-expectations.json` must use `installation_type: "composer_standard"` (v13Composer) and `"composer_custom"` (v13ComposerCustomWebDir), mapping to `InstallationTypeEnum` values.

### Fixture Structure — Mirror v12Composer

Use `v12Composer` as the template (it already exists):
```
v12Composer/
├── composer.json
├── composer.lock
├── test-expectations.json
├── config/system/settings.php
├── var/log/typo3.log
├── public/typo3/index.php
├── public/typo3conf/ext/      (ignored in Composer mode)
└── vendor/
    ├── composer/installed.json
    ├── typo3/cms-core/
    ├── georgringer/news/
    └── example/powermail/
```

For v13: copy structure, set `typo3/cms-*` versions to `^13.4` in `composer.json` and `v13.4.0` in `installed.json`.
For v13ComposerCustomWebDir: mirror `v12ComposerCustomWebDir` — uses `web/` instead of `public/`.

### `composer.json` root for v13Composer (key fields)

```json
{
  "require": {
    "typo3/cms-core": "^13.4",
    "typo3/cms-backend": "^13.4",
    "typo3/cms-frontend": "^13.4",
    "georgringer/news": "^12.0",
    "example/powermail": "^11.0"
  },
  "config": { "vendor-dir": "vendor" },
  "extra": { "typo3/cms": { "web-dir": "public" } }
}
```

### `vendor/composer/installed.json` — critical details

- Core package: `"type": "typo3-cms-framework"`, name `typo3/cms-core`, version `v13.4.0`
- Third-party: `"type": "typo3-cms-extension"`, `"extra": {"typo3/cms": {"extension-key": "news"}}`
- `ExtensionDiscoveryService` filters core via `str_starts_with($name, 'typo3/cms-')` — unchanged for v13
- These Composer-mode fixtures do NOT include `PackageStates.php`. Discovery uses `installed.json` only.

### v13 Classic Mode Exists — But Is Out of Scope for This Story

v13 supports classic (legacy) mode with `typo3conf/ext/` extension layout, just like v11/v12. Classic mode fixtures for v13 are out of scope here and belong in a separate story. This story covers Composer mode only.

### Integration Test Pattern

Services via `ContainerFactory::create()` — see `tests/Integration/Discovery/ExtensionDiscoveryWorkflowIntegrationTestCase.php`:

```php
$container = ContainerFactory::create();
$installationDiscovery = $container->get(InstallationDiscoveryService::class);
$extensionDiscovery = $container->get(ExtensionDiscoveryService::class);

$result = $installationDiscovery->discoverInstallation($fixturePath);
$installation = $result->getInstallation();
$extensionResult = $extensionDiscovery->discoverExtensions(
    $fixturePath,
    $installation->getMetadata()?->getCustomPaths()
);
// v13Composer: web-dir resolves to "public" (profile default)
// v13ComposerCustomWebDir: web-dir resolves to "web" (from composer.json, overrides profile default)
// Core extensions (typo3/cms-*) must NOT appear in extension results
```

### Architecture Compliance

- All new code in `tests/` only — no `src/` changes in this story
- No `services.yaml` changes — v13 profile already registered
- Test mirror rule: new test class goes in `tests/Integration/Discovery/`

### Avoid These Mistakes

- Do NOT set `supportsLegacyMode: false` for v13 — the current value `true` is correct
- Do NOT create `PackageStates.php` in v13 Composer fixtures
- Do NOT touch the v14 profile — that is story 1.4
- Do NOT modify `ExtensionDiscoveryService` logic — it already handles v13 Composer correctly

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.3]
- [Source: _bmad-output/planning-artifacts/architecture.md#TYPO3 Version Matrix Strategy] — note: contains error on v13 legacy support, corrected separately
- [Source: src/Infrastructure/Discovery/VersionProfileRegistryFactory.php — v13 profile, lines 42-51]
- [Source: tests/Unit/Infrastructure/Discovery/VersionProfileRegistryFactoryTest.php — versionModeDataProvider, line 57-63]
- [Source: tests/Integration/Infrastructure/Path/PathResolutionServiceFixturesTest.php — auto-discovery pattern]
- [Source: https://docs.typo3.org/m/typo3/reference-coreapi/13.4/en-us/Administration/Installation/Index.html — v13 supports both Composer and classic mode]

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6

### Debug Log References

None.

### Completion Notes List

- Created v13Composer fixture with composer.json (web-dir: "public"), composer.lock, vendor/composer/installed.json (typo3/cms-core v13.4.0 as framework, georgringer/news and example/powermail as third-party extensions), stub PHP classes, web-dir marker, config, and var/log files.
- Created v13ComposerCustomWebDir fixture mirroring v12ComposerCustomWebDir with web-dir: "web" in composer.json.
- Both fixtures include test-expectations.json that PathResolutionServiceFixturesTest auto-discovers — test count increased from 9 to 11.
- Added Typo3V13ComposerDiscoveryTest.php with 5 tests covering: v13 standard installation detection, third-party extension discovery with core exclusion, custom-web-dir installation detection, web-dir override verification, and custom-web-dir extension discovery.
- No src/ changes needed — v13 profile and all discovery logic were already in place.
- All 1563 tests pass, PHPStan Level 8 zero errors, PHP CS Fixer zero violations.

### File List

- tests/Integration/Fixtures/TYPO3Installations/v13Composer/composer.json
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/composer.lock
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/test-expectations.json
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/config/system/settings.php
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/var/log/typo3.log
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/public/typo3/index.php
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/vendor/composer/installed.json
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/vendor/georgringer/news/composer.json
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/vendor/georgringer/news/ext_emconf.php
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/vendor/georgringer/news/Classes/Controller/NewsController.php
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/vendor/example/powermail/composer.json
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/vendor/example/powermail/ext_emconf.php
- tests/Integration/Fixtures/TYPO3Installations/v13Composer/vendor/example/powermail/Classes/Domain/Model/Form.php
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/composer.json
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/composer.lock
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/test-expectations.json
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/config/system/settings.php
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/var/log/typo3.log
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/web/typo3/index.php
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/vendor/composer/installed.json
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/vendor/georgringer/news/composer.json
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/vendor/georgringer/news/ext_emconf.php
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/vendor/georgringer/news/Classes/Domain/Model/News.php
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/vendor/example/powermail/composer.json
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/vendor/example/powermail/ext_emconf.php
- tests/Integration/Fixtures/TYPO3Installations/v13ComposerCustomWebDir/vendor/example/powermail/Classes/Domain/Model/Form.php
- tests/Integration/Discovery/Typo3V13ComposerDiscoveryTest.php
- _bmad-output/implementation-artifacts/sprint-status.yaml
- _bmad-output/implementation-artifacts/1-3-typo3-v13-composer-installation-support.md

### Change Log

- 2026-03-22: Implemented story 1.3 — created v13Composer and v13ComposerCustomWebDir fixtures plus integration test class Typo3V13ComposerDiscoveryTest.php. No src/ changes required.
