# Code Review Findings: ComposerInstallationDetector

**Date:** 2026-03-24
**Reviewer:** claude-opus-4-6 (Blind Hunter + Edge Case Hunter)
**Target:** `src/Infrastructure/Discovery/ComposerInstallationDetector.php`
**Review mode:** no-spec (focused on detection reliability)
**Trigger:** v14 integration tests failing in CI due to unreliable filesystem indicators

---

## Context

The `ComposerInstallationDetector::supports()` method uses filesystem directory checks (`var/log`, `config/system`, `public/typo3`, etc.) to confirm a Composer project is a TYPO3 installation. These directories are runtime artifacts — they do not exist after `composer install` and before first boot. This caused CI failures for v14 fixtures (and potentially v13) because the test fixtures only pass locally where leftover runtime directories exist.

The fundamental issue: the definitive evidence for a TYPO3 Composer installation is already in `composer.json`, `composer.lock`, and `vendor/composer/installed.json` — not in runtime directories.

---

## Patch Findings (actionable)

### P1. `supports()` uses unreliable runtime-dependent filesystem indicators [HIGH]

**Location:** Lines 131–147
**Problem:** Requires 2 of 5 directory indicators: `typo3conf`, `{webDir}/typo3conf`, `{webDir}/typo3`, `config/system`, `var/log`. None are guaranteed after `composer install`. A fresh v14 (or v13) project that has never booted scores 0/5 and fails detection.
**Impact:** Primary use case (analyzing a project before it runs) silently fails.
**Fix direction:** Replace filesystem indicators with Composer artifact checks: `composer.json` `type` field (`"project"`), `composer.lock` resolved packages, `vendor/composer/installed.json`.

### P2. `hasTypo3Packages()` only checks composer.json — misses transitive dependencies [MED]

**Location:** Lines 178–218
**Problem:** Projects using distribution packages (e.g., `helhum/typo3-distribution`) that transitively require `typo3/cms-core` without listing it directly in `composer.json` are false negatives.
**Fix direction:** Check `composer.lock` packages or `vendor/composer/installed.json` as fallback. These contain the resolved dependency tree.

### P3. Unsanitized web-dir path used in filesystem checks [MED]

**Location:** Lines 128–134, 408–437
**Problem:** `composer.json` `extra.typo3/cms.web-dir` value is used directly in `file_exists()` path construction. A value like `../../etc` probes outside the project root.
**Fix direction:** Validate web-dir is a relative path within the project root. Reject absolute paths and `..` traversals.

### P4. `detectEnabledFeatures()` hardcodes `public/typo3conf/ext` [MED]

**Location:** Line 320
**Problem:** Ignores custom web-dir. Installations with `web-dir: "web"` always miss the "extensions" feature flag.
**Fix direction:** Use the resolved web-dir from `detectCustomPaths()` or pass it as parameter.

### P5. `detectDatabaseConfig()` and `detectEnabledFeatures()` hardcode v12+ paths [MED]

**Location:** Lines 290–306, 320–333
**Problem:** `config/system/settings.php` is v12+. TYPO3 v11 uses `typo3conf/LocalConfiguration.php`. These methods silently return empty results for older installations.
**Fix direction:** Use version-aware path resolution (the `VersionProfileRegistry` already has this information).

### P6. Docker presence overrides Composer classification [MED]

**Location:** Lines 483–485
**Problem:** `determineInstallationType()` returns `DOCKER_CONTAINER` if `docker-compose.yml` or `Dockerfile` exists. Every TYPO3 project using DDEV gets misclassified.
**Fix direction:** Docker is a hosting concern, not an installation type. Remove this classification or make it orthogonal to the Composer/Legacy distinction.

### P7. `catch (\Throwable)` swallows fatal errors [MED]

**Location:** Lines 97–106
**Problem:** Catches `Error`, `TypeError`, `OutOfMemoryError`. Bugs in `VersionExtractor` or `PathResolutionService` are logged at error level and return `null`, masking programming errors.
**Fix direction:** Catch `\RuntimeException` or a custom detection exception. Let `Error` subclasses propagate.

### P8. `composer.json` parsed 4+ times per detection [LOW]

**Location:** Multiple methods (hasTypo3Packages, getWebDirectoryForSupportsCheck, detectPhpVersions, determineInstallationType)
**Problem:** Each method independently reads and decodes the same file. Introduces TOCTOU window and unnecessary I/O.
**Fix direction:** Parse `composer.json` once in `supports()` or `detect()`, pass the decoded array to downstream methods.

---

## Deferred Findings (pre-existing, not blocking)

| ID  | Title | Severity |
|-----|-------|----------|
| D1  | `getDefaultProfile()` fallback picks `$supportedVersions[0]` — order-dependent | Low |
| D2  | `detectDatabaseConfig()` name suggests DB config but only checks file existence | Low |
| D3  | `str_replace` path relativization in `detectCustomPaths()` is fragile | Low |
| D4  | Root-level `typo3conf` indicator is legacy false-positive magnet for v12+ | Low |
| D5  | Empty-string `web-dir` produces duplicate/malformed indicator paths | Low |

---

## Recommended Story Scope

A rework story should address P1 and P2 as the core change (replace filesystem indicators with Composer artifact-based detection), and bundle P3, P4, P5, P6, P8 as cleanup within the same refactoring since they all touch the same methods. P7 can be included or deferred.

### Acceptance criteria sketch

1. `supports()` determines TYPO3 presence from `composer.json` content (type, require) and optionally `composer.lock` — no filesystem indicator threshold.
2. Transitive TYPO3 dependencies detected via `composer.lock` or `vendor/composer/installed.json`.
3. v14 and v13 fixtures pass detection without synthetic runtime directories (`var/log/.gitkeep`, `config/system/settings.php` should not be required for detection to succeed).
4. `composer.json` is parsed once per detection pass.
5. Web-dir values are validated against path traversal.
6. Docker indicators do not override installation type classification.
7. All existing v11/v12/v13/v14 integration tests pass.

### Related artifacts

- `src/Infrastructure/Discovery/ComposerInstallationDetector.php` (primary target)
- `tests/Integration/Discovery/Typo3V14ComposerDiscoveryTest.php` (CI failure trigger)
- `tests/Integration/Fixtures/TYPO3Installations/v14Composer/` (fixture)
- `tests/Integration/Fixtures/TYPO3Installations/v14ComposerCustomWebDir/` (fixture)
- Epic 1 stories 1.3, 1.5 (prior Composer detection work)