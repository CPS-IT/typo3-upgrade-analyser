# Story 1.4: Fix `InstallationDiscoveryResult::fromArray` Null-Installation TypeError on Cache Replay

Status: done

## Story

As a developer,
I want `InstallationDiscoveryResult::fromArray` to handle a missing or null `installation` key defensively,
so that replaying a cached failure result (or any corrupted cache entry) never throws a `TypeError`.

## Acceptance Criteria

1. When `InstallationDiscoveryResult::fromArray` receives `['successful' => true, 'installation' => null, ...]` (corrupted or stale cache), it must **not** call `Installation::fromArray(null)` and must **not** throw a `TypeError`.
2. In that scenario the method returns a **failed** `InstallationDiscoveryResult` carrying a descriptive error message such as `'Cache deserialization error: successful result is missing installation data'`.
3. When the input is a well-formed successful entry (`successful: true`, `installation` is a non-null array), existing deserialization behaviour is unchanged.
4. When the input is a well-formed failed entry (`successful: false`), existing deserialization behaviour is unchanged.
5. A new unit test in `InstallationDiscoveryResultTest` covers the corrupted-cache scenario (AC 1 and AC 2).
6. All existing tests pass without modification, `composer test` is green, `composer static-analysis` reports zero PHPStan Level 8 errors, and `composer cs:check` reports no style violations.

## Tasks / Subtasks

- [x] Task 1: Fix `InstallationDiscoveryResult::fromArray` (AC: 1, 2, 3, 4)
  - [x] Open `src/Infrastructure/Discovery/InstallationDiscoveryResult.php`
  - [x] In the `if ($data['successful'])` branch (currently line 321–333), add a guard before calling `Installation::fromArray`:
    ```php
    if (!isset($data['installation']) || !\is_array($data['installation'])) {
        return new self(
            null,
            false,
            'Cache deserialization error: successful result is missing installation data',
            null,
            [],
            $data['attempted_strategies'] ?? [],
        );
    }
    ```
  - [x] The guard must appear **before** `Installation::fromArray($data['installation'])` on the current line 323

- [x] Task 2: Add unit test for corrupted-cache scenario (AC: 5)
  - [x] Open `tests/Unit/Infrastructure/Discovery/InstallationDiscoveryResultTest.php`
  - [x] Add test method `testFromArrayReturnsFailedWhenSuccessfulFlagIsTrueButInstallationIsNull()`
  - [x] Arrange: `$data = ['successful' => true, 'installation' => null, 'attempted_strategies' => []]`
  - [x] Assert: `fromArray($data)` returns an `InstallationDiscoveryResult`, `isSuccessful()` is `false`, `getErrorMessage()` contains `'Cache deserialization error'`
  - [x] No `TypeError` must be thrown (PHPUnit will fail the test if one is)

- [x] Task 3: Quality gate (AC: 6)
  - [x] `composer test` — all tests green (1564 tests, 5827 assertions)
  - [x] `composer sca:php` — zero PHPStan Level 8 errors
  - [x] `composer lint:php` — zero style violations

## Dev Notes

### Affected File

`src/Infrastructure/Discovery/InstallationDiscoveryResult.php` — `fromArray()` method, lines 319–343.

### Root Cause

`toArray()` serialises `installation` via the nullable-safe operator:
```php
'installation' => $this->installation?->toArray(false),
```
For any result that was successful at write time, `installation` will be a non-null array. But if the cache entry was written by an older build that had a different bug, or if the cache file is manually edited / truncated, `successful` can be `true` while `installation` is `null` or absent. The current `if ($data['successful'])` guard does **not** protect against that, so `Installation::fromArray(null)` is called, which fails on PHP's type enforcement because `fromArray` is declared `array $data`.

### Fix Shape

Add an early-return guard inside the `if ($data['successful'])` block:

```php
public static function fromArray(array $data): static
{
    if ($data['successful']) {
        if (!isset($data['installation']) || !\is_array($data['installation'])) {
            return new self(
                null,
                false,
                'Cache deserialization error: successful result is missing installation data',
                null,
                [],
                $data['attempted_strategies'] ?? [],
            );
        }
        $installation = Installation::fromArray($data['installation']);

        return new self(
            $installation,
            true,
            '',
            null,
            [],
            $data['attempted_strategies'] ?? [],
        );
    }

    return new self(
        null,
        false,
        $data['error_message'] ?? 'Unknown cached error',
        null,
        [],
        $data['attempted_strategies'] ?? [],
    );
}
```

### Test File Location and Conventions

`tests/Unit/Infrastructure/Discovery/InstallationDiscoveryResultTest.php` — existing test class with ~15 test methods. All existing tests exercise `success()` / `failed()` factory methods; **`fromArray` has no dedicated tests yet**. Follow the existing PHPUnit 10.5 style (no annotations, `#[DataProvider]` for data-driven cases if needed, `#[CoversClass]` already present on the class).

### Existing Coverage Context

- `testToArrayForSuccessfulResult()` (line ~308) verifies round-trip serialisation for the happy path — after this fix, a full round-trip test with a corrupted entry will complement it.
- No changes to `Installation::fromArray`, `toArray`, or any other class are needed.

### PHPStan Note

The new guard uses `\is_array()` which PHPStan understands as a type narrowing assertion, so the `Installation::fromArray($data['installation'])` call below it will be inferred as `array`, satisfying Level 8.

### Project Structure Notes

- Single-file change (`src/Infrastructure/Discovery/InstallationDiscoveryResult.php`) plus one test method — no new files, no DI wiring, no service config changes.
- `InstallationDiscoveryResult` is `final readonly` — no subclass concerns.
- The `new self(...)` constructor is `private` so both the existing code and the new guard already use it correctly.

### References

- [Source: src/Infrastructure/Discovery/InstallationDiscoveryResult.php#fromArray lines 319–343]
- [Source: tests/Unit/Infrastructure/Discovery/InstallationDiscoveryResultTest.php — existing test class]
- Deferred code-review finding raised against story 1.3 review

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- Added null/non-array guard in `InstallationDiscoveryResult::fromArray` before calling `Installation::fromArray`. Guard returns a failed result with a descriptive error message when `installation` key is missing or not an array.
- Added `testFromArrayReturnsFailedWhenSuccessfulFlagIsTrueButInstallationIsNull` covering the corrupted-cache scenario. Removed redundant `assertInstanceOf` (flagged by PHPStan `alreadyNarrowedType`). CS fixer renamed method from snake_case to camelCase.
- All 1564 tests pass, PHPStan Level 8 clean, CS fixer clean.

### File List

- src/Infrastructure/Discovery/InstallationDiscoveryResult.php
- tests/Unit/Infrastructure/Discovery/InstallationDiscoveryResultTest.php
