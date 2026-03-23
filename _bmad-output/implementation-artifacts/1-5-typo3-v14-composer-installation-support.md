# Story 1.5: TYPO3 v14 Composer Installation Support

Status: done

## Story

As a developer,
I want the tool to correctly discover extensions in a TYPO3 v14 Composer installation,
so that I can analyze projects targeting the next major release and the tool formally supports v14.

## Acceptance Criteria

1. A new integration test fixture at `tests/Integration/Fixtures/TYPO3Installations/v14Composer/` represents a standard TYPO3 v14 Composer layout as physical files (no dynamic generation).
2. A second fixture `tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/` uses a non-standard `web-dir` read from `composer.json`, overriding the profile default.
3. `InstallationDiscoveryService` correctly detects both fixtures as TYPO3 v14 Composer mode.
4. `ExtensionDiscoveryService` correctly discovers third-party extensions from `vendor/composer/installed.json` and excludes core extensions (prefix `typo3/cms-`) in both fixtures.
5. The v14 profile in `VersionProfileRegistry` is marked `supportsComposerMode: true` and `supportsLegacyMode: true` — v14 officially supports both Composer and classic installation modes.
6. `VersionProfileRegistry::getProfile(14)` returns the v14 profile without error.
7. All existing tests (v11, v12, v13 fixtures and unit tests) continue to pass without modification.
8. PHPStan Level 8 reports zero errors.

## Tasks / Subtasks

- [x] Task 1: Create `v14Composer` fixture (AC: 1, 3, 4)
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v14Composer/composer.json` — require `typo3/cms-*: ^14.0`, `extra.typo3/cms.web-dir: "public"`
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v14Composer/composer.lock` — minimal valid lock file (mirror v13Composer, bump versions to v14.0.0)
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v14Composer/vendor/composer/installed.json` — include `typo3/cms-core` (v14.0.0, type `typo3-cms-framework`), `georgringer/news` (third-party, type `typo3-cms-extension`), `example/powermail` (third-party)
  - [x] Create stub PHP files: `vendor/georgringer/news/Classes/Controller/NewsController.php`, `composer.json`, `ext_emconf.php`; same for `example/powermail`
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v14Composer/public/index.php` (web dir marker)
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v14Composer/config/system/settings.php`
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v14Composer/var/log/typo3.log`
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v14Composer/test-expectations.json` — `installation_type: "composer_standard"`, `typo3_version: "14.0"`, third-party extensions as `should_exist: true`, core packages as `should_exist: false`

- [x] Task 2: Create `v14ComposerCustomWebDir` fixture (AC: 2, 3, 4)
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/composer.json` — require `typo3/cms-*: ^14.0`, `extra.typo3/cms.web-dir: "web"` (non-standard)
  - [x] Create `composer.lock`, `vendor/composer/installed.json` (same packages as v14Composer, v14.0.0 versions)
  - [x] Create stub PHP files using `web/` as web directory instead of `public/`
  - [x] Create `tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/test-expectations.json` — `installation_type: "composer_custom"`, `typo3_version: "14.0"`, `path_configuration: "web-dir-web"`

- [x] Task 3: Add integration tests for v14 fixtures (AC: 3, 4, 6, 7)
  - [x] Add test class `tests/Integration/Discovery/Typo3V14ComposerDiscoveryTest.php`
  - [x] Test: `v14Composer` fixture — `InstallationDiscoveryService` detects v14 Composer mode; `ExtensionDiscoveryService` finds `georgringer/news` and `example/powermail`, excludes `typo3/cms-*`
  - [x] Test: `v14ComposerCustomWebDir` fixture — custom `web-dir: "web"` read from `composer.json` overrides the profile default `"public"`
  - [x] Test: `v14ComposerCustomWebDir` — extension discovery finds third-party extensions
  - [x] Test: `VersionProfileRegistry::getProfile(14)` — `supportsComposerMode: true`, `supportsLegacyMode: true`
  - [x] Verify `PathResolutionServiceFixturesTest` auto-discovers v14 fixtures — no code change needed

- [x] Task 4: Quality gate (AC: 7, 8)
  - [x] `composer test` — all tests green (including new integration tests)
  - [x] `composer sca` — zero PHPStan Level 8 errors
  - [x] `composer lint` — zero style violations

## Dev Notes

### No `src/` Changes Required

The v14 profile in `VersionProfileRegistryFactory.php` (lines 52-61) is already correct:
- `supportsComposerMode: true` — correct
- `supportsLegacyMode: true` — correct per official TYPO3 v14 documentation

TYPO3 v14 officially supports both Composer and classic installation modes. The documentation states both methods are "fully supported" with "no official plan to deprecate the classic installation method."
Source: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Installation/Index.html

The unit test `VersionProfileRegistryFactoryTest.php` line 62 (`'v14 supports composer and legacy' => [14, true, true]`) is also already correct. Do not change it.

This story is tests-only (fixtures + integration test class), exactly like story 1.3 was for v13.

### No Existing v14 Fixtures

`tests/Integration/Fixtures/TYPO3Installations/` currently contains: `v11LegacyInstallation`, `v11ComposerCustomWebDir`, `v11ComposerAppVendor`, `v12Composer`, `v12ComposerCustomWebDir`, `v12ComposerCustomBothDirs`, `v13Composer`, `v13ComposerCustomWebDir`. No v14 fixtures exist.

### Fixture Structure — Mirror v13Composer

Use the v13Composer fixture as the template (just bump versions to v14.0.0):

```
v14Composer/
├── composer.json              (require typo3/cms-*: ^14.0, web-dir: "public")
├── composer.lock              (minimal valid lock file)
├── test-expectations.json
├── config/system/settings.php
├── var/log/typo3.log
├── public/typo3/index.php     (web-dir marker)
└── vendor/
    ├── composer/installed.json
    ├── typo3/cms-core/
    │   └── composer.json
    ├── georgringer/news/
    │   ├── composer.json
    │   ├── ext_emconf.php
    │   └── Classes/Controller/NewsController.php
    └── example/powermail/
        ├── composer.json
        ├── ext_emconf.php
        └── Classes/Domain/Model/Form.php
```

For `v14ComposerCustomWebDir`: mirror `v13ComposerCustomWebDir` — use `web/` instead of `public/`, set `web-dir: "web"` in composer.json.

### `composer.json` root for v14Composer

```json
{
  "name": "myvendor/v14-composer-project",
  "description": "TYPO3 v14 Composer installation fixture",
  "type": "project",
  "require": {
    "php": "^8.3",
    "typo3/cms-backend": "^14.0",
    "typo3/cms-core": "^14.0",
    "typo3/cms-extbase": "^14.0",
    "typo3/cms-frontend": "^14.0",
    "georgringer/news": "^12.0",
    "example/powermail": "^11.0"
  },
  "config": {
    "vendor-dir": "vendor",
    "bin-dir": "bin"
  },
  "extra": {
    "typo3/cms": {
      "web-dir": "public"
    }
  }
}
```

### `vendor/composer/installed.json` — critical details

```json
{
  "packages": [
    {
      "name": "typo3/cms-core",
      "version": "v14.0.0",
      "type": "typo3-cms-framework",
      "extra": { "typo3/cms": { "extension-key": "core" } }
    },
    {
      "name": "georgringer/news",
      "version": "v12.0.0",
      "type": "typo3-cms-extension",
      "extra": { "typo3/cms": { "extension-key": "news" } }
    },
    {
      "name": "example/powermail",
      "version": "v11.0.0",
      "type": "typo3-cms-extension",
      "extra": { "typo3/cms": { "extension-key": "powermail" } }
    }
  ],
  "dev": false
}
```

- Core package `typo3/cms-core` with type `typo3-cms-framework` is excluded by `str_starts_with($name, 'typo3/cms-')`.
- These Composer-mode fixtures do NOT include `PackageStates.php`. Discovery uses `installed.json` only.

### `test-expectations.json` for v14Composer

```json
{
  "typo3_version": "14.0",
  "installation_type": "composer_standard",
  "notes": "Standard v14 Composer fixture with web-dir public/. Core packages (typo3/cms-*) excluded from extension discovery.",
  "extensions": [
    { "composer_name": "georgringer/news", "extension_key": "news", "should_exist": true },
    { "composer_name": "example/powermail", "extension_key": "powermail", "should_exist": true },
    { "composer_name": "typo3/cms-core", "extension_key": "core", "should_exist": false }
  ],
  "auto_detect_expected": ["news", "powermail"]
}
```

For `v14ComposerCustomWebDir`: same but `"installation_type": "composer_custom"`, add `"path_configuration": "web-dir-web"`.

### Integration Test Pattern

Mirror `tests/Integration/Discovery/Typo3V13ComposerDiscoveryTest.php` exactly. Use `ContainerFactory::create()` to get services:

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
// v14Composer: web-dir resolves to "public" (profile default)
// v14ComposerCustomWebDir: web-dir resolves to "web" (from composer.json, overrides profile default)
// Core extensions (typo3/cms-*) must NOT appear in extension results
```

Test class: `tests/Integration/Discovery/Typo3V14ComposerDiscoveryTest.php`
Namespace: `CPSIT\UpgradeAnalyzer\Tests\Integration\Discovery`
Base class: `AbstractIntegrationTestCase` (consistent with v13 pattern; `ExtensionDiscoveryWorkflowIntegrationTestCase` exists but serves a different purpose)

Suggested test methods:
- `testV14ComposerInstallationIsDetected()`
- `testV14ComposerExtensionDiscoveryFindsThirdPartyExtensionsAndExcludesCore()`
- `testV14ComposerCustomWebDirInstallationIsDetected()`
- `testV14ComposerCustomWebDirReadsWebDirFromComposerJson()`
- `testV14ComposerCustomWebDirExtensionDiscoveryFindsThirdPartyExtensions()`

### Auto-Discovery Test Coverage

`tests/Integration/Infrastructure/Path/PathResolutionServiceFixturesTest.php` auto-discovers every fixture directory containing `test-expectations.json`. Creating v14 fixtures with a valid `test-expectations.json` adds them automatically — no code change needed. Test count will increase from 11 (after v13) to 13.

### Architecture Compliance

- All new code in `tests/` only — no `src/` changes in this story
- No `services.yaml` changes — v14 profile already registered
- New test class goes in `tests/Integration/Discovery/` (mirrors v13 pattern)
- Domain layer untouched

### Avoid These Mistakes

- Do NOT change `supportsLegacyMode` on the v14 profile — `true` is already correct
- Do NOT modify `VersionProfileRegistryFactory.php` — it is correct as-is
- Do NOT modify `VersionProfileRegistryFactoryTest.php` — the v14 data provider entries are correct as-is
- Do NOT create `PackageStates.php` in v14 Composer fixtures
- Do NOT modify `ExtensionDiscoveryService` logic — it already handles v14 Composer correctly

### Epic AC Correction Note

The epics.md story 1.5 AC states `supportsLegacyMode: false` for v14. This is factually wrong. The official TYPO3 v14 documentation confirms both installation methods are fully supported with no deprecation plan for classic mode. The correct value is `supportsLegacyMode: true`, which matches the current factory implementation. Do not use epics.md as the source of truth for this particular AC — use this story file.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.5] — note: AC regarding `supportsLegacyMode: false` is incorrect, see Epic AC Correction Note above
- [Source: src/Infrastructure/Discovery/VersionProfileRegistryFactory.php lines 52-61 — v14 profile, already correct]
- [Source: tests/Unit/Infrastructure/Discovery/VersionProfileRegistryFactoryTest.php line 62 — already correct, no change needed]
- [Source: _bmad-output/implementation-artifacts/1-3-typo3-v13-composer-installation-support.md — mirror pattern for fixtures and integration tests]
- [Source: tests/Integration/Discovery/Typo3V13ComposerDiscoveryTest.php — integration test pattern to mirror]
- [Source: tests/Integration/Fixtures/TYPO3Installations/v13Composer/ — fixture structure to mirror]
- [Source: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Installation/Index.html — v14 supports both Composer and classic mode]

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6

### Debug Log References

- `composer static-analysis` is not available; the correct script name is `composer sca`
- `composer cs:check` is not available; the correct script name is `composer lint`

### Completion Notes List

- Created v14Composer fixture tree (13 files) mirroring v13Composer, bumped all versions to v14.0.0 and php requirement to ^8.3
- Created v14ComposerCustomWebDir fixture tree (13 files) mirroring v13ComposerCustomWebDir, using `web/` as web-dir
- Added `Typo3V14ComposerDiscoveryTest.php` with 5 integration tests, mirroring the v13 pattern exactly
- All 1564 tests pass; PHPStan Level 8 reports zero errors; lint reports zero violations
- No `src/` changes required — v14 profile in `VersionProfileRegistryFactory.php` was already correct

### File List

tests/Integration/Fixtures/TYPO3Installations/v14Composer/composer.json
tests/Integration/Fixtures/TYPO3Installations/v14Composer/composer.lock
tests/Integration/Fixtures/TYPO3Installations/v14Composer/test-expectations.json
tests/Integration/Fixtures/TYPO3Installations/v14Composer/config/system/settings.php
tests/Integration/Fixtures/TYPO3Installations/v14Composer/var/log/typo3.log
tests/Integration/Fixtures/TYPO3Installations/v14Composer/public/index.php
tests/Integration/Fixtures/TYPO3Installations/v14Composer/vendor/composer/installed.json
tests/Integration/Fixtures/TYPO3Installations/v14Composer/vendor/georgringer/news/composer.json
tests/Integration/Fixtures/TYPO3Installations/v14Composer/vendor/georgringer/news/ext_emconf.php
tests/Integration/Fixtures/TYPO3Installations/v14Composer/vendor/georgringer/news/Classes/Controller/NewsController.php
tests/Integration/Fixtures/TYPO3Installations/v14Composer/vendor/example/powermail/composer.json
tests/Integration/Fixtures/TYPO3Installations/v14Composer/vendor/example/powermail/ext_emconf.php
tests/Integration/Fixtures/TYPO3Installations/v14Composer/vendor/example/powermail/Classes/Domain/Model/Form.php
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/composer.json
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/composer.lock
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/test-expectations.json
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/config/system/settings.php
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/var/log/typo3.log
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/web/index.php
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/vendor/composer/installed.json
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/vendor/georgringer/news/composer.json
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/vendor/georgringer/news/ext_emconf.php
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/vendor/georgringer/news/Classes/Controller/NewsController.php
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/vendor/example/powermail/composer.json
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/vendor/example/powermail/ext_emconf.php
tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/vendor/example/powermail/Classes/Domain/Model/Form.php
tests/Integration/Discovery/Typo3V14ComposerDiscoveryTest.php

## Change Log

- 2026-03-23: Added v14Composer and v14ComposerCustomWebDir fixture trees (26 files total) and Typo3V14ComposerDiscoveryTest.php (5 integration tests). All tests pass, PHPStan zero errors, lint clean.
