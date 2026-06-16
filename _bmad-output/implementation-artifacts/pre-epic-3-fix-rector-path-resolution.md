# Story pre-epic-3: Fix public/-Only Path Detection in Typo3RectorAnalyzer (Bug #292)

Status: ready-for-dev

## Story

As a developer running the upgrade analyzer on a TYPO3 composer installation with a non-`public/` web root (e.g., `web/`, `htdocs/`, `app/web/`),
I want the Rector analyzer to either resolve the correct extension path or emit an explicit warning per extension,
so that the tool never silently produces 0 findings due to a non-existent path being passed to Rector.

## Acceptance Criteria

1. When `custom_paths['web-dir']` is set to a non-`public` value (e.g., `'web'`), `getFallbackExtensionPath()` constructs the path using the configured web dir, not the hardcoded `'public/typo3conf'`.
2. When no `custom_paths['web-dir']` is set and no `public/` directory exists, `getFallbackExtensionPath()` still uses `'public'` as default (backward-compatible behavior for standard installations without filesystem confirmation).
3. After `getExtensionPath()` resolves a path (via PathResolutionService or fallback), if the resolved path does not exist on disk, the analyzer logs a `WARNING` and returns an empty `AnalysisResult` (0 findings) rather than passing a non-existent path to Rector.
4. When the resolved path exists, analysis proceeds normally (no change to existing happy-path behavior).
5. The warning message in AC3 includes extension key and resolved path, sufficient for diagnosis.
6. All existing `Typo3RectorAnalyzerTest` tests pass without modification.
7. New unit tests cover AC1, AC2, and AC3.
8. `composer test` green, `composer sca:php` zero errors, `composer lint:php` zero violations.

## Tasks / Subtasks

- [ ] Task 1: Fix `getFallbackExtensionPath()` to use the configured web dir (AC: 1, 2)
  - [ ] In `src/Infrastructure/Analyzer/Typo3RectorAnalyzer.php`, method `getFallbackExtensionPath()`, change the default fallback:
    - Current: `$typo3confDir = $customPaths['typo3conf-dir'] ?? 'public/typo3conf';`
    - New: `$webDir = $customPaths['web-dir'] ?? 'public'; $typo3confDir = $customPaths['typo3conf-dir'] ?? ($webDir . '/typo3conf');`
  - [ ] This ensures that if `web-dir` is `'web'`, the fallback path is `web/typo3conf/ext/{key}` not `public/typo3conf/ext/{key}`

- [ ] Task 2: Add path existence check after `getExtensionPath()` (AC: 3, 4, 5)
  - [ ] In `doAnalyze()`, after the `$extensionPath = $this->getExtensionPath($extension, $context);` call, add:
    ```php
    if (!is_dir($extensionPath)) {
        $this->logger->warning('Extension path does not exist — skipping Rector analysis', [
            'extension' => $extension->getKey(),
            'resolved_path' => $extensionPath,
        ]);
        return $result;
    }
    ```
  - [ ] The early return must occur before `generateRectorConfig()` to avoid passing a non-existent path to Rector

- [ ] Task 3: Update `Typo3RectorAnalyzerTest` (AC: 6, 7)
  - [ ] Existing tests must pass without modification (verify with `composer test`)
  - [ ] Add `testGetFallbackExtensionPathUsesWebDir()`: with `custom_paths['web-dir'] = 'web'` and no `custom_paths['typo3conf-dir']`, assert the resolved path contains `web/typo3conf`
  - [ ] Add `testGetFallbackExtensionPathDefaultsToPublic()`: with empty `custom_paths`, assert the resolved path contains `public/typo3conf`
  - [ ] Add `testDoAnalyzeReturnsEmptyResultWhenPathNotFound()`: set up context with `installation_path` pointing to a temp directory that does NOT contain the extension, assert that `analyze()` returns an `AnalysisResult` with 0 findings and that `configGenerator->generateConfig()` is never called

- [ ] Task 4: Quality gate (AC: 8)
  - [ ] `composer test` — all tests green
  - [ ] `composer sca:php` — zero PHPStan errors
  - [ ] `composer lint:php` — zero violations

## Dev Notes

### Root Cause

Two cooperating bugs in `src/Infrastructure/Analyzer/Typo3RectorAnalyzer.php`:

**Bug A — hardcoded web root in fallback:**

`getFallbackExtensionPath()` (line ~319–329):
```php
private function getFallbackExtensionPath(Extension $extension, string $installationPath, array $customPaths): string
{
    $typo3confDir = $customPaths['typo3conf-dir'] ?? 'public/typo3conf';
    ...
    return $installationPath . '/' . $typo3confDir . '/ext/' . $extension->getKey();
}
```

When `custom_paths` is empty or doesn't contain `'typo3conf-dir'`, the fallback always uses `public/typo3conf`. For installations with `web/`, `htdocs/`, etc. as web root, this produces `{installationPath}/public/typo3conf/ext/{key}` — a path that does not exist.

**Bug B — no path existence check:**

`doAnalyze()` passes the result of `getExtensionPath()` directly to `generateRectorConfig()` and then to `executeRectorAnalysis()`. When Rector receives a path to a directory that does not exist, it exits with code 0 and 0 files processed, emitting no error or warning. The analyzer then reports 0 findings silently.

### Exact Change — Task 1

In `getFallbackExtensionPath()`, replace:
```php
$typo3confDir = $customPaths['typo3conf-dir'] ?? 'public/typo3conf';
```
with:
```php
$webDir = $customPaths['web-dir'] ?? 'public';
$typo3confDir = $customPaths['typo3conf-dir'] ?? ($webDir . '/typo3conf');
```

This preserves the `typo3conf-dir` override when explicitly set, but falls back to the configured `web-dir` (or `public` when not set) to build the default `typo3conf` path.

### Exact Change — Task 2

In `doAnalyze()`, after line ~101 (`$extensionPath = $this->getExtensionPath($extension, $context);`), add:

```php
if (!is_dir($extensionPath)) {
    $this->logger->warning('Extension path does not exist — skipping Rector analysis', [
        'extension' => $extension->getKey(),
        'resolved_path' => $extensionPath,
    ]);
    return $result;
}
```

This must be placed BEFORE the call to `$this->generateRectorConfig(...)`.

### Test Approach for Task 3

The test class constructor for `Typo3RectorAnalyzerTest` already mocks all dependencies. New tests can reuse the existing `setUp()` fixtures.

For `testDoAnalyzeReturnsEmptyResultWhenPathNotFound()`:
- Pass an `AnalysisContext` with `installation_path` pointing to a temp dir (e.g., `sys_get_temp_dir()`)
- Use an extension key that guarantees the path `{tempDir}/public/typo3conf/ext/{key}` does NOT exist
- Mock `pathResolutionService->resolvePath()` to return a failed response (or set it up to return `isSuccess() = false`)
- Assert `configGenerator->generateConfig()` is never called (use `expects($this->never())`)
- The `analyze()` call goes through the cache layer — to bypass caching in the test, either mock `cacheService` to not cache, or use a unique extension key with version that won't hit cache

Note: `doAnalyze()` is `protected` and called indirectly through `analyze()`. The `AbstractCachedAnalyzer::analyze()` calls `doAnalyze()` only if no cache hit. Ensure the cache mock returns `null` for the cache lookup so `doAnalyze()` is actually called.

### What NOT to Change

- Do not modify `determineInstallationType()` — it already correctly reads `custom_paths['web-dir']` for `COMPOSER_CUSTOM` detection
- Do not modify `PathResolutionService` or any strategy — the path existence check in `doAnalyze()` covers the case where PathResolutionService succeeds but returns a non-existent path
- Do not change the `getExtensionPath()` signature or its PathResolutionService call path
- Do not add any new class or service — both changes are confined to `Typo3RectorAnalyzer.php`

### PHPStan Level 8 Notes

- `is_dir(string)` returns `bool` — no type issues
- The early `return $result` in `doAnalyze()` is valid: `$result` is a `new AnalysisResult(...)` with no findings, which is the correct 0-findings return
- `$customPaths['web-dir'] ?? 'public'` — `$customPaths` is `array` from `$context->getConfigurationValue('custom_paths', [])`, key access returns `mixed`; PHPStan level 8 will flag this if the array type is `mixed`. Use `(string)($customPaths['web-dir'] ?? 'public')` if PHPStan complains.

### Project Structure Notes

- Source: `src/Infrastructure/Analyzer/Typo3RectorAnalyzer.php`
- Test: `tests/Unit/Infrastructure/Analyzer/Typo3RectorAnalyzerTest.php`
- No new files needed; no other classes touched

### Test Conventions (from pre-epic-3-fix-rector-rule-sets story)

- PHPUnit 12 with `#[DataProvider]` attribute (not `@dataProvider`)
- `declare(strict_types=1)` in every PHP file
- GPL-2.0-or-later license header
- `#[CoversClass(Typo3RectorAnalyzer::class)]` and `#[AllowMockObjectsWithoutExpectations]` already present on the test class
- Constructor: `new Typo3RectorAnalyzer($this->cacheService, $this->logger, $this->rectorExecutor, $this->configGenerator, $this->resultParser, $this->ruleRegistry, $this->pathResolutionService)`

### References

- Sprint change proposal: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-06-16.md` § "New Story: pre-epic-3-fix-rector-path-resolution"
- Source file: `src/Infrastructure/Analyzer/Typo3RectorAnalyzer.php`
  - `getFallbackExtensionPath()` ~line 318
  - `doAnalyze()` ~line 89
  - `getExtensionPath()` ~line 200
- Existing tests: `tests/Unit/Infrastructure/Analyzer/Typo3RectorAnalyzerTest.php`
- `PathResolutionResponse` DTO: `src/Infrastructure/Path/DTO/PathResolutionResponse.php`
- `AnalysisContext`: `src/Domain/ValueObject/AnalysisContext.php`

## Dev Agent Record

### Agent Model Used

_to be filled_

### Debug Log References

None.

### Completion Notes List

_to be filled_

### File List

- src/Infrastructure/Analyzer/Typo3RectorAnalyzer.php
- tests/Unit/Infrastructure/Analyzer/Typo3RectorAnalyzerTest.php

## Change Log

- 2026-06-16: Story created — fix Bug #292: replace hardcoded `public/typo3conf` fallback with web-dir-aware construction; add path existence check with WARNING before Rector invocation.
