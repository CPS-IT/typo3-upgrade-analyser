# Story 2.5: VCS Resolution Integration, Data Model Migration, and Template Updates

Status: ready-for-dev

## Story

As a developer,
I want `GitSource` renamed to `VcsSource` with `VcsResolverInterface` integration, metric renaming from `git_*` to `vcs_*`, console warnings on resolution failure, and updated templates,
so that VCS availability is resolved through Composer-based resolution, unresolvable sources produce visible warnings, and reports reflect the provider-agnostic data model.

## Acceptance Criteria

### AC 1-4: Class Refactoring & Dependency Injection

1. `src/Infrastructure/Analyzer/VersionAvailability/Source/GitSource.php` renamed to `VcsSource.php`; class renamed `GitSource` -> `VcsSource`
2. `VcsSource` still implements `VersionSourceInterface`
3. `getName()` returns `'vcs'` (not `'git'`)
4. `VcsSource` injects `VcsResolverInterface $resolver` via constructor (single instance, not iterable); removes `GitRepositoryAnalyzer` dependency

### AC 5-6: Service Configuration

5. `services.yaml`: wire `ComposerVersionResolver` as the concrete `VcsResolverInterface` implementation (alias pattern)
6. `services.yaml`: `VcsSource` replaces `GitSource` with the `version_availability.source` tag; `GitRepositoryAnalyzer`, `GitHubClient`, `GitProviderFactory` service definitions remain (NOT deleted -- that is Story 2-6) but are unwired from `VcsSource`

### AC 7-9: Metric Renaming & Null vs False Distinction

7. Metrics returned by `VcsSource::checkAvailability()`:
   - `git_available` -> `vcs_available`
   - `git_repository_url` -> `vcs_source_url`
   - `git_latest_version` -> `vcs_latest_version`
8. `git_repository_health` metric removed entirely (no replacement)
9. Failure semantics: `vcs_available` = `null` when resolution failed (unknown), `false` when resolved but no compatible version, `true` when compatible version found

### AC 10-14: Console Warnings (absorbed from Story 2-4)

10. When resolution fails (`FAILURE` or `NOT_FOUND` status), a Console WARNING is emitted:
    ```
    [WARNING] VCS source "{url}" could not be resolved. Ensure Composer authentication is configured for this URL.
    ```
11. WARNING appears regardless of verbosity level
12. In non-interactive / CI mode the warning is written to stderr
13. One warning per source URL (multiple extensions sharing same failing URL = one warning)
14. `VcsSource` receives `OutputInterface` (or `SymfonyStyle`) -- investigate how to inject this in Infrastructure layer (may require `OutputAwareInterface` or setter injection from command layer)

### AC 15-17: Risk Scoring & Recommendation Updates

15. `VersionAvailabilityAnalyzer::calculateRiskScore()`: reads `vcs_available` instead of `git_available`; removes `git_repository_health` weighted scoring; uses binary VCS availability (weight 2 for `true`, 0 for `false`, skip for `null`)
16. `VersionAvailabilityAnalyzer::addRecommendations()`: replaces all `git_*` metric reads with `vcs_*`; removes health-based recommendation logic; updates recommendation text from "Git" to "VCS"
17. `VersionAvailabilityAnalyzer`: source name mapping `'github' -> 'git'` updated to `'github' -> 'vcs'`; default source list becomes `['ter', 'packagist', 'vcs']`; cache key components updated similarly

### AC 18-20: Template & Output Format Changes

18. All 6 template files updated: `git_available` -> `vcs_available`, `git_repository_url` -> `vcs_source_url`, `git_latest_version` -> `vcs_latest_version`, `git_repository_health` references removed
19. Column/card headers: "Git" -> "VCS", "Git Repository" -> "VCS Repository"; source checks `'git' in enabled_sources` -> `'vcs' in enabled_sources` (keep `'github'` mapping for backwards compat)
20. Default `enabled_sources` in templates: `['ter', 'packagist', 'git']` -> `['ter', 'packagist', 'vcs']`

### AC 21: VersionAvailabilityDataProvider Update

21. `VersionAvailabilityDataProvider::extractData()`: replaces `git_available`, `git_repository_url`, `git_repository_health`, `git_latest_version` with `vcs_available`, `vcs_source_url`, `vcs_latest_version`; removes `git_repository_health` key entirely

### AC 22-24: Test Coverage

22. All existing tests updated to new metric names (unit, integration, functional)
23. New `VcsSourceTest` unit tests covering: successful resolution (no warning), resolution failure (warning + `null` metric), multiple failures (one warning per URL), cache hit/miss
24. PHPStan Level 8 reports zero errors; `composer lint:php` passes

## Tasks / Subtasks

- [ ] Task 1: Rename GitSource to VcsSource (AC: 1-4)
  - [ ] 1.1 Rename file `GitSource.php` -> `VcsSource.php`
  - [ ] 1.2 Change class name, update `getName()` to return `'vcs'`
  - [ ] 1.3 Replace constructor: `VcsResolverInterface $resolver` instead of `GitRepositoryAnalyzer`
  - [ ] 1.4 Implement `checkAvailability()` delegating to `$resolver->resolve()`
  - [ ] 1.5 Map `VcsResolutionResult` to metrics array with null/false/true semantics
  - [ ] 1.6 Add console warning logic for failed resolutions
- [ ] Task 2: Update services.yaml (AC: 5-6)
  - [ ] 2.1 Add `VcsResolverInterface` alias pointing to `ComposerVersionResolver`
  - [ ] 2.2 Replace `GitSource` service definition with `VcsSource`
  - [ ] 2.3 Wire `VcsResolverInterface`, `LoggerInterface`, `CacheService` as VcsSource args
  - [ ] 2.4 Keep old Git provider service definitions intact (Story 2-6 scope)
- [ ] Task 3: Update VersionAvailabilityAnalyzer (AC: 15-17)
  - [ ] 3.1 Update `calculateRiskScore()`: `git_available` -> `vcs_available`, remove `git_repository_health` logic, handle `null` as skip
  - [ ] 3.2 Update `addRecommendations()`: all `git_*` -> `vcs_*`, remove health-based logic
  - [ ] 3.3 Update default source list and `'github'` mapping
  - [ ] 3.4 Update cache key components
- [ ] Task 4: Update VersionAvailabilityDataProvider (AC: 21)
  - [ ] 4.1 Replace `git_*` metric keys with `vcs_*` in `extractData()`
  - [ ] 4.2 Remove `git_repository_health` from output
- [ ] Task 5: Update templates (AC: 18-20)
  - [ ] 5.1 `version-availability-table.html.twig`: defaults, source checks, metric names, headers
  - [ ] 5.2 `version-availability-table.md.twig`: same changes
  - [ ] 5.3 `version-availability-analysis.html.twig` (extension detail): same changes
  - [ ] 5.4 `version-availability-analysis.md.twig` (extension detail): same changes
  - [ ] 5.5 Check `detailed-report.html.twig` and `main-report.md.twig` for any git_* references
- [ ] Task 6: Update all tests (AC: 22-24)
  - [ ] 6.1 Rename/rewrite `GitSourceTest` -> `VcsSourceTest`
  - [ ] 6.2 Update `VersionAvailabilityAnalyzerTest`: new metric names, null handling
  - [ ] 6.3 Update `ReportContextBuilderTest`: new metric names
  - [ ] 6.4 Update `VersionAvailabilityIntegrationTestCase`
  - [ ] 6.5 Update `MixedAnalysisIntegrationTestCase`
  - [ ] 6.6 Run full suite, PHPStan, lint

## Dev Notes

### VcsSource Implementation Pattern

`VcsSource::checkAvailability()` must:
1. Get the extension's VCS URL via `$extension->getRepositoryUrl()`
2. Get the composer name via `$extension->getComposerName()`
3. If no repository URL or no composer name, return default response with `null` metrics (not `false`)
4. Call `$this->resolver->resolve($composerName, $repositoryUrl, $context->getTargetVersion())`
5. Map `VcsResolutionResult` to metrics:
   - `RESOLVED_COMPATIBLE`: `vcs_available=true`, `vcs_latest_version=$result->latestCompatibleVersion`
   - `RESOLVED_NO_MATCH`: `vcs_available=false`, `vcs_latest_version=null`
   - `NOT_FOUND` / `FAILURE`: `vcs_available=null`, emit console warning
6. Cache successful results; do NOT cache failures (resolver may succeed on retry)

Default response:
```php
[
    'vcs_available' => null,
    'vcs_source_url' => null,
    'vcs_latest_version' => null,
]
```

### Console Warning Injection

Problem: `VcsSource` lives in Infrastructure layer; `OutputInterface` is a Symfony Console concern.

Options (investigate and pick simplest):
- **Option A**: Accept `OutputInterface` via setter from `AnalyzeCommand` after DI construction (like Symfony's `ContainerAwareCommand` pattern)
- **Option B**: Use `LoggerInterface` only; add a Monolog `ConsoleHandler` that maps WARNING -> stderr output
- **Option C**: Return warning metadata in metrics array; let `VersionAvailabilityAnalyzer` (Application-adjacent) emit console warnings

Option B or C likely cleanest for architecture purity (Infrastructure must not depend on Console). Evaluate existing patterns in codebase.

### Risk Scoring Changes

Current git scoring (lines 168-175 of `VersionAvailabilityAnalyzer.php`):
```php
if (\in_array('git', $enabledSources, true)) {
    $maxPossibleScore += 2;
    if ($gitAvailable) {
        $gitWeight = $gitHealth ? (2 * $gitHealth) : 1;
        $availabilityScore += $gitWeight;
    }
}
```

New VCS scoring:
```php
if (\in_array('vcs', $enabledSources, true)) {
    $maxPossibleScore += 2;
    $vcsAvailable = $metrics['vcs_available'] ?? null;
    if (true === $vcsAvailable) {
        $availabilityScore += 2;  // Binary: full weight or nothing
    } elseif (null === $vcsAvailable) {
        $maxPossibleScore -= 2;   // Unknown: exclude from scoring
    }
}
```

### Files to Modify (complete list)

**Rename:**
- `src/Infrastructure/Analyzer/VersionAvailability/Source/GitSource.php` -> `VcsSource.php`

**Modify (PHP):**
- `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php` -- metric names, scoring, recommendations, source mapping
- `src/Infrastructure/Reporting/Provider/VersionAvailabilityDataProvider.php` -- metric key extraction
- `config/services.yaml` -- VcsSource wiring, VcsResolverInterface alias

**Modify (Templates):**
- `resources/templates/html/partials/main-report/version-availability-table.html.twig`
- `resources/templates/md/partials/main-report/version-availability-table.md.twig`
- `resources/templates/html/partials/extension-detail/version-availability-analysis.html.twig`
- `resources/templates/md/partials/extension-detail/version-availability-analysis.md.twig`
- `resources/templates/html/detailed-report.html.twig` (check for git_* references)
- `resources/templates/md/main-report.md.twig` (check for git_* references)

**Modify (Tests):**
- `tests/Unit/Infrastructure/Analyzer/VersionAvailability/Source/GitSourceTest.php` -> `VcsSourceTest.php`
- `tests/Unit/Infrastructure/Analyzer/VersionAvailabilityAnalyzerTest.php`
- `tests/Unit/Infrastructure/Reporting/ReportContextBuilderTest.php`
- `tests/Integration/Analyzer/VersionAvailabilityIntegrationTestCase.php`
- `tests/Integration/MixedAnalysisIntegrationTestCase.php`

### Existing Classes to Reuse (do NOT reinvent)

- `VcsResolverInterface` at `src/Infrastructure/ExternalTool/VcsResolverInterface.php`
- `ComposerVersionResolver` at `src/Infrastructure/ExternalTool/ComposerVersionResolver.php` (the concrete `VcsResolverInterface` implementation)
- `VcsResolutionResult` at `src/Infrastructure/ExternalTool/VcsResolutionResult.php`
- `VcsResolutionStatus` at `src/Infrastructure/ExternalTool/VcsResolutionStatus.php`
- `CacheService` at `src/Infrastructure/Cache/CacheService.php`
- `VersionSourceInterface` at `src/Infrastructure/Analyzer/VersionAvailability/VersionSourceInterface.php`

### Testing Patterns

- PHPUnit 12.3 attributes only: `#[CoversClass]`, `#[Test]`, `#[DataProvider]`
- No `test` prefix on methods; method names describe expected behaviour
- `self::assertEquals()` (never `$this->`)
- Mock interfaces, not concrete classes: `$this->createMock(VcsResolverInterface::class)`
- Use `createStub()` for Process objects to avoid PHPUnit 12 notices
- Data providers: `public static function descriptiveNameProvider(): iterable`

### Previous Story Learnings

From Story 2-2 (ComposerVersionResolver):
- Process factory pattern `?\Closure $processFactory` enables unit testing without real CLI
- Linear scan strategy (newest-to-oldest) is correct; binary search was removed due to non-monotone distributions
- `--working-dir` is a performance blocker (11-13s overhead); never use it
- Compatibility check: use `findTypo3Requirements()` + `isConstraintCompatible()`, NOT `isComposerJsonCompatible()`

From Story 2-3 (GenericGitResolver -- cancelled):
- `VcsResolverInterface` was created here; it remains the contract
- `GenericGitResolver` was cancelled because `git archive --remote` is rejected by all major hosting providers
- File stays in codebase until Story 2-6 cleanup

### Project Structure Notes

- Domain layer (`src/Domain/`) has zero framework dependencies -- VcsSource is Infrastructure, so Symfony imports are allowed
- All analyzers use `AbstractCachedAnalyzer` pattern; VcsSource is a *source* (not an analyzer) and does its own caching via `CacheService` directly
- Service tags: `version_availability.source` for sources, `analyzer` for analyzers, `vcs_resolver` for resolvers (if using tagged iterator)
- Since there is currently only one `VcsResolverInterface` implementation, use alias pattern (not tagged iterator) for wiring

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Epic-2 Story 2.5]
- [Source: _bmad-output/planning-artifacts/architecture.md#VCS-Resolution]
- [Source: src/Infrastructure/Analyzer/VersionAvailability/Source/GitSource.php] -- current implementation to replace
- [Source: src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php:136-211] -- risk scoring and recommendations
- [Source: src/Infrastructure/Reporting/Provider/VersionAvailabilityDataProvider.php:68-84] -- metric extraction
- [Source: config/services.yaml:89-94] -- current GitSource wiring
- [Source: Sprint change proposal 2026-03-29c] -- GenericGitResolver cancellation, Story 2-4 absorption

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
