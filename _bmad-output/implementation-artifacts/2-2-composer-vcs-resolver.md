# Story 2.2: Composer VCS Resolver (Tier 1)

Status: done

## Story

As a developer,
I want version availability for VCS-hosted extensions resolved via Composer CLI,
so that version availability is checked for all VCS providers without per-host API clients.

## Acceptance Criteria

1. **Given** a `string $packageName` and a `Version $targetVersion`, **when** `PackagistVersionResolver::resolve(string $packageName, string $vcsUrl, Version $targetVersion): VcsResolutionResult` is called for a Packagist-indexed package, **then** it executes `composer show --all --format=json vendor/package` (no `--working-dir`) and parses the output.
2. **Two-call strategy:** the first call (no version argument) returns the full version list + `requires` for the latest version only. If the latest version is compatible with `$targetVersion`, return immediately. If NOT compatible, perform a binary search on the version list by issuing versioned calls (`composer show --all --format=json vendor/package X.Y.Z`) until the newest compatible version is found or the list is exhausted. **Precondition:** binary search assumes `versions[]` is ordered newest-to-oldest as returned by Composer. Defend against mis-ordering: if a binary search step finds a newer compatible version in the "older" half, fall back to a linear scan from newest to oldest.
3. **Returns `VcsResolutionResult` unconditionally** — the return type is non-nullable. The status field encodes the outcome:
   - `ResolutionStatus::RESOLVED_COMPATIBLE` — a compatible version was found; `latestCompatibleVersion` is set.
   - `ResolutionStatus::RESOLVED_NO_MATCH` — package found on Packagist but no version satisfies `$targetVersion`; `latestCompatibleVersion` is `null`.
   - `ResolutionStatus::NOT_ON_PACKAGIST` — Composer exited non-zero with a "not found" message in stderr; `latestCompatibleVersion` is `null`. Caller **must** hand off to Tier 2.
   - `ResolutionStatus::FAILURE` — network error, auth failure, timeout, or malformed JSON output; `latestCompatibleVersion` is `null`. Caller **must** hand off to Tier 2.
4. Distinguish `NOT_ON_PACKAGIST` from `FAILURE` by inspecting stderr: if stderr contains `"not found"` or `"Could not find package"` (case-insensitive), use `NOT_ON_PACKAGIST`. All other non-zero exits use `FAILURE`. Log a WARNING for `FAILURE`; log DEBUG for `NOT_ON_PACKAGIST`.
5. **`--working-dir` is never used** in any subprocess call. Hard constraint from spike findings (VcsResolutionSpike.md §8: adds 11–13 s overhead per call).
6. **Configurable timeout** via constructor parameter (default: 30 s). A `ProcessTimedOutException` maps to `ResolutionStatus::FAILURE`.
7. **Minimum Composer version check:** on first use, run `composer --version` and parse the version string. If the version is below 2.1, return `VcsResolutionResult` with `ResolutionStatus::FAILURE` and log a WARNING with message `"Composer 2.1+ required for stable JSON output; found {version}"`. Skip this check on subsequent calls (cache result).
8. **Malformed JSON handling:** if the subprocess exits 0 but `json_decode()` throws `JsonException`, return `VcsResolutionResult` with `ResolutionStatus::FAILURE` and log a WARNING including the package name and the exception message.
9. `PackagistVersionResolver` lives in `src/Infrastructure/ExternalTool/PackagistVersionResolver.php`. **Pre-condition documented in a docblock:** this class is intended for packages that appear in a `DeclaredRepository` (i.e. VCS-sourced extensions). It may be called for any package name — a `NOT_ON_PACKAGIST` result is the expected outcome for extensions that are not Packagist-indexed.
10. `VcsResolutionResult` is a `final readonly` value object in `src/Infrastructure/ExternalTool/VcsResolutionResult.php`. `ResolutionStatus` is a backed enum in `src/Infrastructure/ExternalTool/ResolutionStatus.php`. See Dev Notes for exact shapes.
11. Unit tests cover all `ResolutionStatus` variants and subprocess edge cases. See Dev Notes for the required test double approach.
12. PHPStan Level 8 reports zero errors.

**Transition contract:** During development, the existing `GitRepositoryAnalyzer` + `GitHubClient` remain functional and wired. `VersionAvailabilityAnalyzer` is NOT switched to the new resolver until Story 2.5. Build `PackagistVersionResolver` standalone; no integration wiring in `config/services.yaml` for this story.

**Dependency note for Story 2.3:** `GenericGitResolver` (Story 2.3, parallel backlog) returns the same `VcsResolutionResult` type. Story 2.3 has a compile-time dependency on `VcsResolutionResult` and `ResolutionStatus` defined here. **Story 2.2 must be merged before Story 2.3 can be completed.**

## Tasks / Subtasks

- [x] Task 1: Define `ResolutionStatus` enum and `VcsResolutionResult` VO (AC: 3, 10)
  - [x] Create `src/Infrastructure/ExternalTool/ResolutionStatus.php` — backed string enum with four cases (see Dev Notes)
  - [x] Create `src/Infrastructure/ExternalTool/VcsResolutionResult.php` — `final readonly` class (see Dev Notes)
  - [x] Write `tests/Unit/Infrastructure/ExternalTool/VcsResolutionResultTest.php` and `ResolutionStatusTest.php`
  - [x] Add `shouldTryFallback(): bool` helper to `VcsResolutionResult` that returns `true` for `NOT_ON_PACKAGIST` and `FAILURE`

- [x] Task 2: Implement `PackagistVersionResolver` (AC: 1–9)
  - [x] Create `src/Infrastructure/ExternalTool/PackagistVersionResolver.php`
  - [x] Constructor: `LoggerInterface $logger`, `ComposerConstraintCheckerInterface $constraintChecker`, `int $timeoutSeconds = 30`, `?\Closure $processFactory = null` (see Dev Notes for type annotation)
  - [x] Implement `resolve(string $packageName, string $vcsUrl, Version $targetVersion): VcsResolutionResult`
  - [x] Implement Composer version check (AC: 7) with cached result
  - [x] Build subprocess via factory or direct `new Process(['composer', 'show', '--all', '--format=json', $packageName])`
  - [x] Parse stdout with `json_decode(..., JSON_THROW_ON_ERROR)`; catch `JsonException` → `FAILURE`
  - [x] Distinguish `NOT_ON_PACKAGIST` vs `FAILURE` from exit code + stderr content (AC: 4)
  - [x] Check latest version compatibility via `ComposerConstraintCheckerInterface`
  - [x] Binary search with fallback to linear scan (AC: 2)
  - [x] Apply timeout (AC: 6); catch `ProcessTimedOutException` → `FAILURE`

- [x] Task 3: Write unit tests (AC: 11)
  - [x] Test: `RESOLVED_COMPATIBLE` — latest version is compatible (single call)
  - [x] Test: `RESOLVED_COMPATIBLE` — latest not compatible, binary search finds older compatible version
  - [x] Test: `RESOLVED_NO_MATCH` — no compatible version in full list
  - [x] Test: `NOT_ON_PACKAGIST` — process exits non-zero, stderr contains "not found"
  - [x] Test: `FAILURE` — process exits non-zero, stderr does not match "not found" pattern
  - [x] Test: `FAILURE` — process timeout
  - [x] Test: `FAILURE` — process exits 0 but stdout is invalid JSON
  - [x] Test: `FAILURE` — Composer version below 2.1
  - [x] Test: `shouldTryFallback()` returns `true` for `NOT_ON_PACKAGIST` and `FAILURE`, `false` otherwise

- [x] Task 4: PHPStan and code style verification (AC: 12)
  - [x] Run `composer sca:php` — zero errors
  - [x] Run `composer lint:php` — zero violations
  - [x] Run `composer test` — all tests green

## Dev Notes

### Value Object and Enum Shapes

```php
// src/Infrastructure/ExternalTool/ResolutionStatus.php
enum ResolutionStatus: string
{
    case RESOLVED_COMPATIBLE  = 'resolved_compatible';   // compatible version found
    case RESOLVED_NO_MATCH    = 'resolved_no_match';     // on Packagist, none compatible
    case NOT_ON_PACKAGIST     = 'not_on_packagist';      // not found → caller tries Tier 2
    case FAILURE              = 'failure';               // network/auth/timeout → caller tries Tier 2
}

// src/Infrastructure/ExternalTool/VcsResolutionResult.php
final readonly class VcsResolutionResult
{
    public function __construct(
        public ResolutionStatus $status,
        public string $sourceUrl,
        public ?string $latestCompatibleVersion,  // non-null only when status = RESOLVED_COMPATIBLE
    ) {}

    public function shouldTryFallback(): bool
    {
        return $this->status === ResolutionStatus::NOT_ON_PACKAGIST
            || $this->status === ResolutionStatus::FAILURE;
    }
}
```

Story 2.3 (`GenericGitResolver`) returns the same `VcsResolutionResult` with `RESOLVED_COMPATIBLE`, `RESOLVED_NO_MATCH`, or `FAILURE` (no `NOT_ON_PACKAGIST` — Tier 2 is the last resort).

### Critical: No `--working-dir` under any circumstances

`--working-dir` adds 11–13 s per subprocess call (VcsResolutionSpike.md §8). `PackagistVersionResolver` is Packagist-only — no target installation config is needed. Any code review that finds `--working-dir` in this class is a blocker.

VCS-only packages are not on Packagist. When `composer show --all` exits non-zero with "not found" in stderr, return `NOT_ON_PACKAGIST` immediately. Do not attempt `--working-dir` as a fallback.

### Subprocess: `versions[]` Ordering and Binary Search

`composer show --all --format=json vendor/package` returns `versions[]` newest-to-oldest (observed in spike tests). Binary search over this list reduces worst-case from O(N) to O(log N) — relevant for extensions with many releases (ext-solr: 139 versions).

```
versions[] index:  0          N/2          N-1
                newest   →   mid    →   oldest
```

Guard against mis-ordering: after each binary search step, if the result in the supposedly "older" half is numerically newer than the current best, abort binary search and switch to a linear scan from index 0. Use `version_compare()` to compare version strings.

Each versioned call: `composer show --all --format=json vendor/package X.Y.Z` → returns `requires{}` for that single version. ~315–400 ms per call.

### TYPO3 Compatibility Check

Use `ComposerConstraintCheckerInterface`. Both `resolve()` and the binary search steps call the same method:

```php
// findTypo3Requirements() returns array<string, string>: ['typo3/cms-core' => '^13.4', ...]
$typo3Reqs = $this->constraintChecker->findTypo3Requirements($requires);
if (empty($typo3Reqs)) {
    // no typo3/cms-* requirements — treat as compatible (extension has no declared TYPO3 dep)
    // conservative: you may prefer RESOLVED_NO_MATCH here; document the choice
}
foreach ($typo3Reqs as $constraint) {
    if ($this->constraintChecker->isConstraintCompatible($constraint, $targetVersion)) {
        return new VcsResolutionResult(ResolutionStatus::RESOLVED_COMPATIBLE, $vcsUrl, $version);
    }
}
```

`ComposerConstraintChecker::isConstraintCompatible()` catches parse failures internally and falls back to a major-version string match. If a constraint from Composer output fails to parse AND the fallback string match also fails, the method returns `false` — treat as non-compatible. Do not add extra wildcard handling in this class.

`ComposerConstraintCheckerInterface` lives in `src/Infrastructure/Version/`. It is auto-wired — no manual entry in `config/services.yaml` needed.

### Process Testing: `\Closure` Factory Pattern

`RectorExecutorTest` tests failure modes by passing a non-existent binary path. That approach works for "binary missing" but cannot simulate varied stdout/exit-code combinations. For `PackagistVersionResolver`, accept an optional `\Closure` process factory:

```php
/**
 * @param (\Closure(list<string>): \Symfony\Component\Process\Process)|null $processFactory
 */
public function __construct(
    private readonly LoggerInterface $logger,
    private readonly ComposerConstraintCheckerInterface $constraintChecker,
    private readonly int $timeoutSeconds = 30,
    private readonly ?\Closure $processFactory = null,
) {}

private function createProcess(array $command): Process
{
    return $this->processFactory !== null
        ? ($this->processFactory)($command)
        : new Process($command);
}
```

In tests, pass a factory that returns a `Process` mock (or a real `Process` constructed with a fake command that exits as needed). The PHPStan annotation `(\Closure(list<string>): Process)|null` keeps Level 8 clean.

### JSON Output Shape (Reference)

`composer show --all --format=json vendor/package`:
```json
{
  "name": "vendor/package",
  "versions": ["1.2.3", "1.2.2", "1.1.0"],
  "requires": { "typo3/cms-core": "^13.4 || ^14.0", "php": "^8.1" },
  "source": { "url": "https://github.com/vendor/package" }
}
```
`versions[]` newest-to-oldest; `requires` is for the latest version only.

`composer show --all --format=json vendor/package 1.1.0` (versioned):
```json
{
  "name": "vendor/package",
  "versions": ["1.1.0"],
  "requires": { "typo3/cms-core": "^12.4" }
}
```

### Composer Version Check Implementation

```php
private ?bool $composerVersionOk = null;

private function checkComposerVersion(): bool
{
    if ($this->composerVersionOk !== null) {
        return $this->composerVersionOk;
    }
    $process = $this->createProcess(['composer', '--version']);
    $process->run();
    if (!$process->isSuccessful()) {
        return $this->composerVersionOk = false;
    }
    // output: "Composer version 2.8.9 2024-..."
    if (preg_match('/version (\d+\.\d+)/', $process->getOutput(), $m)) {
        if (version_compare($m[1], '2.1', '<')) {
            $this->logger->warning(
                sprintf('Composer 2.1+ required for stable JSON output; found %s', $m[1]),
            );
            return $this->composerVersionOk = false;
        }
    }
    return $this->composerVersionOk = true;
}
```

### `DeclaredRepository` Actual Location

The sprint change proposal planned `DeclaredRepository` at `src/Infrastructure/ExternalTool/`. Story 2.1 placed it at `src/Infrastructure/Discovery/DTO/DeclaredRepository.php` (namespace `CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DTO`). `PackagistVersionResolver` does not import `DeclaredRepository` — it receives `string $packageName` and `string $vcsUrl` extracted by the orchestrator in Story 2.5.

### Project Structure Notes

```
src/Infrastructure/ExternalTool/
    ResolutionStatus.php          (new — enum)
    VcsResolutionResult.php       (new — final readonly VO)
    PackagistVersionResolver.php  (new)

tests/Unit/Infrastructure/ExternalTool/
    ResolutionStatusTest.php           (new)
    VcsResolutionResultTest.php        (new)
    PackagistVersionResolverTest.php   (new)
```

No changes to `config/services.yaml`, `VersionAvailabilityAnalyzer`, `GitHubClient`, `GitRepositoryAnalyzer`, or any existing class.

### Design Decision: Linear Scan over Binary Search (2026-03-28)

During code review, binary search was identified as correct only under an unstated precondition:
compatible versions must form a **contiguous block at the older end** of the `versions[]` list
(monotone-compatibility assumption). For TYPO3 extensions this is a plausible heuristic, but it
is not guaranteed — an extension may have released a hotfix for an older TYPO3 on top of a newer,
incompatible release line, breaking monotonicity.

**Decision:** Use `linearScan()` (O(N) subprocess calls, newest-to-oldest) as the active strategy.
Binary search was removed from production code and is preserved only in git history on the
`feature/2-2-composer-vcs-resolver` branch.

**Conditions for re-introducing binary search:**
- Real-world data shows O(N) call counts are a measurable performance problem (e.g., packages with
  50+ versions causing noticeable latency in the analysis run).
- The monotone-compatibility assumption is validated (or the guard logic for non-monotone
  distributions is made correct).
- Binary search correctness must be re-verified: the original implementation had a dead
  `null !== $bestCompatible` guard and compared `$versions[$mid]` against `$versions[$low-1]`
  (window boundary) rather than the previously accumulated best, which could cause spurious
  fallback to linear scan on correctly ordered lists.

### References

- [Source: `documentation/implementation/development/feature/VcsResolutionSpike.md` — §8 (recommended command), §9 (go/no-go Tier 2), §10 (constraints table)]
- [Source: `_bmad-output/planning-artifacts/architecture.md` — Git Source Detection & Version Availability (AD3)]
- [Source: `_bmad-output/planning-artifacts/epics.md` — Epic 2, Story 2.2]
- [Source: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-03-26.md` — §4C, Story 2.2]
- [Source: `src/Infrastructure/Version/ComposerConstraintChecker.php` — `findTypo3Requirements()` returns `array<string, string>`]
- [Source: `src/Infrastructure/Analyzer/Rector/RectorExecutor.php` — Symfony Process usage reference]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6 (2026-03-28)

### Debug Log References

- PHPUnit 12 notices: used `createStub()` instead of `createMock()` for process and checker objects without strict expectations — eliminates all notices.
- `composerShowJson()` helper typed `array<string, string>` for requires — callers must use string-keyed arrays like `['typo3/cms-core' => '^13.4']`.
- Binary search sanity-check: guard `null !== $bestCompatible` before `version_compare` was already always true at that point; CS fixer normalised yoda-style comparisons throughout.

### Completion Notes List

- Implemented `ResolutionStatus` backed string enum (4 cases) and `VcsResolutionResult` final readonly VO with `shouldTryFallback()`.
- Implemented `PackagistVersionResolver::resolve()` with: Composer version check (cached), fast-path latest-version check, binary search with linear fallback, `NOT_ON_PACKAGIST`/`FAILURE` stderr discrimination, timeout handling, and malformed JSON handling.
- No `--working-dir` used anywhere per AC 5 hard constraint.
- No wiring in `config/services.yaml` per transition contract.
- PHPStan Level 8: zero errors. CS-Fixer: zero violations. Unit tests: 1642 pass, exit 0.

### File List

- src/Infrastructure/ExternalTool/ResolutionStatus.php (new)
- src/Infrastructure/ExternalTool/VcsResolutionResult.php (new)
- src/Infrastructure/ExternalTool/PackagistVersionResolver.php (new)
- tests/Unit/Infrastructure/ExternalTool/ResolutionStatusTest.php (new)
- tests/Unit/Infrastructure/ExternalTool/VcsResolutionResultTest.php (new)
- tests/Unit/Infrastructure/ExternalTool/PackagistVersionResolverTest.php (new)

## Change Log

- 2026-03-28: Story implemented — ResolutionStatus enum, VcsResolutionResult VO, PackagistVersionResolver with binary search strategy, and full unit test coverage (claude-sonnet-4-6)
