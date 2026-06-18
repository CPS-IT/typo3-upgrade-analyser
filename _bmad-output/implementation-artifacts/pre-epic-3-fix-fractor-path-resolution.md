# Story pre-epic-3: Fix web-dir-aware Fallback Path and Add is_dir Guard in FractorAnalyzer

Status: review

## Story

As a developer running the upgrade analyzer on a TYPO3 composer installation with a non-`public/` web root (e.g., `web/`, `htdocs/`, `app/web/`),
I want the Fractor analyzer to either resolve the correct extension path or skip the extension with an explicit warning,
so that the tool never silently produces 0 findings (or crashes) due to a non-existent path being passed to Fractor.

## Acceptance Criteria

1. When `custom_paths['web-dir']` is set to a non-`public` value (e.g., `'web'`, `'htdocs'`), `getFallbackExtensionPath()` constructs the `typo3conf` directory using the configured web dir, not the hardcoded `'public/typo3conf'`.
2. When no `custom_paths['web-dir']` is set, `getFallbackExtensionPath()` still uses `'public'` as the default web dir (backward-compatible behavior for standard installations).
3. When `custom_paths['typo3conf-dir']` is explicitly set, it continues to override the derived path (existing behavior preserved).
4. After `getExtensionPath()` resolves a path (via PathResolutionService or fallback), if `installation_path` is non-empty and the resolved path does not exist on disk, `doAnalyze()` logs a `WARNING` and returns an empty `AnalysisResult` (0 findings) rather than passing a non-existent path to Fractor.
5. When the resolved path exists (or no installation path is configured), analysis proceeds normally (no change to existing happy-path behavior).
6. The warning message in AC4 includes the extension key and resolved path, sufficient for diagnosis.
7. All existing `FractorAnalyzerTest` tests pass without modification.
8. New unit tests cover AC1, AC2, and AC4.
9. `composer test` green, `composer sca:php` zero errors, `composer lint:php` zero violations.

## Tasks / Subtasks

- [x] Task 1: Fix `getFallbackExtensionPath()` to derive typo3conf dir from web-dir (AC: 1, 2, 3)
  - [x] In `src/Infrastructure/Analyzer/FractorAnalyzer.php`, method `getFallbackExtensionPath()` (~line 325), replace:
    - Current: `$typo3confDir = $customPaths['typo3conf-dir'] ?? 'public/typo3conf';`
    - New:
      ```php
      $webDir = (string) ($customPaths['web-dir'] ?? 'public');
      $typo3confDir = $customPaths['typo3conf-dir'] ?? ($webDir . '/typo3conf');
      ```
  - [x] This ensures that if `web-dir` is `'htdocs'`, the fallback path is `htdocs/typo3conf/ext/{key}` not `public/typo3conf/ext/{key}`, while an explicit `typo3conf-dir` still wins.

- [x] Task 2: Add path existence guard in `doAnalyze()` (AC: 4, 5, 6)
  - [x] In `doAnalyze()`, inside the existing `try` block, immediately after `$extensionPath = $this->getExtensionPath($extension, $context);` (~line 101) and BEFORE `$this->generateFractorConfig(...)`, add:
    ```php
    $installationPath = $context->getConfigurationValue('installation_path', '');

    if (!empty($installationPath) && !is_dir($extensionPath)) {
        $this->logger->warning('Extension path does not exist — skipping Fractor analysis', [
            'extension' => $extension->getKey(),
            'resolved_path' => $extensionPath,
        ]);

        return $result;
    }
    ```
  - [x] The early `return $result;` returns the empty `AnalysisResult` constructed at the top of `doAnalyze()`. The `finally` block still runs `configGenerator->cleanup()` — this is correct and harmless.

- [x] Task 3: Update `FractorAnalyzerTest` (AC: 7, 8)
  - [x] Existing tests must pass without modification (verify with `composer test`).
  - [x] Add `testGetFallbackExtensionPathUsesWebDir()`: with `custom_paths['web-dir'] = 'htdocs'` and no `typo3conf-dir`, force the PathResolutionService mock to fail/return unsuccessful so the fallback path is used, then assert the resolved path contains `htdocs/typo3conf`.
  - [x] Add `testGetFallbackExtensionPathDefaultsToPublic()`: with empty `custom_paths`, force fallback and assert the resolved path contains `public/typo3conf`.
  - [x] Add `testDoAnalyzeReturnsEmptyResultWhenPathNotFound()`: set up context with `installation_path` pointing to a temp directory that does NOT contain the extension; assert `analyze()` returns an `AnalysisResult` with 0 findings and that `configGenerator->generateConfig()` is never called and `fractorExecutor->execute()` is never called.

- [x] Task 4: Quality gate (AC: 9)
  - [x] `composer test` — all tests green
  - [x] `composer sca:php` — zero PHPStan errors
  - [x] `composer lint:php` — zero violations

## Dev Notes

### Root Cause

This is the verbatim Fractor duplicate of Bug #292 (already fixed in `Typo3RectorAnalyzer`, story `pre-epic-3-fix-rector-path-resolution`). Confirmed by `_bmad-output/planning-artifacts/research/technical-fractor-defect-investigation-2026-06-17.md` and `sprint-change-proposal-2026-06-17.md`. Two cooperating bugs in `src/Infrastructure/Analyzer/FractorAnalyzer.php`:

**Bug A — hardcoded web root in fallback** (`getFallbackExtensionPath()`, line 327):
```php
$typo3confDir = $customPaths['typo3conf-dir'] ?? 'public/typo3conf';
```
When `custom_paths` lacks `'typo3conf-dir'`, the fallback always uses `public/typo3conf`. For installations with `web/`, `htdocs/`, etc. as web root, this produces `{installationPath}/public/typo3conf/ext/{key}` — a path that does not exist.

**Bug B — no path existence check** (`doAnalyze()`, line 101): the result of `getExtensionPath()` is passed straight to `generateFractorConfig()` and then `fractorExecutor->execute()`. A non-existent path yields a silent 0-findings result (or a crash, depending on the executor), with no diagnostic.

### Exact Change — Task 1

In `getFallbackExtensionPath()` (lines 325–336), replace line 327:
```php
$typo3confDir = $customPaths['typo3conf-dir'] ?? 'public/typo3conf';
```
with:
```php
$webDir = (string) ($customPaths['web-dir'] ?? 'public');
$typo3confDir = $customPaths['typo3conf-dir'] ?? ($webDir . '/typo3conf');
```
The `(string)` cast is required for PHPStan level 8 (`$customPaths` is `array`, key access returns `mixed`). Leave the rest of the method (the direct-extension-path special case and the standard `…/ext/{key}` return) unchanged.

### Exact Change — Task 2

Insert the guard inside the existing `try` in `doAnalyze()`, between the `getExtensionPath()` call (line 101) and `generateFractorConfig()` (line 104). Note `$installationPath` is NOT in scope inside `doAnalyze()` (it is local to `getExtensionPath()`), so it must be re-read from the context — exactly as done in `Typo3RectorAnalyzer::doAnalyze()` lines 102–111.

### Test Approach for Task 3

The test class constructor mocks all dependencies; reuse the existing `setUp()` fixtures. Constructor argument order:
```php
new FractorAnalyzer(
    $this->cacheService,
    $this->logger,        // NullLogger — cannot assert on warnings directly
    $this->fractorExecutor,
    $this->configGenerator,
    $this->resultParser,
    $this->ruleRegistry,
    $this->pathResolutionService,
)
```

`$this->logger` is a real `NullLogger`, so AC6's warning cannot be asserted via a mock expectation. Verify the skip behavior indirectly: assert `configGenerator->generateConfig()` and `fractorExecutor->execute()` are never called (`$this->expects($this->never())`).

For the fallback-path tests (AC1/AC2), `getFallbackExtensionPath()` is `private` and only reached when PathResolutionService does not return a successful path. Two viable approaches (mirror whichever the existing `Typo3RectorAnalyzerTest` fallback tests use):
- Make `pathResolutionService->resolvePath()` return a response with `isSuccess() === false`, then invoke `getExtensionPath()` via reflection and assert the returned string contains the expected `…/typo3conf` segment, OR
- Invoke `getFallbackExtensionPath()` directly via reflection with a constructed `Extension`, `installationPath`, and `customPaths`.

For `testDoAnalyzeReturnsEmptyResultWhenPathNotFound()`:
- `analyze()` goes through `AbstractCachedAnalyzer`. Set `cacheService->get()` to return `null` (cache miss) so `doAnalyze()` actually runs — see existing `testAnalyzeWithoutCacheHit` at line 169.
- Pass an `AnalysisContext` whose `installation_path` is a real temp dir (`sys_get_temp_dir()`) with `custom_paths => []`, and use an extension key whose resolved path `{tempDir}/public/typo3conf/ext/{key}` does NOT exist.
- Make `pathResolutionService->resolvePath()` return an unsuccessful response so the fallback path is used (the fallback yields the non-existent path).
- Assert `configGenerator->generateConfig()` uses `expects($this->never())` and `fractorExecutor->execute()` uses `expects($this->never())`.
- `cacheService->set()` will be called with the empty result on the early-return path — allow it.
- `configGenerator->cleanup()` runs in `finally` — allow it (do not assert `never`).
- Assert the returned `AnalysisResult` has 0 findings (e.g., `total_findings` metric absent/0, or no recommendations from a findings path).

### What NOT to Change

- Do not modify `determineInstallationType()` — it already correctly reads `custom_paths['web-dir']` for `COMPOSER_CUSTOM` detection (lines 294–319).
- Do not modify `PathResolutionService` or any strategy — the `is_dir` guard in `doAnalyze()` covers the case where PathResolutionService succeeds but returns a non-existent path.
- Do not change the `getExtensionPath()` signature or its PathResolutionService call path.
- Do not add any new class or service — both changes are confined to `FractorAnalyzer.php`.
- Do not touch `FractorRuleRegistry` or `FractorConfigGenerator` — those belong to the sibling story `pre-epic-3-fix-fractor-rule-sets`.

### PHPStan Level 8 Notes

- `is_dir(string)` returns `bool` — no type issues.
- `(string) ($customPaths['web-dir'] ?? 'public')` — required cast because `$customPaths` array key access returns `mixed`.
- The early `return $result;` is valid: `$result` is the `new AnalysisResult($this->getName(), $extension)` constructed at the top of `doAnalyze()` with no findings.

### Project Structure Notes

- Source: `src/Infrastructure/Analyzer/FractorAnalyzer.php`
  - `getFallbackExtensionPath()` ~line 325 (target literal at line 327)
  - `doAnalyze()` ~line 89; `getExtensionPath()` call at line 101; `generateFractorConfig()` at line 104
  - `getExtensionPath()` ~line 207
- Test: `tests/Unit/Infrastructure/Analyzer/FractorAnalyzerTest.php`
- No new files needed; no other classes touched.

### Test Conventions

- PHPUnit 12 with `#[DataProvider]` attribute (not `@dataProvider`).
- `declare(strict_types=1)` in every PHP file; GPL-2.0-or-later license header.
- `#[CoversClass(FractorAnalyzer::class)]` and `#[AllowMockObjectsWithoutExpectations]` already present on the test class.
- Reflection is the established pattern for testing the private/protected analyzer methods in this test class (see `testGetAnalyzerSpecificCacheKeyComponents` at line 211).

### References

- Sprint change proposal: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-06-17.md` § "Story: pre-epic-3-fix-fractor-path-resolution"
- Defect investigation: `_bmad-output/planning-artifacts/research/technical-fractor-defect-investigation-2026-06-17.md`
- Proven analogue (already implemented): `_bmad-output/implementation-artifacts/pre-epic-3-fix-rector-path-resolution.md` and `src/Infrastructure/Analyzer/Typo3RectorAnalyzer.php` (`doAnalyze()` lines 99–157, guard at 102–111)
- Source file: `src/Infrastructure/Analyzer/FractorAnalyzer.php`
- Existing tests: `tests/Unit/Infrastructure/Analyzer/FractorAnalyzerTest.php`
- `AnalysisContext`: `src/Domain/ValueObject/AnalysisContext.php`

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- `vendor/bin/phpunit --testsuite=Unit --no-coverage --filter FractorAnalyzerTest` — 23 tests, 54 assertions, OK (2 pre-existing skips).
- `composer test` — 1681 tests, 6250 assertions, OK (pre-existing deprecations/skips only).
- `composer sca:php` — PHPStan level 8, no errors over 292 files.
- `composer lint:php` — PHP-CS-Fixer dry-run, no violations.

### Completion Notes List

- Task 1: Replaced the hardcoded `'public/typo3conf'` fallback in `getFallbackExtensionPath()` with a web-dir-derived value. `web-dir` now drives the `typo3conf` directory; an explicit `typo3conf-dir` still overrides; absent `web-dir` defaults to `public` (backward compatible). `(string)` cast added for PHPStan level 8 mixed-array access.
- Task 2: Added an `is_dir` guard in `doAnalyze()` immediately after path resolution. When `installation_path` is non-empty and the resolved path does not exist, a WARNING (extension key + resolved path) is logged and the empty `AnalysisResult` is returned before invoking Fractor. The `finally` cleanup still runs.
- Task 3: Added `testGetFallbackExtensionPathUsesWebDir()`, `testGetFallbackExtensionPathDefaultsToPublic()`, and `testDoAnalyzeReturnsEmptyResultWhenPathNotFound()`. The Fractor test uses a real `NullLogger`, so the skip behavior is verified indirectly via `never()` expectations on `configGenerator->generateConfig()` and `fractorExecutor->execute()` plus 0-findings assertions. Existing tests unchanged.
- Task 4: All quality gates pass.

### File List

- src/Infrastructure/Analyzer/FractorAnalyzer.php (modified)
- tests/Unit/Infrastructure/Analyzer/FractorAnalyzerTest.php (modified)

## Change Log

- 2026-06-18: Story created — fix Fractor path resolution (analogue of Bug #292): web-dir-aware fallback in `getFallbackExtensionPath()` + `is_dir` guard with WARNING before Fractor invocation in `doAnalyze()`.
- 2026-06-18: Implemented Tasks 1–4. Web-dir-aware fallback path and `is_dir` guard added to `FractorAnalyzer`; three unit tests added. All quality gates green. Status → review.
