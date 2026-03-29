# Story 2.3: Generic Git Resolver (Tier 2 Fallback)

Status: cancelled (Sprint Change Proposal 2026-03-29c — git archive --remote non-functional for hosted git services; Composer CLI covers all VCS types)

## Story

As a developer,
I want a fallback resolver using `git ls-remote` for VCS URLs that Composer could not resolve,
so that extensions on hosts without Packagist integration still get version availability data.

## Acceptance Criteria

1. **Given** `PackagistVersionResolver::resolve()` returned a result where `shouldTryFallback()` is `true` (status `NOT_FOUND` or `FAILURE`), **when** `GenericGitResolver::resolve(string $packageName, string $vcsUrl, Version $targetVersion): VcsResolutionResult` is called, **then** it executes `git ls-remote -t --refs <vcsUrl>` to list available tag refs. Use `-t --refs`, NOT `--tags` alone — the `--refs` flag suppresses peeled `^{}` entries (architecture requirement, VcsResolutionSpike.md §4.1).

2. **Tag parsing rules** (applied to each line of ls-remote output):
   - Raw line format: `<40-char-hash>\trefs/tags/<tagName>`
   - `X.Y.Z` → `X.Y.Z` (identity — dominant TYPO3 extension pattern per spike §4.2)
   - `vX.Y.Z` → `X.Y.Z` (strip `v` prefix)
   - `X.Y.Z-RCN` → `X.Y.Z-RCN` (pre-release: preserve as-is)
   - `vX.Y.Z-RCN` → `X.Y.Z-RCN` (strip `v` prefix)
   - `dev-*`, `vX.Yalpha`, and any other non-semver pattern → **skip** (not valid versions)
   - Valid tag regex: `/^v?(\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?)$/`

3. **Most recent stable tag:** From valid parsed tags, find the most recent **stable** tag (no pre-release suffix) using `version_compare()` sorted newest-to-oldest. If no stable tags exist after parsing, return `VcsResolutionResult(RESOLVED_NO_MATCH, $vcsUrl, null)`.

4. **Compatibility check (Option B — most recent stable tag only, spike §9):** For the most recent stable tag:
   a. Attempt to fetch `composer.json` via subprocess: `git archive --remote=<vcsUrl> refs/tags/<tag> -- composer.json | tar -xO`
   b. If subprocess exits non-zero or stdout is empty: log DEBUG `"git archive failed for {url} tag {tag} — treating as compatible"` and return `RESOLVED_COMPATIBLE` with that version string.
   c. If fetch succeeds: decode JSON. If malformed JSON: log WARNING and return `RESOLVED_COMPATIBLE` with that version (same fallback as (b)).
   d. If decoded: use `ComposerConstraintCheckerInterface::findTypo3Requirements($composerJson['require'] ?? [])` + `isConstraintCompatible()`. If no TYPO3 requirements found: treat as compatible (identical to `PackagistVersionResolver::isCompatible` — extensions without declared TYPO3 dep are assumed compatible).
   e. If compatible: return `RESOLVED_COMPATIBLE` with that version string.
   f. If NOT compatible: return `RESOLVED_NO_MATCH`.

   **CRITICAL — Do NOT use `isComposerJsonCompatible()`:** `ComposerConstraintChecker::isComposerJsonCompatible(null)` and `isComposerJsonCompatible(['require' => []])` both return `false` — this contradicts the "no TYPO3 constraint = compatible" design used in `PackagistVersionResolver`. Use `findTypo3Requirements()` + `isConstraintCompatible()` instead.

5. **Return values** — `VcsResolutionResult` is non-nullable:
   - `VcsResolutionStatus::RESOLVED_COMPATIBLE` — a stable tag exists and compatibility check passed (or no TYPO3 constraint, or `git archive` unavailable); `latestCompatibleVersion` = the version string.
   - `VcsResolutionStatus::RESOLVED_NO_MATCH` — stable tags exist but the most recent is not TYPO3-compatible; `latestCompatibleVersion` = `null`.
   - `VcsResolutionStatus::FAILURE` — `git ls-remote` itself failed (network, auth, git not installed, timeout); `latestCompatibleVersion` = `null`.
   - **`NOT_FOUND` is never used** — `GenericGitResolver` is the last resort tier.

6. **On `FAILURE`:** Log a WARNING including `$vcsUrl` and the failure reason. The caller (Story 2.5 integration) continues analysis for other extensions.

7. **Configurable timeout** via constructor (default: 30 s). `ProcessTimedOutException` on `git ls-remote` → `FAILURE` with WARNING.

8. **Git binary unavailability:** If the git subprocess throws `\Symfony\Component\Process\Exception\ProcessFailedException` on launch or returns a "not found"-like error before producing output: return `FAILURE`. Log WARNING: `"git binary not available — GenericGitResolver cannot resolve {url}"`.

9. `GenericGitResolver` lives in `src/Infrastructure/ExternalTool/GenericGitResolver.php`. Constructor:

   ```php
   /**
    * @param (\Closure(list<string>): Process)|null $processFactory
    */
   public function __construct(
       private readonly LoggerInterface $logger,
       private readonly ComposerConstraintCheckerInterface $constraintChecker,
       private readonly int $timeoutSeconds = 30,
       private readonly ?\Closure $processFactory = null,
   ) {}
   ```

   Note: No `ComposerEnvironment` dependency — `GenericGitResolver` uses git, not Composer.

10. A `VcsResolverInterface` is created in `src/Infrastructure/ExternalTool/VcsResolverInterface.php` with a single method:
    ```php
    resolve(string $packageName, string $vcsUrl, Version $targetVersion): VcsResolutionResult
    ```
    The interface carries no other methods and has no dependency on any framework type.

11. `GenericGitResolver` declares `implements VcsResolverInterface`.

12. Unit tests cover all return status variants (see Dev Notes for test patterns). PHPStan Level 8 reports zero errors.

**Transition contract:** `GenericGitResolver` is built standalone. `VersionAvailabilityAnalyzer` is NOT updated until Story 2.5. No entry in `config/services.yaml` for this story.

**Prerequisite:** Story 2.2 must be merged — `VcsResolutionResult` and `VcsResolutionStatus` are defined there.

**Kickoff finding F-E-04 (HIGH):** `GitVersionParser` incorrectly uses the main-branch `composer.json` compatibility as a proxy for all stable tags — wrong, because different tags have different `composer.json` constraints. `GenericGitResolver` MUST use the most recent stable TAG's `composer.json`, not the main branch. This story resolves F-E-04.

## Tasks / Subtasks

- [x] Task 0: Create `VcsResolverInterface` (AC: 10–11)
  - [x] Create `src/Infrastructure/ExternalTool/VcsResolverInterface.php`
  - [x] Add `implements VcsResolverInterface` to `GenericGitResolver`

- [x] Task 1: Implement `GenericGitResolver` (AC: 1–9)
  - [x] Create `src/Infrastructure/ExternalTool/GenericGitResolver.php`
  - [x] Implement `resolve(string $packageName, string $vcsUrl, Version $targetVersion): VcsResolutionResult`
  - [x] Implement private `runLsRemote(string $vcsUrl): ?string` — runs `git ls-remote -t --refs <url>`, returns stdout or null on failure/timeout; logs WARNING on failure
  - [x] Implement private `parseTagsFromOutput(string $output): list<string>` — extract semver version strings per AC2 regex
  - [x] Implement private `findMostRecentStableTag(list<string> $versions): ?string` — sort with `version_compare()`, skip pre-release suffixes, return newest stable or null
  - [x] Implement private `fetchComposerJson(string $vcsUrl, string $tag): ?array` — runs `git archive --remote=<url> refs/tags/<tag> -- composer.json | tar -xO` via `Process::fromShellCommandline()`; returns decoded array or null on failure; logs DEBUG on failure
  - [x] Implement private `isCompatible(?array $composerJsonRequire, Version $targetVersion): bool` — uses `findTypo3Requirements()` + `isConstraintCompatible()`; no TYPO3 req = returns `true`
  - [x] Apply `$timeoutSeconds` to `runLsRemote` subprocess

- [x] Task 2: Write unit tests (AC: 10)
  - [x] Create `tests/Unit/Infrastructure/ExternalTool/GenericGitResolverTest.php`
  - [x] Test: `RESOLVED_COMPATIBLE` — ls-remote returns tags, `git archive` succeeds, most recent stable tag is TYPO3-compatible
  - [x] Test: `RESOLVED_COMPATIBLE` — ls-remote returns tags, `git archive` fails (null), treated as compatible
  - [x] Test: `RESOLVED_COMPATIBLE` — ls-remote returns tags, `git archive` succeeds, `composer.json` has no TYPO3 requirement (treated as compatible)
  - [x] Test: `RESOLVED_NO_MATCH` — ls-remote returns tags, most recent stable tag is NOT compatible with target
  - [x] Test: `RESOLVED_NO_MATCH` — ls-remote succeeds but no valid semver tags after parsing
  - [x] Test: `FAILURE` — ls-remote exits non-zero
  - [x] Test: `FAILURE` — ls-remote times out (`ProcessTimedOutException`)
  - [x] Test: `FAILURE` — git binary not available
  - [x] Test: tag parsing covers all variants from AC2 (v-prefix, pre-release, `dev-*` skip, non-semver skip)

- [x] Task 3: PHPStan and code style verification (AC: 10, 11)
  - [x] Run `composer sca:php` — zero errors
  - [x] Run `composer lint:php` — zero violations
  - [x] Run `composer test` — all tests green

- Review Follow-ups (AI) — addressing code review 2026-03-29:
  - [x] [AI-Review] Fix #3 (HIGH): v-prefixed tags stripped to normalized version, then used verbatim in `git archive` ref — bug: `refs/tags/2.3.4` used instead of `refs/tags/v2.3.4`. Store both original tag name and normalized version string; pass original tag to `fetchComposerJson`.
  - [x] [AI-Review] Fix #4 (HIGH): `fetchComposerJson` uses non-injectable `createArchiveProcess` — unit tests rely on real git failing in CI rather than injecting stubs. Add `?\Closure $archiveProcessFactory` constructor param; update tests to inject archive process stubs.
  - [x] [AI-Review] Fix #1 (MED): `$packageName` silently ignored — include in WARNING log messages in `runLsRemote` and related warnings.
  - [x] [AI-Review] Fix #2 (MED): `git ls-remote` missing `-q` flag — `From <url>` line may appear in stdout on some git versions. Explicit suppression is better than accidentally working filters.
  - [x] [AI-Review] Fix #5 (LOW): Test comment at line 149 factually wrong — "findTypo3Requirements never called" but it IS called with `[]` when fetchComposerJson returns null.

## Dev Notes

### Actual Enum: `VcsResolutionStatus` (NOT `ResolutionStatus`)

The story 2.2 story file uses `ResolutionStatus` in its code examples. The actual implemented file is:

```php
// src/Infrastructure/ExternalTool/VcsResolutionStatus.php
enum VcsResolutionStatus: string
{
    case RESOLVED_COMPATIBLE = 'resolved_compatible';
    case RESOLVED_NO_MATCH   = 'resolved_no_match';
    case NOT_FOUND           = 'not_found';   // used only by PackagistVersionResolver
    case FAILURE             = 'failure';
}
```

`GenericGitResolver` uses only `RESOLVED_COMPATIBLE`, `RESOLVED_NO_MATCH`, `FAILURE`. Never `NOT_FOUND`.

### `isComposerJsonCompatible` vs `findTypo3Requirements` — Critical Difference

`ComposerConstraintChecker::isComposerJsonCompatible` returns `false` for both `null` input AND `composer.json` with no `typo3/cms-*` requirements. This does NOT match the "no TYPO3 constraint = compatible" design contract used in `PackagistVersionResolver`.

**Do NOT use** `isComposerJsonCompatible()` in `GenericGitResolver`. Use the same pattern as `PackagistVersionResolver::isCompatible()`:

```php
private function isCompatible(?array $composerJsonRequire, Version $targetVersion): bool
{
    $typo3Reqs = $this->constraintChecker->findTypo3Requirements($composerJsonRequire ?? []);
    if ([] === $typo3Reqs) {
        return true; // no declared TYPO3 dependency — treat as compatible
    }
    foreach ($typo3Reqs as $constraint) {
        if ($this->constraintChecker->isConstraintCompatible($constraint, $targetVersion)) {
            return true;
        }
    }
    return false;
}
```

### Commands

**ls-remote (array subprocess — use `$processFactory`):**
```
['git', 'ls-remote', '-t', '--refs', $vcsUrl]
```

**git archive with pipe (shell subprocess — use `Process::fromShellCommandline`):**
```php
// escapeshellarg() required for both url and tag
$cmd = sprintf(
    'git archive --remote=%s refs/tags/%s -- composer.json | tar -xO',
    escapeshellarg($vcsUrl),
    escapeshellarg($tag),
);
$process = Process::fromShellCommandline($cmd);
```

`Process::fromShellCommandline` accepts a `string`, not an array. This is incompatible with the `(\Closure(list<string>): Process)|null` factory type. Options:
1. **Recommended:** Use `$processFactory` only for `git ls-remote` (array command). For `git archive`, construct the `Process` directly in a separate private method `createArchiveProcess(string $cmd): Process` that is always inline (not injected). This means `fetchComposerJson` is harder to unit-test in isolation, but you can test the overall `resolve()` method by controlling the `ls-remote` subprocess to return tags, and having the real `git archive` fail gracefully in environments where it's not available.
2. **Alternative:** Add a second `?\Closure $archiveProcessFactory = null` constructor parameter for `fetchComposerJson` tests. This is more testable but adds constructor complexity.

Choose option 1 for simplicity — the fallback path (git archive failure → compatible) is tested by providing a URL where git archive is known to fail.

### Process Factory Usage (for `runLsRemote` testing)

Same `\Closure` pattern as `PackagistVersionResolver`:

```php
$processFactory = function (array $command): Process {
    $stub = $this->createStub(Process::class);
    $stub->method('isSuccessful')->willReturn(true);
    $stub->method('getOutput')->willReturn(
        "abc123\trefs/tags/1.2.3\ndef456\trefs/tags/1.1.0\n"
    );
    return $stub;
};

$resolver = new GenericGitResolver($this->logger, $this->constraintChecker, 30, $processFactory);
```

Use `createStub()` (not `createMock()`) for process objects without strict call expectations — avoids PHPUnit 12 notices (learned from story 2.2 debug log).

### Tag Parsing — Representative Output Samples

From VcsResolutionSpike.md §4.2 real-world data:
```
# Pure semver (no v prefix) — most common in TYPO3:
6b604382b7a27e7000c507bbf4cb98a335291cef    refs/tags/10.0.0
d175a273ce2be454524f218d5685e39d8d92bdcb    refs/tags/10.0.1
abc123ef    refs/tags/3.0.1
def456ab    refs/tags/dev-develop   ← skip (not semver)
```

The private `parseTagsFromOutput` must:
1. Split on newline
2. Find lines containing `refs/tags/`
3. Extract the substring after the last `/`
4. Apply the regex `/^v?(\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?)$/`
5. Capture group 1 (strips `v` prefix if present)
6. Return as `list<string>`

### Finding Most Recent Stable Tag

```php
private function findMostRecentStableTag(array $versions): ?string
{
    // Stable = no hyphen (no pre-release suffix)
    $stable = array_values(array_filter($versions, fn($v) => !str_contains($v, '-')));
    if ([] === $stable) {
        return null;
    }
    usort($stable, static fn($a, $b): int => version_compare($b, $a)); // newest first
    return $stable[0];
}
```

### `fetchComposerJson` — Parsing Tar Stream

`git archive ... | tar -xO` writes the file contents to stdout (tar `-O` flag = extract to stdout). The output is the raw `composer.json` content, not a tar header. So `$process->getOutput()` gives the JSON string directly.

```php
private function fetchComposerJson(string $vcsUrl, string $tag): ?array
{
    $cmd = sprintf(
        'git archive --remote=%s refs/tags/%s -- composer.json | tar -xO',
        escapeshellarg($vcsUrl),
        escapeshellarg($tag),
    );
    $process = Process::fromShellCommandline($cmd);
    $process->setTimeout($this->timeoutSeconds);
    $process->run();

    if (!$process->isSuccessful() || '' === trim($process->getOutput())) {
        $this->logger->debug(
            'git archive unavailable for {url} tag {tag} — treating as compatible',
            ['url' => $vcsUrl, 'tag' => $tag],
        );
        return null;
    }

    try {
        /** @var array{require?: array<string, string>} $data */
        $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        return $data;
    } catch (\JsonException $e) {
        $this->logger->warning(
            'Malformed composer.json from git archive for {url} tag {tag}: {msg}',
            ['url' => $vcsUrl, 'tag' => $tag, 'msg' => $e->getMessage()],
        );
        return null;
    }
}
```

### Authentication — Nothing to Configure

SSH agent auth for `git@host:` URLs is automatic when git is invoked as a subprocess inheriting the environment. HTTPS auth uses git credential helpers (`~/.gitconfig`). The resolver does not configure auth — it delegates to the host's git environment, as validated in VcsResolutionSpike.md §4.3.

### PHPStan Notes

- `Process::fromShellCommandline` accepts `string $command` — PHPStan knows this signature. No annotation needed.
- `usort` return value is `bool` — do not `return usort(...)`.
- `array_values(array_filter(...))` returns `list<T>` — PHPStan may infer `array<int, T>`. Add `/** @var list<string> $stable */` if needed.
- `json_decode(..., true)` returns `mixed`. Add a `@var` annotation with the expected shape.

### File Structure

```
src/Infrastructure/ExternalTool/
    GenericGitResolver.php    (new)

tests/Unit/Infrastructure/ExternalTool/
    GenericGitResolverTest.php    (new)
```

No changes to `config/services.yaml`, `VersionAvailabilityAnalyzer`, `GitHubClient`, `GitRepositoryAnalyzer`, or any existing class. The existing `GitVersionParser.php` is NOT extended — it will be removed in Story 2.6.

### References

- `documentation/implementation/development/feature/VcsResolutionSpike.md` — §4 (ls-remote commands), §4.2 (tag parsing), §4.3 (auth), §9 (go/no-go + Option B), §10 (constraints table), §11 (GitVersionParser verdict)
- `_bmad-output/planning-artifacts/architecture.md` — Tier 2 GenericGitResolver section (AD3)
- `_bmad-output/planning-artifacts/epics.md` — Epic 2, Story 2.3
- `src/Infrastructure/ExternalTool/PackagistVersionResolver.php` — subprocess factory pattern, `isCompatible()` logic to replicate
- `src/Infrastructure/ExternalTool/VcsResolutionStatus.php` — actual enum (NOT `ResolutionStatus`)
- `src/Infrastructure/ExternalTool/VcsResolutionResult.php` — result VO
- `src/Infrastructure/Version/ComposerConstraintCheckerInterface.php` — `findTypo3Requirements()` + `isConstraintCompatible()` (use these, NOT `isComposerJsonCompatible()`)
- `src/Infrastructure/Version/ComposerConstraintChecker.php:58` — `isComposerJsonCompatible()` returns `false` for null/no-typo3-req (do NOT use in GenericGitResolver)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Used `createStub()` for process mocks (avoids PHPUnit 12 strict notices — same lesson as story 2.2)
- `fetchComposerJson` uses `Process::fromShellCommandline()` via a separate non-injected `createArchiveProcess()` method; `$processFactory` is used only for `runLsRemote` (array-command subprocess). This follows Dev Notes option 1.
- `ProcessFailedException` catch in `runLsRemote` covers "git binary not available" — throws before producing output when the binary is missing.

### Completion Notes List

- Implemented `GenericGitResolver` in `src/Infrastructure/ExternalTool/GenericGitResolver.php` with all required private methods and correct AC2 tag-parsing regex.
- Used `findTypo3Requirements()` + `isConstraintCompatible()` pattern (NOT `isComposerJsonCompatible()`) per Dev Notes.
- 11 unit tests pass covering all specified variants: RESOLVED_COMPATIBLE (3 cases), RESOLVED_NO_MATCH (3 cases), FAILURE (3 cases), tag-parsing variants, pre-release-only output.
- PHPStan Level 8: zero errors. CS-Fixer: clean after one auto-fix (`sprintf` → `\sprintf` preference). Full test suite: 1752 tests, no failures.

**Review follow-up (2026-03-29):**
- Fixed #3 (HIGH): `parseTagsFromOutput` now returns `list<array{version: string, tag: string}>`. Original tag name (e.g. `v2.3.4`) is passed to `fetchComposerJson`; normalized version (e.g. `2.3.4`) is stored in the result. `testVPrefixedTagUsesOriginalTagNameForGitArchive` verifies correct archive ref construction.
- Fixed #4 (HIGH): Added `?\Closure $archiveProcessFactory` constructor param. All test scenarios now inject deterministic process stubs — no reliance on real git environment in unit tests.
- Fixed #1 (MED): `$packageName` now included in all WARNING/DEBUG log messages in `runLsRemote`.
- Fixed #2 (MED): Added `-q` to `git ls-remote` command to suppress `From <url>` informational output.
- Fixed #5 (LOW): Corrected misleading test comment — `findTypo3Requirements` IS called with `[]` when archive returns null.
- Deferred #6 (no interface), #7 (RESOLVED_NO_MATCH conflation), #8 (shouldTryFallback design), #9 (hierarchical tags), #10 (malformed JSON = compatible): spec decisions or Story 2.5 scope.
- Finding #11 (readonly class): reviewer assumed PHP 8.1 minimum; project actually requires PHP ^8.3. Finding premise is wrong; making class readonly is valid but out of scope for this story.
- 12 tests pass (added `testVPrefixedTagUsesOriginalTagNameForGitArchive`). PHPStan Level 8: zero errors. Full test suite: 1753 tests, no failures.

### File List

- `src/Infrastructure/ExternalTool/VcsResolverInterface.php` (new)
- `src/Infrastructure/ExternalTool/GenericGitResolver.php` (new, implements VcsResolverInterface)
- `tests/Unit/Infrastructure/ExternalTool/GenericGitResolverTest.php` (new)

## Change Log

- 2026-03-28: Story created (bmad-create-story)
- 2026-03-28: Implementation complete — GenericGitResolver and unit tests added; PHPStan/CS/tests all green (claude-sonnet-4-6)
- 2026-03-29: Addressed code review findings — 5 items resolved: #3 v-prefix bug fix, #4 archive testability, #1 dead $packageName, #2 -q flag, #5 wrong comment (claude-sonnet-4-6)
- 2026-03-29: Added VcsResolverInterface (sprint change proposal, Task 0) — GenericGitResolver now implements VcsResolverInterface; PHPStan Level 8 clean, 1753 tests pass (claude-sonnet-4-6)
