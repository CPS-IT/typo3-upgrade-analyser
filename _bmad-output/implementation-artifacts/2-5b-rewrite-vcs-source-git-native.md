# Story 2.5b: Rewrite VCS Source with Git-Native Resolution

Status: review

## Story

As a developer analyzing a TYPO3 installation,
I want VCS version availability checks to use direct git operations instead of repeated `composer show --working-dir` calls,
so that analysis of installations with private VCS packages completes in seconds rather than hours.

## Context

Story 2-5a introduced a `--working-dir` fallback in `ComposerVersionResolver` to detect VCS packages not on Packagist. While correct, the approach is unusably slow: each `composer show --working-dir ... <package> <version>` call takes ~17–22 seconds for private packages (SSH round-trip per call, no effective cache). For a project with 9 VCS extensions and 42–64 versions each, that is 30–60 minutes per run.

Benchmark evidence (see `tmp/bench-git-ls.php` results):

| Approach | Packages | Versions | Total time | Mean/version |
|----------|----------|----------|------------|--------------|
| `composer show --working-dir` | 4 | 148 | 3 047s | 20.6s |
| git ls-remote + clone + show | 4 | 148 | 15s | 0.019s |
| **Speedup** | | | **203×** | |

The git-native strategy works without any server-side special features (`git archive --remote` is NOT used — that was cancelled in Story 2-3 because it requires `uploadarchive` to be enabled server-side).

## Acceptance Criteria

### AC-1: New `GitVersionResolver` class

1. New class `GitVersionResolver` in `src/Infrastructure/ExternalTool/` implementing `VcsResolverInterface`
2. Resolution strategy per package:
   - Step 1: `git ls-remote --tags <url>` — one network call, returns all tag refs
   - Step 2: Parse semver-ish tags (same pattern as bench: `/^v?\d+[\.\d]*/`), filter to versions strictly greater than `$installedVersion` (newest first)
   - Step 3: Clone once per URL: `git clone --depth=1 --quiet <url> <tmpdir>`
   - Step 4: `git fetch --tags --quiet` — fetches all tag objects into the local clone
   - Step 5: For each candidate version (newest first): `git show <tag>:composer.json`
   - Step 6: Parse TYPO3 constraint from the `composer.json` content
   - Step 7: Return first version with compatible constraint as `VcsResolutionStatus::RESOLVED_COMPATIBLE`
   - Step 8: If all candidates checked and none compatible: `VcsResolutionStatus::RESOLVED_NO_MATCH`
3. Clone directory created in `sys_get_temp_dir()` with a unique subdirectory per URL
4. Clone directory cleaned up in a `try/finally` block wrapping steps 3–8

### AC-2: Lower-bound version filter

5. `GitVersionResolver::resolve()` accepts `?Version $installedVersion` as a parameter
6. Tags with version ≤ `$installedVersion` are excluded from the candidate list before cloning
7. Tags are sorted newest-to-oldest before the clone loop (version comparison, not lexicographic)
8. If `$installedVersion` is null, no lower-bound filter is applied; a reasonable scan cap of 50 versions is enforced (with a WARNING when cap is hit)
9. `VcsSource` extracts the installed version via `$extension->getVersion()` and passes it to the resolver

### AC-3: Clone deduplication per repository URL

10. `GitVersionResolver` maintains a per-instance clone cache: `array<string, string>` mapping repository URL → tmpdir
11. When `resolve()` is called for a second package from the same repository URL, the existing clone is reused (no re-clone)
12. All cached clone directories are cleaned up via `register_shutdown_function()` registered once on first clone creation
13. Additionally, an explicit `reset()` method is provided to allow clearing the cache between analysis runs (for long-running processes)

### AC-4: SSH reachability pre-check

14. For SSH-based VCS URLs (`git@host:...`, `ssh://...`), attempt `ssh -T -o ConnectTimeout=5 git@<host>` before the first git operation on that host
15. Cache result per host for the duration of the PHP process in `$sshHostStatus: array<string, bool>`
16. If host unreachable (exit 255): return `VcsResolutionStatus::SSH_UNREACHABLE` immediately (no git ls-remote, no clone)
17. Single WARNING per unreachable host (same deduplication as 2-5a `VcsSource::handleSshUnreachable()`)
18. HTTPS-based git URLs (`https://...`) bypass SSH pre-check entirely

### AC-5: Only git URLs supported

19. Non-git VCS URLs (Mercurial `hg://`, Subversion `svn://`) are not supported; if encountered, log WARNING and return `VcsResolutionStatus::FAILURE`
20. Supported URL schemes: `git@host:path.git`, `https://host/path.git`, `ssh://git@host/path.git`
21. `VcsSource` skips resolution and returns the default Unknown response immediately when `$repositoryUrl` is null (no change from 2-5a)

### AC-6: `VcsResolverInterface` updated for git-native approach

22. `VcsResolverInterface::resolve()` signature updated:
    - Remove `?string $installationPath = null` (no longer needed)
    - Add `?Version $installedVersion = null` (lower-bound filter)
    - Full new signature: `resolve(string $packageName, ?string $vcsUrl, Version $targetVersion, ?Version $installedVersion = null): VcsResolutionResult`
23. `ComposerVersionResolver` removed — the `--working-dir` approach is superseded by `GitVersionResolver`
24. `services.yaml`: `VcsResolverInterface` alias updated from `ComposerVersionResolver` to `GitVersionResolver`

### AC-7: `VersionAvailabilityAnalyzer::getRequiredTools()` aggregates all source requirements

25. `getRequiredTools()` returns `['curl', 'git']`
26. `hasRequiredTools()` checks both `curl_init` function availability AND presence of `git` executable in PATH
27. When `git` is absent, the analyzer's `analyze()` method logs a WARNING and skips VCS source checks (returns `VcsAvailability::Unknown` for all VCS metrics)

### AC-8: `ComposerVersionResolver` and `ComposerEnvironment` cleanup

28. `ComposerVersionResolver` class deleted
29. `ComposerEnvironment` class deleted if it has no other consumers; otherwise left for now (check with grep)
30. `VcsResolverInterface` no longer references `$installationPath` in PHPDoc or implementations
31. `VcsSource` removes `handleSshUnreachable()` deduplication for SSH host warnings — `GitVersionResolver` now owns SSH pre-check and emits warnings at the resolver level; OR `VcsSource` retains host-level deduplication and `GitVersionResolver` returns `SSH_UNREACHABLE` without logging (preferred: keep warning in `VcsSource::handleSshUnreachable()`)

### AC-9: Test coverage

32. Unit test: `GitVersionResolver` — `git ls-remote` returns tags, lower-bound filter excludes older versions
33. Unit test: `GitVersionResolver` — newest-compatible version returned as `RESOLVED_COMPATIBLE`
34. Unit test: `GitVersionResolver` — no compatible version → `RESOLVED_NO_MATCH`
35. Unit test: `GitVersionResolver` — null `$repositoryUrl` → `NOT_FOUND`
36. Unit test: `GitVersionResolver` — SSH pre-check unreachable → `SSH_UNREACHABLE`
37. Unit test: `GitVersionResolver` — HTTPS URL bypasses SSH check
38. Unit test: `GitVersionResolver` — clone reused for second package with same URL
39. Unit test: `VcsSource` — passes `$extension->getVersion()` as installed version to resolver
40. Unit test: `VersionAvailabilityAnalyzer::getRequiredTools()` returns `['curl', 'git']`
41. All existing tests pass (no regression)
42. PHPStan Level 8: 0 errors; `composer lint:php`: 0 issues

## Tasks / Subtasks

- [x] Task 0: Prepare — check `ComposerEnvironment` consumers and plan deletion scope
  - [x] 0.1 Grep all usages of `ComposerEnvironment` and `ComposerVersionResolver` outside of `VcsSource`
  - [x] 0.2 Decide: delete `ComposerEnvironment` outright or leave (no story-blocking either way)

- [x] Task 1: Update `VcsResolverInterface` (AC: 6)
  - [x] 1.1 Replace `?string $installationPath = null` with `?Version $installedVersion = null` in `resolve()` signature
  - [x] 1.2 Update PHPDoc

- [x] Task 2: Create `GitVersionResolver` (AC: 1, 2, 3, 4, 5)
  - [x] 2.1 Create `src/Infrastructure/ExternalTool/GitVersionResolver.php`
  - [x] 2.2 Implement `git ls-remote --tags <url>` tag enumeration
  - [x] 2.3 Implement semver tag parsing and lower-bound filter (≥ installedVersion, newest first)
  - [x] 2.4 Implement scan cap (50 versions, WARNING when hit)
  - [x] 2.5 Implement `git clone --depth=1` + `git fetch --tags` + `git show <tag>:composer.json`
  - [x] 2.6 Implement `composer.json` TYPO3 constraint parsing (reuse/extract from `ComposerVersionResolver`)
  - [x] 2.7 Implement clone deduplication cache + `register_shutdown_function` cleanup
  - [x] 2.8 Implement explicit `reset()` method for clearing clone cache
  - [x] 2.9 Implement SSH pre-check with per-host cache (reuse `extractSshHost()` from `ComposerVersionResolver`)
  - [x] 2.10 Handle unsupported VCS URL schemes (WARNING + FAILURE)
  - [x] 2.11 Use `?\Closure $processFactory` pattern for testability (same as `ComposerVersionResolver`)

- [x] Task 3: Update `VcsSource` (AC: 2, 5)
  - [x] 3.1 Pass `$extension->getVersion()` as `$installedVersion` to `$this->resolver->resolve()`
  - [x] 3.2 Remove `$context->getInstallationPath()` argument from `resolve()` call
  - [x] 3.3 Verify `handleSshUnreachable()` and `handleFailure()` deduplication still correct for new resolver output

- [x] Task 4: Update `VersionAvailabilityAnalyzer` (AC: 7)
  - [x] 4.1 `getRequiredTools()`: return `['curl', 'git']`
  - [x] 4.2 `hasRequiredTools()`: add `git` executable check via Symfony Process
  - [x] 4.3 In `analyze()`: when `git` absent, skip VCS checks and log WARNING

- [x] Task 5: Wire up `GitVersionResolver` in DI (AC: 6)
  - [x] 5.1 Update `config/services.yaml`: alias `VcsResolverInterface` → `GitVersionResolver`
  - [x] 5.2 Ensure `GitVersionResolver` constructor arguments are resolved by auto-wiring

- [x] Task 6: Remove `ComposerVersionResolver` (AC: 6, 8)
  - [x] 6.1 Delete `src/Infrastructure/ExternalTool/ComposerVersionResolver.php`
  - [x] 6.2 Delete `src/Infrastructure/ExternalTool/ComposerEnvironment.php` (no other consumers)
  - [x] 6.3 Delete `tests/Unit/Infrastructure/ExternalTool/ComposerVersionResolverTest.php`

- [x] Task 7: Full verification (AC: 9)
  - [x] 7.1 Run full test suite: `composer test` — 1784 tests pass
  - [x] 7.2 PHPStan Level 8: `composer sca:php` — 0 errors
  - [x] 7.3 Code style: `composer lint:php` — 0 issues

## Dev Notes

### Why This Approach

`git archive --remote` was the original plan (Story 2-3) but requires `uploadarchive` to be enabled server-side — disabled on both GitHub and most GitLab installations. The approach used here (clone + fetch tags + local show) is universally compatible and requires only standard git client access.

The key insight: after a one-time clone (~1s) and tag fetch (~1.4s), all `git show <tag>:composer.json` calls are purely local at ~19ms each. Network cost is paid once per repository, not once per version.

### Composer.json Parsing

`GitVersionResolver` must parse the TYPO3 constraint from the `composer.json` content retrieved via `git show`. Reuse the existing parsing logic from `ComposerVersionResolver` (`findTypo3Requirements()` and `isConstraintCompatible()`). Extract to a shared helper or copy (the methods are private static in `ComposerVersionResolver` — move to a small `ComposerJsonParser` utility class or inline in `GitVersionResolver`).

The relevant constraint keys to check in `composer.json`:
```json
{
  "require": {
    "typo3/cms-core": "^12.0",
    "typo3/cms": "^12.0"
  }
}
```

### Version Sorting

Version comparison for the lower-bound filter and newest-first sort must handle:
- Semver: `1.2.3 > 1.2.0`
- Prefix `v`: `v1.2.3` → `1.2.3`
- Non-semver tags to be silently skipped (not included in candidates)

Use PHP's `version_compare()` for ordering. Semver tags matching `/^v?\d+(\.\d+)*$/` are included; anything else (branch-like tags, date-based) is excluded.

### Lower-Bound Filter Logic

```
installedVersion = $extension->getVersion() // e.g. "2.5.0"
candidates = tags where version_compare(tag, installedVersion, '>') === true
sort candidates newest-first
scan candidates[0..N] for compatible TYPO3 constraint
```

When `$installedVersion` is null (hypothetical — all extensions in composer.lock have a version), fall back to: scan newest 50 versions.

### SSH Pre-Check Extraction

Copy `extractSshHost()` and `isSshHostReachable()` logic from `ComposerVersionResolver` into `GitVersionResolver`. The logic is:
- `git@host:path` → probe `git@host` with `ssh -T -o ConnectTimeout=5`
- `ssh://git@host/path` → probe `git@host`
- `https://...` → no probe needed

Exit code 1 from SSH means "authenticated but no shell" (normal for GitHub/GitLab). Exit code 255 means connection refused/timeout (unreachable).

### Process Factory Pattern

Inject `?\Closure $processFactory` for unit testing:
```php
public function __construct(
    private readonly LoggerInterface $logger,
    private readonly ComposerConstraintCheckerInterface $constraintChecker,
    private readonly int $timeoutSeconds = 60,
    private readonly ?\Closure $processFactory = null,
)
```

All `git` subprocess calls go through the factory. Unit tests inject a factory that returns stubs without executing real processes.

The timeout for `git ls-remote` and `git clone` should be configurable (default 60s — longer than the 30s composer timeout since SSH clone can be slower for large repos).

### `hasRequiredTools()` Implementation

```php
public function hasRequiredTools(): bool
{
    if (!\function_exists('curl_init')) {
        return false;
    }
    // Check git is in PATH
    $process = new Process(['git', '--version']);
    $process->run();
    return $process->isSuccessful();
}
```

### Files to Create

**New (PHP):**
- `src/Infrastructure/ExternalTool/GitVersionResolver.php`

### Files to Modify

**Modify (PHP):**
- `src/Infrastructure/ExternalTool/VcsResolverInterface.php` — update `resolve()` signature
- `src/Infrastructure/Analyzer/VersionAvailability/Source/VcsSource.php` — pass `$extension->getVersion()`, remove `$installationPath`
- `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php` — update `getRequiredTools()` and `hasRequiredTools()`

**Modify (Config):**
- `config/services.yaml` — alias `VcsResolverInterface` → `GitVersionResolver`

### Files to Delete

**Delete (PHP):**
- `src/Infrastructure/ExternalTool/ComposerVersionResolver.php`
- `src/Infrastructure/ExternalTool/ComposerEnvironment.php` (if no other consumers)

**Delete (Tests):**
- `tests/Unit/Infrastructure/ExternalTool/ComposerVersionResolverTest.php`

### Files to Create (Tests)

**New (Tests):**
- `tests/Unit/Infrastructure/ExternalTool/GitVersionResolverTest.php`

### Files to Modify (Tests)

**Modify (Tests):**
- `tests/Unit/Infrastructure/Analyzer/VersionAvailability/Source/VcsSourceTest.php` — update resolver call assertions (pass Version, not installationPath)
- `tests/Unit/Infrastructure/Analyzer/VersionAvailabilityAnalyzerTest.php` — update `getRequiredTools()` assertion
- `tests/Integration/ExternalTool/WorkingDirFallbackIntegrationTest.php` — delete or rewrite as git-native integration test (gated by `TYPO3_ANALYZER_SKIP_GIT_TESTS` env var)

### Testing Patterns

- PHPUnit ^12.5 attributes only: `#[CoversClass]`, `#[Test]`, `#[DataProvider]`
- No `test` prefix on methods; method names describe expected behaviour
- `self::assertEquals()` (never `$this->`)
- Mock interfaces, not concrete classes
- Use `createStub()` for Process objects (PHPUnit 12 compatibility)
- Data providers: `public static function descriptiveNameProvider(): iterable`
- Use `?\Closure $processFactory` for all subprocess calls in unit tests

### Known Risks

- **Clone size**: `--depth=1` limits history but fetching all tags (`git fetch --tags`) still pulls all tag objects. For repos with many tags (64+ in zug-project), this is fast (~1.3s) but uses disk space. Monitor tmpdir usage.
- **Tag-only clones**: Some repos use lightweight tags only (no annotated tag objects). `git show <tag>:composer.json` works for both lightweight and annotated tags.
- **Monorepo paths**: If a package's `composer.json` is not at the repo root but in a subdirectory, `git show <tag>:composer.json` will fail. This is an edge case not present in the test data. Return `NOT_FOUND` for such failures and log DEBUG.
- **`register_shutdown_function` in tests**: The shutdown function may interfere with test isolation. Ensure test tmpdir cleanup is immediate (via `reset()` or explicit `try/finally` in tests).

### Performance Reference

From `tmp/bench-git-ls.php` output:

| Package | Tags | ls-remote | clone | fetch | show total | speedup |
|---------|------|-----------|-------|-------|------------|---------|
| cpsit/record-content | 7 | 0.83s | 0.80s | 0.98s | 0.14s | 44.7× |
| cpsit/zug-caretaker | 42 | 0.69s | 1.03s | 1.13s | 0.83s | 226.7× |
| fr/iki-event-approval | 35 | 0.70s | 1.01s | 2.01s | 0.65s | 93.9× |
| fr/zug-project | 64 | 0.74s | 0.96s | 1.30s | 1.19s | 399.8× |
| **Overall** | **148** | **2.97s** | **3.80s** | **5.42s** | **2.81s** | **203×** |

With the lower-bound filter (only versions newer than installed), the `git show` step will be even faster — most packages will only need to check the latest few versions.

### References

- [Source: tmp/bench-git-ls.php] — benchmark script with full timings
- [Source: tmp/bench-working-dir.php] — baseline: 4558s for 204 versions
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.3] — Story 2-3 cancelled note (git archive --remote non-functional)
- [Source: _bmad-output/implementation-artifacts/2-5a-fix-vcs-detection-non-packagist.md] — predecessor story
- [Source: src/Infrastructure/ExternalTool/ComposerVersionResolver.php] — parsing logic to reuse
- [Source: src/Infrastructure/Analyzer/VersionAvailability/Source/VcsSource.php] — class to update
- [Source: src/Domain/Entity/Extension.php] — `getVersion()` for installed version lower bound

## Dev Agent Record

### Implementation Notes

- `ComposerEnvironment` had no consumers beyond `ComposerVersionResolver` — both deleted.
- `GenericGitResolver` (Story 2-3, legacy) updated to match new `VcsResolverInterface` signature (parameter rename only, unused).
- `VcsDetectionNonPackagistTest` and `ComposerEnvironmentTest` deleted — they covered deleted classes.
- Integration test files `MixedAnalysisIntegrationTestCase` and `VersionAvailabilityIntegrationTestCase` updated to use `GitVersionResolver`.
- `VcsSourceTest::installationPathPassedToResolver` renamed to `installedVersionPassedToResolver` and updated to assert `Version` is passed, not `null`.
- `register_shutdown_function` registered once per resolver instance on first clone creation; `reset()` method provided for explicit cleanup.
- PHPStan Level 8: 0 errors. Tests: 1784 pass, 0 failures. Lint: 0 issues.

## File List

### New
- `src/Infrastructure/ExternalTool/GitVersionResolver.php`
- `tests/Unit/Infrastructure/ExternalTool/GitVersionResolverTest.php`

### Modified
- `src/Infrastructure/ExternalTool/VcsResolverInterface.php`
- `src/Infrastructure/ExternalTool/GenericGitResolver.php`
- `src/Infrastructure/Analyzer/VersionAvailability/Source/VcsSource.php`
- `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php`
- `config/services.yaml`
- `tests/Unit/Infrastructure/Analyzer/VersionAvailability/Source/VcsSourceTest.php`
- `tests/Unit/Infrastructure/Analyzer/VersionAvailabilityAnalyzerTest.php`
- `tests/Integration/MixedAnalysisIntegrationTestCase.php`
- `tests/Integration/Analyzer/VersionAvailabilityIntegrationTestCase.php`

### Deleted
- `src/Infrastructure/ExternalTool/ComposerVersionResolver.php`
- `src/Infrastructure/ExternalTool/ComposerEnvironment.php`
- `tests/Unit/Infrastructure/ExternalTool/ComposerVersionResolverTest.php`
- `tests/Unit/Infrastructure/ExternalTool/ComposerEnvironmentTest.php`
- `tests/Unit/Infrastructure/ExternalTool/VcsDetectionNonPackagistTest.php`
- `tests/Integration/ExternalTool/WorkingDirFallbackIntegrationTest.php`

## Change Log

- 2026-04-07: Story drafted based on benchmark findings from bench-git-ls.php and user constraints from conversation.
- 2026-04-07: Implementation complete — GitVersionResolver created, ComposerVersionResolver/ComposerEnvironment deleted, all tests pass.
