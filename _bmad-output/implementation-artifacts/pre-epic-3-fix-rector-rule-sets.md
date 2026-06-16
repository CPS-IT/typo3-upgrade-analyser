# Story pre-epic-3: Fix Rector Rule Set Selection (Bug #290)

Status: review

## Story

As a developer running the upgrade analyzer on a TYPO3 12.4 installation,
I want `getSetsForVersionUpgrade()` to return all accumulated deprecation rule sets up to the target version,
so that a v12→v13 upgrade analysis surfaces findings from all prior versions (v10, v11, v12, v13), not only the sets introduced in the target version.

## Acceptance Criteria

1. `RectorRuleRegistry::getSetsForVersionUpgrade(v12.4, v13.0)` returns `Typo3LevelSetList::UP_TO_TYPO3_13` (which internally chains TYPO3_10 + TYPO3_11 + TYPO3_12 + TYPO3_13) instead of only `Typo3SetList::TYPO3_13`.
2. `getSetsForVersionUpgrade(v11.5, v12.0)` returns `Typo3LevelSetList::UP_TO_TYPO3_12`.
3. `getSetsForVersionUpgrade(v11.5, v13.0)` returns `Typo3LevelSetList::UP_TO_TYPO3_13` (single level set — no duplicated version sets).
4. `getSetsForVersionUpgrade(v13.0, v14.0)` returns `Typo3LevelSetList::UP_TO_TYPO3_14`.
5. Same-version and downgrade behavior is unchanged.
6. Unsupported source version (e.g. v9.5) behavior is unchanged.
7. All existing tests pass; updated tests assert `UP_TO_TYPO3_N` constants, not individual `TYPO3_N` sets.
8. `composer test` green, `composer sca:php` zero errors, `composer lint:php` zero violations.

## Tasks / Subtasks

- [x] Task 1: Add `TYPO3_LEVEL_SETS` map to `RectorRuleRegistry` (AC: 1–4)
  - [x] Add `use Ssch\TYPO3Rector\Set\Typo3LevelSetList;` import
  - [x] Add private const `TYPO3_LEVEL_SETS` mapping major version int → `Typo3LevelSetList::UP_TO_TYPO3_N` for keys 10, 11, 12, 13, 14
  - [x] Do NOT remove `TYPO3_VERSION_SETS` — it is used by `getSetsForMajorVersion()`, `getVersionSpecificSets()`, `getSupportedVersions()`, `isVersionSupported()`

- [x] Task 2: Rewrite range-filter in `getSetsForVersionUpgrade()` (AC: 1–6)
  - [x] Replace the `foreach (self::TYPO3_VERSION_SETS ...)` loop with a lookup: `$levelSet = self::TYPO3_LEVEL_SETS[$toVersion->getMajor()] ?? null`
  - [x] If `$levelSet` is null (target major unsupported), return empty array
  - [x] Prepend the single level set to `$sets`
  - [x] Keep the existing `GENERAL` set append (always added for supported upgrades)
  - [x] Keep the `CODE_QUALITY` set append for major version upgrades (different major)
  - [x] Keep same-version early return (uses GENERAL + CODE_QUALITY, unaffected)
  - [x] Keep downgrade early return (unaffected)
  - [x] Keep `isVersionSupported($fromVersion)` guard — still valid to reject unsupported source versions

- [x] Task 3: Update `RectorRuleRegistryTest` (AC: 7)
  - [x] `testGetSetsForVersionUpgradeFrom11To12`: replace `assertContains(Typo3SetList::TYPO3_12, ...)` with `assertContains(Typo3LevelSetList::UP_TO_TYPO3_12, ...)`; remove assertion for individual `TYPO3_12`
  - [x] `testGetSetsForVersionUpgradeFrom12To13`: replace `assertContains(Typo3SetList::TYPO3_13, ...)` with `assertContains(Typo3LevelSetList::UP_TO_TYPO3_13, ...)`
  - [x] `testGetSetsForVersionUpgradeMultipleVersions` (v11→v13): remove assertions for individual `TYPO3_12` and `TYPO3_13`; add `assertContains(Typo3LevelSetList::UP_TO_TYPO3_13, ...)`; `CODE_QUALITY` assertion remains valid
  - [x] Add `testGetSetsForVersionUpgradeFrom13To14`: asserts `UP_TO_TYPO3_14` is in result
  - [x] Add `testGetSetsForVersionUpgradeReturnsLevelSetNotIndividualSets`: for v12→v13, assert `UP_TO_TYPO3_13` present AND `TYPO3_13` is NOT directly in the set (the level set subsumes it)
  - [x] Add `use Ssch\TYPO3Rector\Set\Typo3LevelSetList;` import to test class

- [x] Task 4: Quality gate (AC: 8)
  - [x] `composer test` — all tests green (1674 tests, 6221 assertions)
  - [x] `composer sca:php` — zero PHPStan errors
  - [x] `composer lint:php` — zero violations

## Dev Notes

### Root Cause

`getSetsForVersionUpgrade()` iterates `TYPO3_VERSION_SETS` and selects entries where `setVersion > fromVersion && setVersion <= toVersion`. For a v12.4→v13.0 upgrade, only `'13.0'` passes this filter, selecting `[Typo3SetList::TYPO3_13]`. Rules from `TYPO3_10`, `TYPO3_11`, `TYPO3_12` are not selected even though they must all be cleared before v13 compatibility is achieved.

Real-world consequence: Rector returns 0 findings on a TYPO3 12.4 codebase that actually contains dozens of v10/v11/v12 deprecations. The analyzer silently reports a clean bill of health.

### Fix Pattern

`Typo3LevelSetList::UP_TO_TYPO3_N` is a cumulative level set. Internally:
- `UP_TO_TYPO3_13` = `UP_TO_TYPO3_12` + `TYPO3_13`
- `UP_TO_TYPO3_12` = `UP_TO_TYPO3_11` + `TYPO3_12`
- etc. (each file is a thin PHP closure calling `$rectorConfig->sets([...])`)

Returning `[UP_TO_TYPO3_13]` for a v12→v13 upgrade produces the same accumulated behavior as running Rector with `UP_TO_TYPO3_13` manually.

### Exact Change in `getSetsForVersionUpgrade()`

Replace the foreach loop (lines ~151–163) and the surrounding logic with:

```php
// After downgrade and unsupported-source guards remain unchanged.
// Replace the foreach block with:
$targetMajor = $toVersion->getMajor();
$levelSet = self::TYPO3_LEVEL_SETS[$targetMajor] ?? null;

if (null === $levelSet) {
    $this->logger->warning('No level set for target version {version}', [
        'to_version' => $toVersion->toString(),
    ]);
    return $sets;
}

$sets[] = $levelSet;

$this->logger->info('Selected level set for TYPO3 target version {version}', [
    'target_major' => $targetMajor,
    'level_set' => $levelSet,
]);

// Keep existing GENERAL and CODE_QUALITY appends below (unchanged).
```

### `TYPO3_LEVEL_SETS` constant to add

```php
private const array TYPO3_LEVEL_SETS = [
    10 => Typo3LevelSetList::UP_TO_TYPO3_10,
    11 => Typo3LevelSetList::UP_TO_TYPO3_11,
    12 => Typo3LevelSetList::UP_TO_TYPO3_12,
    13 => Typo3LevelSetList::UP_TO_TYPO3_13,
    14 => Typo3LevelSetList::UP_TO_TYPO3_14,
];
```

Keys are `int` (from `$toVersion->getMajor()`). Note `TYPO3_VERSION_SETS` keys are `string` — do not confuse.

### Do NOT Remove `TYPO3_VERSION_SETS`

The following methods depend on `TYPO3_VERSION_SETS` and must not be touched:
- `getSetsForMajorVersion(int $majorVersion)` — returns individual sets for a specific major
- `getVersionSpecificSets(Version $version)` — exact version/major lookup
- `getSupportedVersions()` — keys of the array
- `isVersionSupported(Version $version)` — `array_key_exists` check
- `getAllAvailableSets()` — collects all individual sets for the registry

### PHPStan Level 8 Notes

- `$toVersion->getMajor()` returns `int`. The `TYPO3_LEVEL_SETS` array keys must be `int`, not strings.
- `self::TYPO3_LEVEL_SETS[$targetMajor] ?? null` — PHPStan will type this as `string|null`; safe.
- No mixed types introduced.

### Test Conventions

- PHPUnit 12 with `#[DataProvider]` attribute (not `@dataProvider`)
- `declare(strict_types=1)` in every PHP file
- GPL-2.0-or-later license header
- Namespace: `CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Rector`
- Test file: `tests/Unit/Infrastructure/Analyzer/Rector/RectorRuleRegistryTest.php`
- Constructor: `new RectorRuleRegistry(new NullLogger())` — no other dependencies

### Project Structure Notes

- Source: `src/Infrastructure/Analyzer/Rector/RectorRuleRegistry.php`
- Test: `tests/Unit/Infrastructure/Analyzer/Rector/RectorRuleRegistryTest.php`
- No new files needed; no other classes touched

### References

- Sprint change proposal: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-06-16.md` § "New Story: pre-epic-3-fix-rector-rule-sets"
- `Typo3LevelSetList`: `vendor/ssch/typo3-rector/src/Set/Typo3LevelSetList.php`
- Level set config: `vendor/ssch/typo3-rector/config/level/up-to-typo3-13.php` (chains UP_TO_12 + TYPO3_13)
- `RectorRuleRegistry`: `src/Infrastructure/Analyzer/Rector/RectorRuleRegistry.php`
- Existing tests: `tests/Unit/Infrastructure/Analyzer/Rector/RectorRuleRegistryTest.php`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

None.

### Completion Notes List

- Task 1: Added `TYPO3_LEVEL_SETS` private const (major int → `Typo3LevelSetList::UP_TO_TYPO3_N`) and `use Ssch\TYPO3Rector\Set\Typo3LevelSetList;` import. `TYPO3_VERSION_SETS` left untouched.
- Task 2: Replaced `foreach (TYPO3_VERSION_SETS)` range-filter with a single `$levelSet = self::TYPO3_LEVEL_SETS[$toVersion->getMajor()] ?? null` lookup. Null target major returns empty (unsupported target). GENERAL and CODE_QUALITY appends, same-version/downgrade/unsupported-source guards all unchanged.
- Task 3: Updated 3 existing tests to assert `UP_TO_TYPO3_N` constants; added `testGetSetsForVersionUpgradeFrom13To14` and `testGetSetsForVersionUpgradeReturnsLevelSetNotIndividualSets`.
- Task 4: 1674 tests green, PHPStan level 8 clean, CS-Fixer clean.

### File List

- src/Infrastructure/Analyzer/Rector/RectorRuleRegistry.php
- tests/Unit/Infrastructure/Analyzer/Rector/RectorRuleRegistryTest.php

## Change Log

- 2026-06-16: Fixed Bug #290 — replaced range-filter set selection with `Typo3LevelSetList::UP_TO_TYPO3_N` lookup in `getSetsForVersionUpgrade()`. For a v12→v13 upgrade the analyzer now accumulates all deprecation rule sets (v10+v11+v12+v13) instead of only TYPO3_13. All 1674 tests pass.
