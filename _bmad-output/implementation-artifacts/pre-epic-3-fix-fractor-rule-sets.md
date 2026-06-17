# Story pre-epic-3: Fix FractorRuleRegistry Cumulative Set Selection

Status: ready-for-dev

## Story

As a developer running the upgrade analyzer on a TYPO3 12.4 installation,
I want `FractorRuleRegistry::getSetsForVersionUpgrade()` to return all accumulated TypoScript migration rule sets up to the target version,
so that a v12→v13 upgrade analysis surfaces findings from all prior versions (v10, v11, v12, v13), not only the sets introduced in the target version.

## Acceptance Criteria

1. `FractorRuleRegistry::getSetsForVersionUpgrade(v12.4, v13.0)` returns `Typo3LevelSetList::UP_TO_TYPO3_13` instead of only `Typo3SetList::TYPO3_13`.
2. `getSetsForVersionUpgrade(v11.5, v12.0)` returns `Typo3LevelSetList::UP_TO_TYPO3_12`.
3. `getSetsForVersionUpgrade(v11.5, v13.0)` returns `Typo3LevelSetList::UP_TO_TYPO3_13` (single level set — no duplicated version sets).
4. `getSetsForVersionUpgrade(v13.0, v14.0)` returns `Typo3LevelSetList::UP_TO_TYPO3_14`.
5. Same-version input (v12.4 → v12.4) still returns empty — the existing `testGetSetsForSameVersion` test must continue to pass.
6. Downgrade behavior is unchanged (returns empty with warning log).
7. Unsupported source version (e.g. v9.5) behavior is unchanged (returns empty).
8. `FractorConfigGenerator::generateConfigFileContent()` no longer emits the dead `use a9f\\Typo3Fractor\\Set\\Typo3LevelSetList;` import in generated config files (sets are embedded as file paths via `var_export`, so the import is never resolved).
9. All existing tests pass; stale assertions on individual `Typo3SetList::TYPO3_N` are updated.
10. `composer test` green, `composer sca:php` zero errors, `composer lint:php` zero violations.

## Tasks / Subtasks

- [ ] Task 1: Add `TYPO3_LEVEL_SETS` map and import to `FractorRuleRegistry` (AC: 1–4)
  - [ ] Add `use a9f\Typo3Fractor\Set\Typo3LevelSetList;` import
  - [ ] Add private const `TYPO3_LEVEL_SETS` mapping major version int → `Typo3LevelSetList::UP_TO_TYPO3_N` for keys 10, 11, 12, 13, 14
  - [ ] Do NOT remove `TYPO3_VERSION_SETS` — used by `getSetsForMajorVersion()`, `getVersionSpecificSets()`, `getSupportedVersions()`, `isVersionSupported()`, `getAllAvailableSets()`, `getSetSeverity()`, `getSetDescription()`, `getSetEffort()`

- [ ] Task 2: Rewrite `getSetsForVersionUpgrade()` (AC: 1–7)
  - [ ] Replace the `foreach (self::TYPO3_VERSION_SETS ...)` loop with a level set lookup
  - [ ] Add a same-version/non-upgrade guard before the lookup: `if (!$fromVersion->isLessThan($toVersion)) { return $sets; }` — replaces the current downgrade-only `isGreaterThan` check; preserves both downgrade and same-version empty-return behavior
  - [ ] Keep `isVersionSupported($fromVersion)` guard — still valid to reject unsupported source versions
  - [ ] Level set lookup: `$levelSet = self::TYPO3_LEVEL_SETS[$toVersion->getMajor()] ?? null`
  - [ ] If `$levelSet` is null (target major unsupported), log warning and return empty
  - [ ] Add `$sets[] = $levelSet` (no GENERAL or CODE_QUALITY appends — Fractor has no equivalent)
  - [ ] Remove `array_unique($sets)` call — with a single level set it is redundant; but keep if it causes no harm

- [ ] Task 3: Remove dead import from `FractorConfigGenerator::generateConfigFileContent()` (AC: 8)
  - [ ] In `src/Infrastructure/Analyzer/Fractor/FractorConfigGenerator.php`, remove the line: `$content .= "use a9f\\Typo3Fractor\\Set\\Typo3LevelSetList;\n";` from `generateConfigFileContent()` (line 144)
  - [ ] Check `FractorConfigGeneratorTest` for any assertion on this import line — if asserted, remove that assertion. (The test at line 126 `generateConfigContainsExpectedSkipPatterns` checks `*/Resources/Public/*` skip pattern, not the import — likely safe, but verify)

- [ ] Task 4: Update `FractorRuleRegistryTest` (AC: 9)
  - [ ] Add `use a9f\Typo3Fractor\Set\Typo3LevelSetList;` import to test class
  - [ ] `testGetSetsForVersionUpgradeFrom11To12`: replace `assertContains(Typo3SetList::TYPO3_12, ...)` with `assertContains(Typo3LevelSetList::UP_TO_TYPO3_12, ...)`
  - [ ] `testGetSetsForVersionUpgradeFrom12To13`: replace `assertContains(Typo3SetList::TYPO3_13, ...)` with `assertContains(Typo3LevelSetList::UP_TO_TYPO3_13, ...)`
  - [ ] `testGetSetsForVersionUpgradeMultipleVersions` (v11→v13): remove `assertContains(Typo3SetList::TYPO3_12, ...)` and `assertContains(Typo3SetList::TYPO3_13, ...)`; add `assertContains(Typo3LevelSetList::UP_TO_TYPO3_13, ...)`
  - [ ] `testSetsAreUniqueAcrossVersions`: after fix each result contains a single level set path — `count == 1 == count(array_unique(...))` — test still passes; no change required, but verify
  - [ ] Add `testGetSetsForVersionUpgradeFrom13To14`: asserts `UP_TO_TYPO3_14` is in result for v13→v14
  - [ ] Add `testGetSetsForVersionUpgradeReturnsLevelSetNotIndividualSets`: for v12→v13, assert `UP_TO_TYPO3_13` present AND `Typo3SetList::TYPO3_13` is NOT directly in the returned array

- [ ] Task 5: Quality gate (AC: 10)
  - [ ] `composer test` — all tests green
  - [ ] `composer sca:php` — zero PHPStan errors
  - [ ] `composer lint:php` — zero violations

## Dev Notes

### Root Cause

`getSetsForVersionUpgrade()` iterates `TYPO3_VERSION_SETS` and selects entries where `setVersion > fromVersion && setVersion <= toVersion`. For a v12.4→v13.0 upgrade, only `'13.0'` passes this filter, selecting `[Typo3SetList::TYPO3_13]`. Rules from TYPO3_10, TYPO3_11, TYPO3_12 are not selected even though they must all be cleared before v13 compatibility is achieved.

Real-world consequence: Fractor returns 0 findings on a TYPO3 12.4 codebase that contains v10/v11/v12 TypoScript migration requirements. The analyzer silently reports a clean bill of health.

This is identical to the pre-fix `RectorRuleRegistry` code, fixed in commit `fc791b1` (story `pre-epic-3-fix-rector-rule-sets`).

### Fix Pattern

`Typo3LevelSetList::UP_TO_TYPO3_N` is a cumulative level set. Internally:
- `UP_TO_TYPO3_13` = `UP_TO_TYPO3_12` + `TYPO3_13`
- `UP_TO_TYPO3_12` = `UP_TO_TYPO3_11` + `TYPO3_12`
- etc. (`vendor/a9f/typo3-fractor/config/level/up-to-typo3-13.php` — confirmed)

Returning `[UP_TO_TYPO3_13]` for a v12→v13 upgrade produces the same accumulated behavior as running Fractor manually with `UP_TO_TYPO3_13`.

### Fractor vs Rector differences (important)

1. **No GENERAL / CODE_QUALITY appends**: `RectorRuleRegistry` adds GENERAL and CODE_QUALITY sets after the level set. `FractorRuleRegistry` has no equivalent — the method only returns version-specific sets. Do not add any general sets.

2. **Same-version guard**: `RectorRuleRegistry` had an explicit same-version early return; `FractorRuleRegistry` does not. The current range filter naturally returns empty for same-version (no set is both `> v12.4` AND `<= v12.4`). After the fix, the level set lookup (`TYPO3_LEVEL_SETS[12]`) would return `UP_TO_TYPO3_12` for a v12.4→v12.4 call — NOT empty. This would break `testGetSetsForSameVersion`. The fix must add an explicit non-upgrade guard.

3. **Dead import in config generator**: `FractorConfigGenerator::generateConfigFileContent()` emits `use a9f\\Typo3Fractor\\Set\\Typo3LevelSetList;` in generated config PHP files. Since sets are written as file-path strings via `var_export()` (not class constants), this import is never used by the generated file. It is dead code. Remove it.

### Exact change in `getSetsForVersionUpgrade()`

Replace the current code from the `// Return empty for downgrades` comment through the end of the `foreach` block with:

```php
// Return empty for non-upgrades (downgrade or same version)
if (!$fromVersion->isLessThan($toVersion)) {
    $this->logger->warning('Non-upgrade scenario detected — no sets applied', [
        'from_version' => $fromVersion->toString(),
        'to_version' => $toVersion->toString(),
    ]);

    return $sets;
}

// Return empty if the source version is unsupported
if (!$this->isVersionSupported($fromVersion)) {
    return $sets;
}

$targetMajor = $toVersion->getMajor();
$levelSet = self::TYPO3_LEVEL_SETS[$targetMajor] ?? null;

if (null === $levelSet) {
    $this->logger->warning('No level set for target version {version}', [
        'to_version' => $toVersion->toString(),
    ]);

    return $sets;
}

$sets[] = $levelSet;

$this->logger->info('Selected level set for TYPO3 target version', [
    'target_major' => $targetMajor,
    'level_set' => $levelSet,
]);

return $sets;
```

### `TYPO3_LEVEL_SETS` constant to add

```php
/** @var array<int, string> */
private const array TYPO3_LEVEL_SETS = [
    10 => Typo3LevelSetList::UP_TO_TYPO3_10,
    11 => Typo3LevelSetList::UP_TO_TYPO3_11,
    12 => Typo3LevelSetList::UP_TO_TYPO3_12,
    13 => Typo3LevelSetList::UP_TO_TYPO3_13,
    14 => Typo3LevelSetList::UP_TO_TYPO3_14,
];
```

Keys are `int` (from `$toVersion->getMajor()`). Note `TYPO3_VERSION_SETS` keys are `string` — do not confuse.

### Do NOT touch `TYPO3_VERSION_SETS`

The following methods depend on `TYPO3_VERSION_SETS` and must not be modified:
- `getAllAvailableSets()` — iterates to collect all individual sets
- `getSetsForMajorVersion(int $majorVersion)` — returns individual sets for a specific major
- `getVersionSpecificSets(Version $version)` — exact version/major lookup
- `getSupportedVersions()` — array keys
- `isVersionSupported(Version $version)` — `array_key_exists` check
- `SET_METADATA` — keyed by `Typo3SetList::TYPO3_N` constants; unchanged

### Dead import in `generateConfigFileContent()`

Line 144 in `FractorConfigGenerator.php`:
```php
$content .= "use a9f\\Typo3Fractor\\Set\\Typo3LevelSetList;\n";
```

Remove this line. The generated `use` statement is dead because sets are embedded as PHP file-path strings (e.g. `'/path/to/vendor/a9f/typo3-fractor/config/level/up-to-typo3-13.php'`) via `var_export()`, not as class constant references. The `Typo3LevelSetList` class is never referenced in the generated file.

### PHPStan Level 8 notes

- `$toVersion->getMajor()` returns `int`. Array keys in `TYPO3_LEVEL_SETS` must be `int`.
- `self::TYPO3_LEVEL_SETS[$targetMajor] ?? null` — PHPStan will type the result as `string|null`; safe with the null-guard.
- `Typo3LevelSetList` constants are `string` (file paths). No mixed types introduced.

### Test conventions

- PHPUnit 12 with `#[DataProvider]` attribute (not `@dataProvider`)
- `declare(strict_types=1)` in every PHP file
- GPL-2.0-or-later license header in source files (test files may omit it per existing pattern — follow the file's existing style)
- Namespace: `CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Fractor`
- Constructor: `new FractorRuleRegistry(new NullLogger())` — no other dependencies

### Project Structure Notes

- Source: `src/Infrastructure/Analyzer/Fractor/FractorRuleRegistry.php`
- Source: `src/Infrastructure/Analyzer/Fractor/FractorConfigGenerator.php`
- Test: `tests/Unit/Infrastructure/Analyzer/Fractor/FractorRuleRegistryTest.php`
- Test: `tests/Unit/Infrastructure/Analyzer/Fractor/FractorConfigGeneratorTest.php` (verify no assertion on dead import; check test on line 126)
- No new files needed

### References

- Research report: `_bmad-output/planning-artifacts/research/technical-fractor-defect-investigation-2026-06-17.md` — Finding 1 (FractorRuleRegistry) and Dead Code Note (FractorConfigGenerator)
- Sprint Change Proposal: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-06-17.md` — Story `pre-epic-3-fix-fractor-rule-sets`
- Analogous Rector story (completed): `_bmad-output/implementation-artifacts/pre-epic-3-fix-rector-rule-sets.md`
- `Typo3LevelSetList`: `vendor/a9f/typo3-fractor/src/Set/Typo3LevelSetList.php` (confirms constants UP_TO_TYPO3_10–14)
- Level set config: `vendor/a9f/typo3-fractor/config/level/up-to-typo3-13.php` (chains UP_TO_12 + TYPO3_13)
- `FractorRuleRegistry`: `src/Infrastructure/Analyzer/Fractor/FractorRuleRegistry.php:107–129` (range-filter to replace)
- `FractorConfigGenerator`: `src/Infrastructure/Analyzer/Fractor/FractorConfigGenerator.php:144` (dead import to remove)
- `FractorRuleRegistryTest`: `tests/Unit/Infrastructure/Analyzer/Fractor/FractorRuleRegistryTest.php` (stale assertions at lines 47, 60, 73–74)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
