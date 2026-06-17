---
stepsCompleted: [1, 2, 3]
inputDocuments:
  - src/Infrastructure/Analyzer/FractorAnalyzer.php
  - src/Infrastructure/Analyzer/Fractor/FractorConfigGenerator.php
  - src/Infrastructure/Analyzer/Fractor/FractorRuleRegistry.php
  - src/Infrastructure/Analyzer/Rector/RectorRuleRegistry.php
  - src/Infrastructure/Analyzer/Rector/RectorConfigGenerator.php
  - vendor/a9f/typo3-fractor/src/Set/Typo3LevelSetList.php
  - vendor/a9f/typo3-fractor/config/level/up-to-typo3-13.php
  - tests/Unit/Infrastructure/Analyzer/Fractor/FractorRuleRegistryTest.php
  - tests/Unit/Infrastructure/Analyzer/Fractor/FractorConfigGeneratorTest.php
workflowType: research
lastStep: 3
research_type: technical
research_topic: Fractor defect investigation vs. three rector bugfix patterns
research_goals: Determine whether FractorAnalyzer, FractorConfigGenerator, and FractorRuleRegistry reproduce the three defect patterns fixed in the Rector analyzer suite
user_name: Dirk
date: 2026-06-17
web_research_enabled: false
source_verification: true
---

# Technical Research Report: Fractor Defect Investigation

**Date:** 2026-06-17
**Author:** Dirk
**Research Type:** Code investigation — analogous defects to three confirmed Rector bugs

---

## Research Overview

Three bugs were recently found and fixed in the Rector analyzer suite:

1. `fc791b1` — `RectorRuleRegistry::getSetsForVersionUpgrade()` used a range-filter that selected only
   version-specific sets, missing accumulated deprecations from prior versions.
2. `0a5a16f` — `RectorConfigGenerator::getSkipPatterns()` had a hardcoded `*/Configuration/TCA/Overrides/*`
   entry that suppressed valid findings in TCA override files.
3. `4bd496d` (branch only, not yet on main) — `Typo3RectorAnalyzer::getFallbackExtensionPath()` hardcoded
   `public/typo3conf` as the default typo3conf directory, breaking analysis on non-public web root
   installations.

This report investigates whether analogous defects exist in the Fractor counterparts:
`FractorRuleRegistry`, `FractorConfigGenerator`, and `FractorAnalyzer`.

---

## Finding 1: Rule Set Selection — CONFIRMED BUG

**File:** `src/Infrastructure/Analyzer/Fractor/FractorRuleRegistry.php:107–119`
**Severity:** High — produces systematically incomplete analysis results

### What the Rector fix did

`RectorRuleRegistry` was changed to use cumulative `Typo3LevelSetList::UP_TO_TYPO3_N` constants instead of
a range-filter over individual version sets. The level sets chain recursively — `UP_TO_TYPO3_13` imports
`UP_TO_TYPO3_12`, which imports `UP_TO_TYPO3_11`, etc. — ensuring all accumulated deprecations are
included regardless of where the upgrade starts.

### What FractorRuleRegistry does

`FractorRuleRegistry::getSetsForVersionUpgrade()` uses the old, pre-fix range-filter pattern:

```php
foreach (self::TYPO3_VERSION_SETS as $versionString => $versionSets) {
    $setVersion = new Version($versionString);
    if ($setVersion->isGreaterThan($fromVersion) && $setVersion->isLessThanOrEqualTo($toVersion)) {
        array_push($sets, ...$versionSets);
    }
}
```

For a v12→v13 upgrade this selects only `Typo3SetList::TYPO3_13`. The correct behavior is to use
`Typo3LevelSetList::UP_TO_TYPO3_13`, which chains v10+v11+v12+v13 sets so every prior-version deprecation
that must be resolved for TYPO3 13 compatibility is checked.

### Availability of the fix

`a9f\Typo3Fractor\Set\Typo3LevelSetList` exists in vendor with `UP_TO_TYPO3_10` through `UP_TO_TYPO3_14`
constants. Confirmed cumulative: `vendor/a9f/typo3-fractor/config/level/up-to-typo3-13.php` imports
`UP_TO_TYPO3_12` + `TYPO3_13`. The fix pattern is directly available.

### Impact

Analysis results for any upgrade span are incomplete. An extension with v11 deprecations will show clean
for a v12→v13 upgrade, generating false confidence in upgrade readiness.

### Structural difference from Rector (not a bug, but worth noting)

`RectorRuleRegistry` also adds `GENERAL_SETS` (general + code_quality sets) after the level set.
`FractorRuleRegistry` has no equivalent general sets. This is not a defect — Fractor has no
general-purpose TypoScript quality sets — but it means the post-fix behavior will be simpler (level set
only, no appended general sets).

### Test coverage gap

`FractorRuleRegistryTest::testGetSetsForVersionUpgradeFrom12To13()` asserts that `Typo3SetList::TYPO3_13`
is present in results. This test passes with the current broken implementation. After the fix it will
need to assert `Typo3LevelSetList::UP_TO_TYPO3_13` instead.

---

## Finding 2: Hardcoded Skip Patterns — NOT REPRODUCED in equivalent form

**File:** `src/Infrastructure/Analyzer/Fractor/FractorConfigGenerator.php:183–206`
**Severity:** Low — marginal risk of over-skip; the Rector-specific defect does not apply

### What the Rector fix removed

`RectorConfigGenerator::getSkipPatterns()` had `'*/Configuration/TCA/Overrides/*'` hardcoded. TCA Override
files are PHP files containing database schema definitions with potentially deprecated API calls. Skipping
them prevented Rector from detecting those deprecations.

### What FractorConfigGenerator has

`FractorConfigGenerator::getSkipPatterns()` does not contain `'*/Configuration/TCA/Overrides/*'`. The
defect was never introduced here.

The Fractor skip list differs from the post-fix Rector list in two ways:

| Pattern | Rector (current) | Fractor (current) |
|---|---|---|
| `*/.idea/*` | absent | present |
| `*/Resources/Public/*` | absent | present |
| Both share | `*/vendor/*`, `*/node_modules/*`, `*/var/*`, `*/public/*`, `*/.Build/*`, `*/Documentation/*`, `*/doc/*` | same |

**`*/.idea/*`**: Benign. IDE metadata files would not be analyzed regardless.

**`*/Resources/Public/*`**: Defensible but worth auditing. Fractor processes TypoScript and XML files.
TypoScript configuration is conventionally placed in `Resources/Private/`, not `Resources/Public/`. The
skip pattern is therefore unlikely to suppress valid findings in typical extensions. However, non-standard
layouts could have TypoScript in a `Resources/Public/` subdirectory, and those would be silently excluded.
No equivalent risk was identified for XML.

### Test note

`FractorConfigGeneratorTest::generateConfigContainsExpectedSkipPatterns()` at line 144 asserts that
`*/Resources/Public/*` IS present. If the pattern were removed as a cleanup, this test would need updating.

---

## Finding 3: Path Detection for Non-public Web Roots — CONFIRMED BUG

**File:** `src/Infrastructure/Analyzer/FractorAnalyzer.php:326–336`
**Severity:** High — produces wrong extension paths or errors on non-standard TYPO3 installations

### What the Rector fix did (branch `bugfix/pre-epic-3-fix-rector-path-resolution`, not yet on main)

Two changes in `Typo3RectorAnalyzer`:

1. Fallback path respects `web-dir` custom path:
   ```php
   // Before (buggy):
   $typo3confDir = $customPaths['typo3conf-dir'] ?? 'public/typo3conf';
   
   // After (fixed):
   $webDir = (string) ($customPaths['web-dir'] ?? 'public');
   $typo3confDir = $customPaths['typo3conf-dir'] ?? ($webDir . '/typo3conf');
   ```

2. Guard against non-existent resolved path:
   ```php
   if (!empty($installationPath) && !is_dir($extensionPath)) {
       $this->logger->warning('Extension path does not exist — skipping Rector analysis', [...]);
       return $result;
   }
   ```

### What FractorAnalyzer has

`FractorAnalyzer::getFallbackExtensionPath()` at line 327:

```php
private function getFallbackExtensionPath(Extension $extension, string $installationPath, array $customPaths): string
{
    $typo3confDir = $customPaths['typo3conf-dir'] ?? 'public/typo3conf';
    ...
}
```

This is identical to the pre-fix Rector code. The `web-dir` custom path is inspected in
`determineInstallationType()` (line 292) for installation type classification, but that information is
never passed through to the fallback path calculation. The two methods are inconsistent: type detection is
web-dir aware; fallback path resolution is not.

Additionally, `doAnalyze()` has no `!is_dir($extensionPath)` guard before invoking Fractor. The
PathResolutionService failure path logs a warning and falls back — but if the fallback itself resolves to
a wrong (non-existent) path, the executor is called with that path. Fractor's behavior with a
non-existent directory is not explicitly handled: it may throw (visible error) or return empty output
(silent 0 findings), depending on how FractorExecutor handles it.

### Impact

Installations using `web/`, `htdocs/`, or any custom web root other than `public/` will get an incorrect
fallback path. The analysis either fails with an error or silently produces 0 findings, depending on
Fractor's executor behavior.

### Test coverage gap

`FractorAnalyzerTest` has no test case covering a custom web-dir fallback path. The Rector fix added
explicit test coverage for this scenario in `Typo3RectorAnalyzerTest`.

---

## Summary Matrix

| Defect Pattern | FractorRuleRegistry | FractorConfigGenerator | FractorAnalyzer |
|---|---|---|---|
| Range-filter vs. cumulative sets | **CONFIRMED** — identical pre-fix code | Not applicable | Not applicable |
| Hardcoded skip suppressing valid findings | Not applicable | Not reproduced (TCA/Overrides pattern never added) | Not applicable |
| public/-only fallback path | Not applicable | Not applicable | **CONFIRMED** — identical pre-fix code |
| Missing path-existence guard | Not applicable | Not applicable | **CONFIRMED** — guard not present |

---

## Recommended Actions (ordered by impact)

### P1 — Fix `FractorRuleRegistry::getSetsForVersionUpgrade()`

Replace the range-filter with a `Typo3LevelSetList` lookup. Mirror the Rector fix exactly:

1. Add `use a9f\Typo3Fractor\Set\Typo3LevelSetList;`
2. Add a `TYPO3_LEVEL_SETS` constant mapping major version int → level set constant
3. Replace the `foreach` loop with a single level set lookup on `toVersion->getMajor()`
4. Update `FractorRuleRegistryTest` — assertions on individual `Typo3SetList::TYPO3_N` are now stale

### P2 — Fix `FractorAnalyzer::getFallbackExtensionPath()`

Apply the same two-part fix as the pending Rector branch:

1. Replace `$typo3confDir = $customPaths['typo3conf-dir'] ?? 'public/typo3conf'` with
   `$webDir = (string) ($customPaths['web-dir'] ?? 'public'); $typo3confDir = $customPaths['typo3conf-dir'] ?? ($webDir . '/typo3conf')`
2. Add `!is_dir($extensionPath)` early-return guard in `doAnalyze()` after path resolution
3. Add a test case for non-public web-dir fallback resolution

### P3 — Review `FractorConfigGenerator::getSkipPatterns()` (low priority)

`*/Resources/Public/*` is defensible but should be verified against any TYPO3 extension that places
TypoScript outside `Resources/Private/`. If no such pattern is known, leave as-is. Remove `*/.idea/*`
as it is redundant clutter (IDE metadata is not TypoScript/XML).

---

## Dead Code Note (not a bug)

`FractorConfigGenerator::generateConfigFileContent()` emits `use a9f\\Typo3Fractor\\Set\\Typo3LevelSetList;`
in generated config files. Since sets are written via `var_export()` as string file paths — not as PHP
class constants — this import is never resolved. After fixing FractorRuleRegistry to return level set
paths, the generated files will continue to embed those paths as strings, so the import remains unused.
The generated `use` statement is dead code in every generated file and should be removed from the
template in `generateConfigFileContent()`.
