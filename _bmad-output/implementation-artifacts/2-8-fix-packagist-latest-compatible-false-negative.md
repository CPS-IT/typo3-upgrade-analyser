# Story 2.8: Fix Packagist Latest-Compatible False Negative

Status: review

## Story

As a developer,
I want `packagist_latest_compatible` to correctly reflect compatibility when the chronologically latest Packagist version supports the target TYPO3 version,
so that risk scores are not inflated for well-maintained community packages.

## Context

Bug #223. `PackagistClient::getLatestVersionInfo` fetches the latest stable version from Packagist, then
checks whether its TYPO3 require constraint covers the target version. The check delegates to
`ComposerConstraintChecker::isConstraintCompatible`, which uses Composer Semver for parsing.

Two confirmed false negatives:
- `georgringer/news` v14.0.1 targeting TYPO3 13.4 → returns `packagist_latest_compatible: false`
- `friendsoftypo3/tt-address` v10.0.0 targeting TYPO3 13.4 → returns `packagist_latest_compatible: false`

Both packages have compound TYPO3 constraints (`^13.4 || ^14.4` or similar). The root cause is in
`ComposerConstraintChecker` or in how `PackagistClient::getLatestVersionInfo` selects and evaluates
the latest version. No unit tests exist for `ComposerConstraintChecker` (the class has no test file),
so the bug has gone undetected.

**No prerequisite stories.** This bug fix is independent of Story 2-6 (currently in review). Work on
the current main branch state.

**Kickoff checklist note:** F-E-08 from the pre-epic-2 audit concerns `VersionCompatibilityChecker`
(in `TerApiClient`, a different class for TER constraints). Do NOT conflate it with this story.

## Acceptance Criteria

### AC-1: Confirmed cases pass

1. `georgringer/news` v14.0.1 targeting TYPO3 13.4 returns `packagist_latest_compatible: true`
2. `friendsoftypo3/tt-address` v10.0.0 targeting TYPO3 13.4 returns `packagist_latest_compatible: true`

These should be covered by unit tests that use representative constraint strings (not live API calls).

### AC-2: Compound constraint handling

3. `ComposerConstraintChecker::isConstraintCompatible` returns `true` for:
   - `^13.4 || ^14.4` with target `13.4`
   - `^13.4 || ^14.4` with target `14.4`
   - `^12.4 || ^13.4` with target `13.4`
   - `~13.4` with target `13.4`
   - `>=13.4.0,<15.0.0` (AND range) with target `13.4`
   - `^13.0` with target `13.4`
4. `ComposerConstraintChecker::isConstraintCompatible` returns `false` for:
   - `^12.4` with target `13.4`
   - `^14.4` with target `13.4`
   - `^14.0 || ^15.0` with target `13.4`

### AC-3: Version selection in `getLatestVersionInfo` is correct

5. The method picks the highest stable semver version from the Packagist response
6. Branch aliases (keys containing `dev`) are excluded before version selection
7. Pre-release versions (alpha, beta, rc, snapshot) are excluded before version selection

### AC-4: Risk score and recommendation

8. An extension with `packagist_available: true` and `packagist_latest_compatible: true` has risk score ≤ 20
   (this is determined by `VersionAvailabilityAnalyzer` — verify no change needed there, document if it already works once the false negative is fixed)
9. The recommendation message includes the compatible version string where available (verify existing
   `VersionAvailabilityDataProvider` behaviour — no change needed if already implemented)

### AC-5: Tests and quality

10. New test file `tests/Unit/Infrastructure/Version/ComposerConstraintCheckerTest.php` covers:
    - All `true` cases from AC-2
    - All `false` cases from AC-2
    - Constraint parsing exception: falls back gracefully (does not throw)
    - Empty / null constraint string: returns `false` without crash
11. `tests/Unit/Infrastructure/ExternalTool/PackagistClientTest.php` — add or update test cases for
    `getLatestVersionInfo` that exercise compound constraints without mocking the constraint checker
    (use a real `ComposerConstraintChecker` instance or provide constraint-string test data in fixtures)
12. `composer test` passes (all tests green)
13. `composer sca:php` reports zero PHPStan Level 8 errors
14. `composer lint:php` reports zero style issues

## Tasks / Subtasks

- [x] Task 1: Reproduce and diagnose the root cause
  - [x] 1.1 Write a minimal test or script that calls `ComposerConstraintChecker::isConstraintCompatible`
        with `'^13.4 || ^14.4'` and `Version(13.4)` and observe the result
  - [x] 1.2 If the constraint checker itself is correct, trace the failure into
        `PackagistClient::getLatestVersionInfo` — add debug logging or a direct unit test with
        a fixture Packagist response payload containing compound constraints
  - [x] 1.3 Identify the exact line(s) causing the false negative

- [x] Task 2: Fix `ComposerConstraintChecker` (if root cause is here)
  - [x] 2.1 Update `isConstraintCompatible` to handle all compound operators correctly
  - [x] 2.2 Tighten the exception fallback — `str_contains($constraint, major)` is fragile and
        must not be relied upon for compound constraints; only use it as a last resort when
        Composer Semver itself is unavailable

- [x] Task 3: Fix `PackagistClient::getLatestVersionInfo` (if root cause is here)
  - [x] 3.1 Verify that `version_compare`-based sorting correctly picks the highest stable version
  - [x] 3.2 Verify that the version key used to look up `$data['package']['versions'][$latestVersion]`
        matches exactly (with or without `v` prefix) — ltrim is used for sorting but the original
        key is used for lookup; confirm no mismatch
  - [x] 3.3 Fix any identified mismatch

- [x] Task 4: Write `ComposerConstraintCheckerTest`
  - [x] 4.1 Create `tests/Unit/Infrastructure/Version/ComposerConstraintCheckerTest.php`
  - [x] 4.2 Add `isConstraintCompatible` data provider covering all AC-2 cases
  - [x] 4.3 Add test for exception fallback behaviour

- [x] Task 5: Update `PackagistClientTest` for compound constraints
  - [x] 5.1 Add or update `getLatestVersionInfo` test(s) that pass a fixture Packagist response
        with a compound constraint and assert `is_compatible: true`
  - [x] 5.2 Ensure `ComposerConstraintChecker` is NOT mocked in these tests (use real instance
        to catch parsing bugs)

- [x] Task 6: Full verification
  - [x] 6.1 `composer test` — all tests pass
  - [x] 6.2 `composer sca:php` — 0 PHPStan Level 8 errors
  - [x] 6.3 `composer lint:php` — 0 style issues

## Dev Notes

### File Locations

| File | Action |
|------|--------|
| `src/Infrastructure/Version/ComposerConstraintChecker.php` | Modify (if root cause here) |
| `src/Infrastructure/ExternalTool/PackagistClient.php` | Modify (if root cause here) |
| `tests/Unit/Infrastructure/Version/ComposerConstraintCheckerTest.php` | Create (new) |
| `tests/Unit/Infrastructure/ExternalTool/PackagistClientTest.php` | Modify |

### Data Flow

```
PackagistSource::checkAvailability()
  → PackagistClient::getLatestVersionInfo($composerName, $targetVersion)
      → sorts stable version keys from API response
      → picks end($stableVersions) as latest
      → fetches $versionData = $data['package']['versions'][$latestVersion]
      → isVersionCompatible($versionData, $targetVersion)
          → constraintChecker->findTypo3Requirements($versionData['require'])
          → constraintChecker->isConstraintCompatible($constraint, $targetVersion) ← likely bug
  → returns ['latest_version' => ..., 'is_compatible' => ...]
  ← PackagistSource stores 'packagist_latest_compatible' metric
```

### Diagnostic Approach

Compound constraints like `^13.4 || ^14.4` are handled by Composer Semver's `VersionParser::parseConstraints()`.
The issue may be one of:

1. **Direction of `matches()` call** — `$parsedConstraint->matches(new Constraint('=', $normalized))` checks
   whether the two constraint sets intersect. For a `MultiConstraint(OR)`, Composer Semver v3.x requires ALL
   sub-constraints' ranges to be checked. Verify the OR semantics are correctly evaluated in the installed
   `composer/semver` version (`^3.4`).

2. **Version key mismatch in `getLatestVersionInfo`** — After `ltrim($a, 'v')` the sorted array still
   holds the ORIGINAL version keys (e.g., `v14.0.1`), but the lookup uses `$latestVersion` which is
   `end($stableVersions)` — still the original key. No mismatch expected, but confirm with a test.

3. **Exception silently swallowed** — If `parseConstraints` throws for a specific constraint format,
   the fallback `str_contains($constraint, $major)` may return an incorrect result. Add logging to the
   `catch` block to surface these.

### Testing Patterns (from 2-5b)

- PHPUnit ^12.5 attributes only: `#[CoversClass]`, `#[Test]`, `#[DataProvider]`
- No `test` prefix on method names; describe expected behaviour
- `self::assertEquals()` (never `$this->`)
- Mock interfaces, not concrete classes
- Data providers: `public static function descriptiveNameProvider(): iterable`
- For `ComposerConstraintCheckerTest`: instantiate `ComposerConstraintChecker` directly (no mocks needed —
  it only depends on `Composer\Semver\VersionParser` which it constructs itself)

### Example Fixture for PackagistClientTest

The Packagist API response structure for a version with compound constraint:

```php
$packagistResponse = [
    'package' => [
        'versions' => [
            'v14.0.1' => [
                'name' => 'georgringer/news',
                'version' => 'v14.0.1',
                'require' => [
                    'typo3/cms-core' => '^13.4 || ^14.4',
                    'php' => '^8.1',
                ],
            ],
            'v13.4.0' => [
                'name' => 'georgringer/news',
                'version' => 'v13.4.0',
                'require' => [
                    'typo3/cms-core' => '^12.4',
                    'php' => '^8.1',
                ],
            ],
        ],
    ],
];
```

When using a real `ComposerConstraintChecker` (not mocked) in the test, calling
`getLatestVersionInfo('georgringer/news', Version::fromString('13.4'))` with this payload should
return `['latest_version' => '14.0.1', 'is_compatible' => true]`.

### Key Classes

| Class | Namespace | Role |
|-------|-----------|------|
| `ComposerConstraintChecker` | `Infrastructure\Version` | Parses and evaluates Composer constraints |
| `ComposerConstraintCheckerInterface` | `Infrastructure\Version` | Interface (use this for type hints) |
| `PackagistClient` | `Infrastructure\ExternalTool` | Fetches Packagist API + evaluates version compat |
| `PackagistSource` | `Infrastructure\Analyzer\VersionAvailability\Source` | Passes `is_compatible` as `packagist_latest_compatible` |

### Scope Boundaries

- Do NOT modify `TerApiClient`, `VersionCompatibilityChecker`, or TER-related code (separate finding F-E-08).
- Do NOT modify `VersionAvailabilityAnalyzer` risk scoring unless AC-4 verification shows it is broken.
- Do NOT modify `VersionAvailabilityDataProvider` unless AC-4 verification shows the recommendation
  string is missing.
- Story 2-6 (Legacy Git Provider Cleanup) is in review on a separate branch and does not touch
  `PackagistClient` or `ComposerConstraintChecker`. No merge dependency.

### References

- `src/Infrastructure/Version/ComposerConstraintChecker.php:31–56` — `isConstraintCompatible` implementation
- `src/Infrastructure/ExternalTool/PackagistClient.php:98–155` — `getLatestVersionInfo` implementation
- `src/Infrastructure/ExternalTool/PackagistClient.php:244–274` — `isVersionCompatible` private method
- `tests/Unit/Infrastructure/ExternalTool/PackagistClientTest.php` — existing tests (mock `ComposerConstraintCheckerInterface`)
- GitHub Issue #223

## File List

- `src/Domain/ValueObject/Version.php` — modified: added `$hasPatch` flag and `hasPatch(): bool` method to track whether patch was explicitly specified in input
- `src/Infrastructure/Version/ComposerConstraintChecker.php` — modified: uses `major.minor.9999` as ceiling version when target has no explicit patch; exception fallback tightened to return `false`
- `tests/Unit/Infrastructure/Version/ComposerConstraintCheckerTest.php` — created: comprehensive tests covering all AC-2 true/false cases, exception fallback, empty constraint, and confirmed bug-case methods
- `tests/Unit/Infrastructure/ExternalTool/PackagistClientTest.php` — modified: added 5 compound-constraint integration tests using real `ComposerConstraintChecker`

## Dev Agent Record

### Completion Notes

**Root cause (Task 1):** `georgringer/news` v14.0.1 has the Packagist constraint
`^13.4.20 || ^14.0` (NOT `^13.4 || ^14.4` as assumed in the story context). When the user
specifies target `13.4`, `Version` defaults patch to 0, producing `13.4.0.0`. Semver correctly
returns false for `^13.4.20` against `13.4.0.0` because `13.4.0 < 13.4.20`. The bug is a
semantic mismatch: the tool checks a specific patch (`13.4.0`) when the user means the entire
13.4.x minor series.

**Fix (Task 2):** Added `hasPatch(): bool` to `Version` to track whether a patch was explicitly
given in the input string. In `ComposerConstraintChecker::isConstraintCompatible`, when the target
has no explicit patch (e.g. `'13.4'`), the check uses the ceiling `major.minor.9999` instead of
`major.minor.0`. This means `^13.4.20` is correctly treated as compatible with the 13.4.x upgrade
target. Exception fallback also tightened to return `false` instead of fragile `str_contains`.

**Version selection verified (Task 3):** `getLatestVersionInfo` correctly picks the highest stable
semver version key, excludes dev/alpha/beta/rc/snapshot, and lookup uses the original key.

**AC-4 verified (no change needed):** `VersionAvailabilityAnalyzer` uses `packagist_available`
(not `packagist_latest_compatible`) for risk scoring. With `packagist_available: true`, the
risk score is 1.5 (high availability band). `VersionAvailabilityDataProvider` already surfaces
`packagist_latest_version` and `packagist_latest_compatible` in report data.

**Tests added:** 15 new tests in `ComposerConstraintCheckerTest.php` (including confirmed real-world
cases with actual `^13.4.20 || ^14.0` constraint); 5 integration tests added to
`PackagistClientTest.php` using real `ComposerConstraintChecker` (not mocked).

## Change Log

- 2026-04-12: Story created from Sprint Change Proposal 2026-04-12 (Issue #223).
- 2026-04-12: Root cause identified (^13.4.20 || ^14.0 fails against 13.4.0); fix: Version.hasPatch() + ceiling patch in isConstraintCompatible; tests updated with real-world constraints.
