# Story 1.6: Fix `ExtensionDiscoveryService` Cache Key Double-Computation

Status: done

## Story

As a developer,
I want the cache key in `ExtensionDiscoveryService::discoverExtensions` to be computed exactly once and reused,
so that a cache miss on read can never be caused by a diverged key on write.

## Acceptance Criteria

1. `$cacheKey` is computed **once** at the start of `discoverExtensions` (before the cache-read check) and the same variable is passed to both `$this->cacheService->get($cacheKey)` and `$this->cacheService->set($cacheKey, ...)`.
2. The second `$this->cacheService->generateKey(...)` call (currently at line 130–132) is removed.
3. The `$cacheKey` variable must be in scope regardless of whether cache is enabled, so it is assigned unconditionally (before the `if ($this->configService->isResultCacheEnabled())` guard).
4. A unit test verifies that when cache is enabled and a cache miss occurs, the key passed to `CacheService::set` equals the key passed to `CacheService::get`.
5. All existing cache tests (hit, miss, disabled) continue to pass unchanged.
6. `composer test` is green, `composer static-analysis` reports zero PHPStan Level 8 errors, `composer cs:check` reports no violations.

## Tasks / Subtasks

- [x] Task 1: Refactor `discoverExtensions` to compute cache key once (AC: 1, 2, 3)
  - [x] Open `src/Infrastructure/Discovery/ExtensionDiscoveryService.php`
  - [x] Move `$cacheKey = $this->cacheService->generateKey(...)` to the top of `discoverExtensions`, **before** the `if ($this->configService->isResultCacheEnabled())` block (approximately line 44), so it runs unconditionally
  - [x] Remove the duplicate `$cacheKey = ...` assignment inside the cache-write block (currently lines 130–132)
  - [x] The resulting structure should be:
    ```php
    $cacheKey = $this->cacheService->generateKey('extension_discovery', $installationPath, [
        'custom_paths' => $customPaths ?? [],
    ]);

    if ($this->configService->isResultCacheEnabled()) {
        $cachedResult = $this->cacheService->get($cacheKey);
        if (null !== $cachedResult) { ... }
    }

    // ... discovery logic ...

    if ($this->configService->isResultCacheEnabled()) {
        $this->cacheService->set($cacheKey, $this->serializeResult($result));
    }
    ```

- [x] Task 2: Add unit test for key identity (AC: 4)
  - [x] Open `tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php`
  - [x] Add test `testCacheKeyIsIdenticalForGetAndSet()` (or similar name)
  - [x] Arrange: enable cache, configure `CacheService` mock to return `null` from `get` (miss)
  - [x] Capture the key passed to `CacheService::get` and the key passed to `CacheService::set`
  - [x] Assert both keys are equal (use `$this->callback()` or argument capture)
  - [x] The existing `testDiscoverExtensionsWithCacheEnabled` and `testDiscoverExtensionsWithCacheHit` tests cover behaviour; this test specifically asserts key identity

- [x] Task 3: Quality gate (AC: 6)
  - [x] `composer test` — all tests green
  - [x] `composer sca:php` — zero PHPStan Level 8 errors
  - [x] `composer lint:php` — zero violations

## Dev Notes

### Root Cause and Risk

In `ExtensionDiscoveryService::discoverExtensions` the cache key is computed **twice** from the same inputs:

- **Read** (lines 44–47):
  ```php
  $cacheKey = $this->cacheService->generateKey('extension_discovery', $installationPath, [
      'custom_paths' => $customPaths ?? [],
  ]);
  $cachedResult = $this->cacheService->get($cacheKey);
  ```

- **Write** (lines 129–132):
  ```php
  $cacheKey = $this->cacheService->generateKey('extension_discovery', $installationPath, [
      'custom_paths' => $customPaths ?? [],
  ]);
  $this->cacheService->set($cacheKey, ...);
  ```

PHP arrays are value types (copy-on-write), so `$customPaths` cannot be mutated between the two calls in ordinary flow. However:

1. The double computation is a code-smell and a latent bug: any future modification that passes `$customPaths` by reference to `resolvePaths` or another method between the two key computations would cause a permanent cache miss that would be very hard to diagnose.
2. An extra call to `generateKey` (even if cheap) is redundant.

The fix is a pure refactoring — move the key computation before the first `if` guard. No observable behaviour changes.

### Affected File

`src/Infrastructure/Discovery/ExtensionDiscoveryService.php` — `discoverExtensions()` method, lines 38–142.

### Test File Location and Conventions

`tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php` — existing test class. The relevant existing tests are:
- `testDiscoverExtensionsWithCacheEnabled` (line ~377) — verifies `set` is called
- `testDiscoverExtensionsWithCacheHit` (line ~405) — verifies `get` returns cached result
- `testDiscoverExtensionsWithCacheDisabled` (line ~454) — verifies neither `get` nor `set` is called

Use PHPUnit 10.5's `$this->exactly(1)` and `$this->callback()` matchers for key-capture assertions. Follow existing test style (no annotations).

### PHPStan Note

`$cacheKey` will be assigned unconditionally (before the `if` guard), so PHPStan will not complain about possible-undefined-variable on the `set` call. This also resolves any potential PHPStan warning about the variable being reassigned without use.

### Project Structure Notes

- Single-file change in `src/` plus one new test method — no new files, no DI wiring, no config changes.
- `InstallationDiscoveryService` has the same double-computation pattern (lines 77 and 166); that is a separate pre-existing issue and out of scope here.

### References

- [Source: src/Infrastructure/Discovery/ExtensionDiscoveryService.php#discoverExtensions lines 38–142]
- [Source: tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php — existing test class]
- Deferred code-review finding D2 raised against story 1.3 review

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- Moved `$cacheKey` computation before the `if (isResultCacheEnabled())` guard so it is assigned unconditionally once.
- Removed the duplicate `$cacheKey = ...` assignment in the cache-write block.
- Updated `testDiscoverExtensionsWithCacheEnabled` mock from `exactly(2)` to `exactly(1)` for `generateKey`.
- Updated `testDiscoverExtensionsWithCacheDisabled` to allow one call to `generateKey` (now unconditional), while `get`/`set` remain never-called.
- Added `testCacheKeyIsIdenticalForGetAndSet` capturing keys via `$this->callback()` and asserting equality.
- All 1565 tests pass; PHPStan Level 8 zero errors; CS fixer zero violations.

### File List

- src/Infrastructure/Discovery/ExtensionDiscoveryService.php
- tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php

### Change Log

- 2026-03-23: Refactored cache key to single computation; added key-identity unit test (story 1.6)
