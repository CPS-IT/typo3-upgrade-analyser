# Story 1.7: Add Negative-Case Tests for `InstallationDiscoveryService`

Status: ready-for-dev

## Story

As a developer,
I want `InstallationDiscoveryService::discoverInstallation` to have explicit negative-case test coverage,
so that error paths are verified and future regressions are detected immediately.

## Acceptance Criteria

1. A new test verifies that when `discoverInstallation` is called on a valid directory that contains **no TYPO3 indicators** (no `composer.json`, no `PackageStates.php`, no `typo3/` or `typo3_src/` structure), the result has `isSuccessful() === false` and `getErrorMessage()` is non-empty.
2. No exception is thrown in that scenario.
3. All new tests are in `InstallationDiscoveryServiceTest` — no new test files, no changes to existing tests.
4. `composer test` is green, `composer static-analysis` reports zero PHPStan Level 8 errors, `composer cs:check` reports no violations.

## Tasks / Subtasks

- [ ] Task 1: Add negative-case test (AC: 1, 2, 3)
  - [ ] Open `tests/Unit/Infrastructure/Discovery/InstallationDiscoveryServiceTest.php`
  - [ ] Add test method `testDiscoverInstallationFailsWhenNoTypo3IndicatorsPresent()`
  - [ ] Arrange:
    - Create a real temp directory (use `sys_get_temp_dir()` or `vfsStream`, matching existing test style)
    - Do **not** create `composer.json`, `PackageStates.php`, `typo3/`, or any TYPO3-like structure in it
    - Configure all mock detection strategies so that **none** report support for the path
  - [ ] Assert:
    - `$result->isSuccessful()` is `false`
    - `$result->getErrorMessage()` is a non-empty string
    - No exception is thrown (PHPUnit will fail automatically if one escapes)
  - [ ] Check whether the existing test class already sets up strategies as mocks (it does — see `testDiscoverInstallationSkipsStrategyThatDoesNotSupport`); follow the same pattern to configure mock strategies that return `false` from `supports()` and `canDetect()`

- [ ] Task 2: Quality gate (AC: 4)
  - [ ] `composer test` — all tests green
  - [ ] `composer static-analysis` — zero PHPStan Level 8 errors
  - [ ] `composer cs:check` — zero violations (run `composer cs:fix` if needed)

## Dev Notes

### Gap in Current Coverage

`InstallationDiscoveryServiceTest` currently has:

| Test | What it covers |
|------|---------------|
| `testDiscoverInstallationFailsForNonExistentPath` | Path that doesn't exist as a directory |
| `testDiscoverInstallationSkipsStrategyWithoutRequiredIndicators` | Strategy skipped because indicators absent |
| `testDiscoverInstallationSkipsStrategyThatDoesNotSupport` | Strategy skipped because `supports()` = false |
| `testDiscoverInstallationHandlesStrategyException` | Strategy throws exception |
| `testDiscoverInstallationReturnsNullFromStrategy` | Strategy returns null |

**Missing:** a test where the directory exists and all strategies are tried but none succeed — i.e., the complete "no TYPO3 found" path through the service returns a failed result. The existing tests mock individual behaviour but none assert the final `failed()` result for a complete no-match scenario.

### Service Behaviour on No-Match

From `InstallationDiscoveryService::discoverInstallation` (lines 64–180+):
1. `is_dir` check passes (directory exists)
2. Cache miss (or disabled)
3. Loop through `$this->detectionStrategies`:
   - Each strategy is asked `supports($path)` and `canDetect($path)` — all return false/null
4. No strategy succeeds → service returns `InstallationDiscoveryResult::failed('No compatible detection strategy found', $attemptedStrategies)`

The test just needs to drive this path end-to-end with mock strategies that all decline.

### Test File Location and Conventions

`tests/Unit/Infrastructure/Discovery/InstallationDiscoveryServiceTest.php` — existing test class. Key patterns to follow:
- Test setup uses `setUp()` to initialise mocks; the service is constructed with `$this->detectionStrategies`, `$this->validationRules`, etc.
- `testDiscoverInstallationSkipsStrategyThatDoesNotSupport` (line ~109) is the closest existing analogue — adapt it to assert the **final result** rather than individual mock expectations.
- PHPUnit 10.5 style: no annotations, `assertTrue`/`assertFalse` with clear messages.

### Out of Scope

- Changing `InstallationDiscoveryService` production code (no behaviour change needed)
- Testing `ComposerInstallationDetector` directly (that is a separate unit)
- Integration tests using real fixtures (covered by `Typo3V*DiscoveryTest` classes)

### References

- [Source: src/Infrastructure/Discovery/InstallationDiscoveryService.php#discoverInstallation lines 64–180]
- [Source: tests/Unit/Infrastructure/Discovery/InstallationDiscoveryServiceTest.php — existing test class]
- Deferred code-review finding D3 raised against story 1.3 review

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
