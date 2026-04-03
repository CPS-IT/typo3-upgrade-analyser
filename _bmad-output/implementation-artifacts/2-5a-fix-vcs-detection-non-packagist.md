# Story 2.5a: Fix VCS Detection for Non-Packagist Packages

Status: ready-for-dev

## Story

As a developer analyzing a TYPO3 installation,
I want VCS detection to work for packages sourced from private/non-Packagist repositories,
so that extensions installed via VCS entries in `composer.json` are correctly identified as available, including those on private GitLab/GitHub instances accessed via SSH.

## Acceptance Criteria

### AC-1: Bridge `source.url` to Extension entity (fixes RC-2)

1. `ExtensionDiscoveryService::createExtensionFromComposerData()` reads `$packageData['source']['url']` when present
2. Calls `$extension->setRepositoryUrl($sourceUrl)` to populate the Extension entity
3. Only set when `source.url` is a non-empty string; skip silently for path-type or missing sources
4. Existing behavior for `dist.type`/`dist.url` extraction unchanged

### AC-2: `--working-dir` fallback in ComposerVersionResolver (fixes RC-1)

5. `ComposerVersionResolver::resolve()` gains access to the installation path (via expanded method signature or injected context)
6. Primary resolution unchanged: `composer show --all --format=json $packageName`
7. When primary returns NOT_FOUND **and** an installation path is available: fallback to `composer show --working-dir=$installationPath --format=json $packageName`
8. Fallback only attempted when primary returns NOT_FOUND (zero overhead for Packagist packages)
9. Fallback result is processed through the same version scanning + compatibility check pipeline as primary results

### AC-3: Installation path in AnalysisContext

10. `AnalysisContext` gains an `installationPath` property (nullable string), set from the installation's filesystem path during analysis setup
11. `VcsSource` extracts `installationPath` from context and passes it to the resolver
12. `VcsResolverInterface::resolve()` signature extended: add `?string $installationPath = null` parameter (backwards-compatible default)

### AC-4: SSH authentication graceful degradation

13. For SSH-based VCS URLs (`git@host:...`), when `--working-dir` fallback fails due to SSH auth, resolver returns NOT_FOUND with a descriptive error message
14. `VcsSource` emits WARNING: `VCS source "{url}" for package "{package}" could not be resolved. SSH authentication may not be configured for this host.`
15. Extension reported as `vcs_available: SourceAvailability::Unknown`, not `SourceAvailability::Unavailable`
16. Analyzer continues with remaining extensions (no hard failure)

### AC-5: Optional SSH connectivity pre-check

17. Before running `--working-dir` for the first SSH-based package on a given host, attempt `ssh -T -o ConnectTimeout=5 <host>` to probe connectivity
18. Cache result per host for duration of analysis run
19. If host unreachable, skip `--working-dir` for all packages on that host (avoids repeated 11-13s timeouts)
20. Emit single WARNING per unreachable host instead of per-package warnings

### AC-6: HTTPS packages bypass SSH checks

21. HTTPS-based VCS packages (`https://github.com/...`) resolve via `--working-dir` without any SSH dependency or SSH connectivity check
22. Only `git@host:...` and `ssh://` URLs trigger the SSH pre-check

### AC-7: Performance guard

23. `--working-dir` fallback only attempted when primary resolution returns NOT_FOUND (zero overhead for Packagist packages)
24. Unit test confirms: when primary resolves successfully, no fallback is attempted

### AC-8: SourceAvailability enum replaces null/true/false for VCS metrics

25. New `SourceAvailability` string-backed enum in `src/Domain/ValueObject/SourceAvailability.php` with cases: `Available` (`'available'`), `Unavailable` (`'unavailable'`), `Unknown` (`'unknown'`)
26. `VcsSource::checkAvailability()` returns `SourceAvailability` enum values for the `vcs_available` metric instead of `null`/`true`/`false`
27. `VersionAvailabilityAnalyzer::calculateRiskScore()`: all 3 VCS strict identity checks (`true === $vcsAvailable`, `null === $vcsAvailable`) replaced with enum case matches
28. `VersionAvailabilityAnalyzer::addRecommendations()`: all 6 VCS strict identity checks (`true === $vcsAvailable`, `true !== $vcsAvailable`) replaced with enum case matches
29. `ReportContextBuilder::aggregateAvailabilityStats()`: 2 VCS strict checks replaced with enum case matches
30. `VersionAvailabilityDataProvider::extractData()`: passes `SourceAvailability->value` (string) to template context for `vcs_available`
31. 4 Twig templates: `is same as(true)` / `is same as(false)` replaced with string comparisons against `'available'` / `'unavailable'` / `'unknown'`
32. TER and Packagist sources remain boolean — no changes to their truthiness checks or templates
33. The enum is extensible: future cases like `AuthRequired`, `Timeout` can be added without changing consumers

### AC-9: RC-3 resolution verification

34. After AC-1 + AC-2 are in place, `VcsSource` passes a non-null `repositoryUrl` and `installationPath` to the resolver, enabling the `--working-dir` retry path. The previous dead-end (`SourceAvailability::Unknown` on NOT_FOUND) no longer occurs for packages with `source.url` data.
35. Integration test verifies: a non-Packagist package fixture that previously returned `Unknown` now returns `Available` or `Unavailable`

### AC-10: Test coverage

36. Unit test: `SourceAvailability` enum has correct string backing values
37. Unit test: `ComposerVersionResolver` with `--working-dir` fallback path (both success and SSH failure)
38. Unit test: `ExtensionDiscoveryService` populates `repositoryUrl` from `source.url`
39. Unit test: SSH connectivity check caching (mock ssh process)
40. Unit test: `AnalysisContext` with `installationPath` property
41. Unit test: HTTPS URL bypasses SSH check
42. Integration test: end-to-end VCS detection for a non-Packagist package fixture
43. All existing tests pass (no regression)
44. PHPStan Level 8: 0 errors; `composer lint:php`: 0 issues

### AC-11: Documentation (out of scope for dev, tracked separately)

45. User guide documents SSH authentication prerequisites (local dev SSH agent, CI deploy keys, troubleshooting). This is a documentation task, not a code task — tracked but not blocking story completion.

## Tasks / Subtasks

- [ ] Task 0: Create `SourceAvailability` enum (AC: 8)
  - [ ] 0.1 Create `src/Domain/ValueObject/SourceAvailability.php` as string-backed enum with cases `Available`, `Unavailable`, `Unknown`
  - [ ] 0.2 Unit test for enum: correct `.value` strings, all cases enumerable
- [ ] Task 1: Add `installationPath` to `AnalysisContext` (AC: 3)
  - [ ] 1.1 Add nullable `string $installationPath` property to `AnalysisContext` value object
  - [ ] 1.2 Add getter `getInstallationPath(): ?string`
  - [ ] 1.3 Update constructor or factory to accept installation path
  - [ ] 1.4 Update all call sites that create `AnalysisContext` (likely `AnalyzeCommand`)
  - [ ] 1.5 Unit test for new property
- [ ] Task 2: Bridge `source.url` in ExtensionDiscoveryService (AC: 1)
  - [ ] 2.1 In `createExtensionFromComposerData()`, read `$packageData['source']['url']`
  - [ ] 2.2 Call `$extension->setRepositoryUrl($sourceUrl)` when non-empty string
  - [ ] 2.3 Unit test: package data with `source.url` populates `repositoryUrl`
  - [ ] 2.4 Unit test: package data without `source` key leaves `repositoryUrl` null
  - [ ] 2.5 Unit test: package data with empty `source.url` leaves `repositoryUrl` null
- [ ] Task 3: Add `--working-dir` fallback to ComposerVersionResolver (AC: 2, 3, 6, 7)
  - [ ] 3.1 Extend `VcsResolverInterface::resolve()` signature: add `?string $installationPath = null`
  - [ ] 3.2 In `ComposerVersionResolver::resolve()`: after NOT_FOUND from primary, if `$installationPath` non-null, run `composer show --working-dir=$installationPath --format=json $packageName`
  - [ ] 3.3 Parse fallback result through existing `findTypo3Requirements()` + `isConstraintCompatible()` pipeline
  - [ ] 3.4 Return appropriate `VcsResolutionResult` from fallback
  - [ ] 3.5 Unit test: primary found -> no fallback attempted (AC: 7)
  - [ ] 3.6 Unit test: primary NOT_FOUND, fallback succeeds -> returns resolved result
  - [ ] 3.7 Unit test: primary NOT_FOUND, no installation path -> returns NOT_FOUND
  - [ ] 3.8 Unit test: primary NOT_FOUND, fallback also fails -> returns NOT_FOUND/FAILURE
  - [ ] 3.9 Unit test: HTTPS source URL bypasses SSH check, goes directly to --working-dir (AC: 6)
- [ ] Task 4: SSH connectivity pre-check (AC: 5)
  - [ ] 4.1 Add SSH host extraction from URL (`git@host:...` -> `host`)
  - [ ] 4.2 Implement `checkSshConnectivity(string $host): bool` in ComposerVersionResolver (or separate helper)
  - [ ] 4.3 Cache SSH check result per host (instance array, reset per analysis run)
  - [ ] 4.4 Skip `--working-dir` fallback when SSH host unreachable
  - [ ] 4.5 Unit test: SSH host extraction from various URL formats
  - [ ] 4.6 Unit test: SSH check cached per host
  - [ ] 4.7 Unit test: unreachable host skips fallback
- [ ] Task 5: Update VcsSource to pass installationPath and use SourceAvailability (AC: 3, 4, 8)
  - [ ] 5.1 In `VcsSource::checkAvailability()`, extract `$context->getInstallationPath()`
  - [ ] 5.2 Pass to `$resolver->resolve($composerName, $repositoryUrl, $targetVersion, $installationPath)`
  - [ ] 5.3 Replace `vcs_available` return values: `true` -> `SourceAvailability::Available`, `false` -> `SourceAvailability::Unavailable`, `null` -> `SourceAvailability::Unknown`
  - [ ] 5.4 Update existing VcsSource tests to use enum assertions
- [ ] Task 6: Migrate VCS consumers to SourceAvailability enum (AC: 8)
  - [ ] 6.1 `VersionAvailabilityAnalyzer::calculateRiskScore()`: replace `true ===` / `null ===` checks with enum case matches (3 locations)
  - [ ] 6.2 `VersionAvailabilityAnalyzer::addRecommendations()`: replace `true ===` / `true !==` checks with enum case matches (6 locations)
  - [ ] 6.3 `ReportContextBuilder::aggregateAvailabilityStats()`: replace strict checks with enum case matches (2 locations)
  - [ ] 6.4 `VersionAvailabilityDataProvider::extractData()`: pass `->value` string for `vcs_available` to template context
  - [ ] 6.5 4 Twig templates: replace `is same as(true)` / `is same as(false)` with `== 'available'` / `== 'unavailable'`
  - [ ] 6.6 Update all affected unit tests: `VersionAvailabilityAnalyzerTest`, `ReportContextBuilderTest`
- [ ] Task 7: Warning messages for SSH degradation (AC: 4)
  - [ ] 7.1 Update VcsSource warning logic: add SSH-specific message variant
  - [ ] 7.2 Single WARNING per unreachable SSH host (not per package)
  - [ ] 7.3 Unit test: SSH failure produces host-level warning
- [ ] Task 8: Full verification (AC: 9, 10)
  - [ ] 8.1 Run full test suite
  - [ ] 8.2 PHPStan Level 8: 0 errors
  - [ ] 8.3 `composer lint:php`: 0 issues
  - [ ] 8.4 Integration test: non-Packagist fixture that previously returned Unknown now resolves (AC: 9)
  - [ ] 8.5 Integration test for end-to-end VCS detection with non-Packagist fixture

## Dev Notes

### Root Causes Being Fixed

This story fixes three chained root causes discovered during Story 2-5 code review:

- **RC-1**: `ComposerVersionResolver::resolve()` at line 52 accepts `$vcsUrl` but ignores it. Runs `composer show --all` which only queries Packagist. Non-Packagist packages always return NOT_FOUND.
- **RC-2**: `ExtensionDiscoveryService::createExtensionFromComposerData()` at lines 506-559 never reads `source.url` from `composer.lock` package data. `Extension.repositoryUrl` is always null in production.
- **RC-3**: `VcsSource` has no fallback when resolver returns NOT_FOUND. Returns null metrics without retry.

### Design Decision: SourceAvailability Enum

Replace the fragile `null`/`true`/`false` encoding for `vcs_available` with a string-backed enum in the Domain layer:

```php
// src/Domain/ValueObject/SourceAvailability.php
enum SourceAvailability: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}
```

**Why string-backed:** Twig templates can compare `vcs_available == 'available'` without `constant()` calls. JSON serialization works naturally. PHPStan understands enum cases at Level 8.

**Why VCS-only:** TER and Packagist sources are genuinely binary (available or not — API errors return `false`, not "unknown"). The asymmetry already exists in the codebase: VCS uses strict identity checks (`true ===`, `is same as(true)`) at 11 locations, while TER/Packagist use simple truthiness. The enum formalizes an existing distinction.

**Consumer migration pattern:**
```php
// Before (fragile):
if (true === $vcsAvailable) { ... }
elseif (null === $vcsAvailable) { $maxPossibleScore -= 2; }

// After (explicit):
if ($vcsAvailable === SourceAvailability::Available) { ... }
elseif ($vcsAvailable === SourceAvailability::Unknown) { $maxPossibleScore -= 2; }
```

**Template migration pattern:**
```twig
{# Before: #}
{% if data.version_analysis.vcs_available is same as(true) %}
{# After: #}
{% if data.version_analysis.vcs_available == 'available' %}
```

**Blast radius:** 11 strict identity checks across 3 PHP files, 4 Twig templates. TER/Packagist sources and their consumers are untouched.

### Design Decision: Installation Path via AnalysisContext

Per Sprint Change Proposal AC-2, use option (b): add `installationPath` to `AnalysisContext`.

`AnalysisContext` currently carries: `currentVersion`, `targetVersion`, `phpVersions`, `configuration`. Adding `installationPath` is natural — it already carries installation metadata.

`VcsResolverInterface::resolve()` signature change: add `?string $installationPath = null` as a trailing optional parameter for backwards compatibility.

### ComposerVersionResolver Fallback Strategy

```
resolve($packageName, $vcsUrl, $targetVersion, $installationPath):
  1. Primary: composer show --all --format=json $packageName
     → If found: scan versions, check compatibility, return result
  2. If NOT_FOUND and $installationPath is set:
     a. If URL is SSH-based, check SSH connectivity cache
        → If host unreachable (cached): return NOT_FOUND
     b. Fallback: composer show --working-dir=$installationPath --format=json $packageName
        → If found: scan versions, check compatibility, return result
        → If fails (SSH auth, timeout): return NOT_FOUND with error detail
  3. Return NOT_FOUND
```

The `--working-dir` overhead is ~11-13s per call (documented in Spike 2-0). Only triggered for non-Packagist packages. For the test project (8 packages): ~90-100s additional time. Acceptable for correctness.

### SSH URL Detection

SSH URLs follow pattern: `git@<host>:<path>.git` or `ssh://git@<host>/<path>.git`

Extract host from URL to use for connectivity check. Cache check result in instance array `$sshHostStatus: array<string, bool>`.

### Key Constraint: Process Factory Pattern

`ComposerVersionResolver` uses `?\Closure $processFactory` for unit testing without real CLI. The `--working-dir` fallback and SSH check must use the same factory pattern.

From Story 2-2 learnings:
- `--working-dir` has 11-13s overhead — acceptable only for fallback
- Use `findTypo3Requirements()` + `isConstraintCompatible()` for compatibility check (NOT `isComposerJsonCompatible()`)
- Linear scan strategy (newest-to-oldest) is correct

### Files to Modify

**New (PHP):**
- `src/Domain/ValueObject/SourceAvailability.php` — string-backed enum

**Modify (PHP):**
- `src/Domain/ValueObject/AnalysisContext.php` — add `installationPath` property + getter
- `src/Infrastructure/Discovery/ExtensionDiscoveryService.php` — read `source.url`, call `setRepositoryUrl()`
- `src/Infrastructure/ExternalTool/VcsResolverInterface.php` — add `$installationPath` parameter
- `src/Infrastructure/ExternalTool/ComposerVersionResolver.php` — implement `--working-dir` fallback + SSH check
- `src/Infrastructure/Analyzer/VersionAvailability/Source/VcsSource.php` — pass `installationPath` to resolver, return `SourceAvailability` enum
- `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php` — replace 9 VCS strict identity checks with enum case matches
- `src/Infrastructure/Reporting/ReportContextBuilder.php` — replace 2 VCS strict checks with enum case matches
- `src/Infrastructure/Reporting/Provider/VersionAvailabilityDataProvider.php` — pass `->value` string for `vcs_available`
- `src/Application/Command/AnalyzeCommand.php` — pass installation path when constructing `AnalysisContext`

**Modify (Templates):**
- `resources/templates/html/partials/main-report/version-availability-table.html.twig` — `is same as(true/false)` -> string comparison
- `resources/templates/md/partials/main-report/version-availability-table.md.twig` — same
- `resources/templates/html/partials/extension-detail/version-availability-analysis.html.twig` — same
- `resources/templates/md/partials/extension-detail/version-availability-analysis.md.twig` — same

**New (Tests):**
- `tests/Unit/Domain/ValueObject/SourceAvailabilityTest.php` — enum backing values

**Modify (Tests):**
- `tests/Unit/Infrastructure/ExternalTool/ComposerVersionResolverTest.php` — fallback tests
- `tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php` — source.url tests (create if not exists)
- `tests/Unit/Domain/ValueObject/AnalysisContextTest.php` — installationPath tests (create if not exists)
- `tests/Unit/Infrastructure/Analyzer/VersionAvailability/Source/VcsSourceTest.php` — enum assertions
- `tests/Unit/Infrastructure/Analyzer/VersionAvailabilityAnalyzerTest.php` — enum values in test data
- `tests/Unit/Infrastructure/Reporting/ReportContextBuilderTest.php` — enum values in test data
- `tests/Integration/Analyzer/VersionAvailabilityIntegrationTestCase.php` — update context construction

### Existing Classes to Reuse (do NOT reinvent)

- `VcsResolverInterface` at `src/Infrastructure/ExternalTool/VcsResolverInterface.php` — extend signature
- `ComposerVersionResolver` at `src/Infrastructure/ExternalTool/ComposerVersionResolver.php` — add fallback logic
- `VcsResolutionResult` at `src/Infrastructure/ExternalTool/VcsResolutionResult.php` — reuse as-is
- `VcsResolutionStatus` at `src/Infrastructure/ExternalTool/VcsResolutionStatus.php` — reuse as-is
- `Extension::setRepositoryUrl()` at `src/Domain/Entity/Extension.php:170-183` — already exists, just needs to be called
- `AnalysisContext` at `src/Domain/ValueObject/AnalysisContext.php` — extend with new property
- Process factory `?\Closure $processFactory` pattern from `ComposerVersionResolver` — reuse for SSH check

### Testing Patterns

- PHPUnit ^12.5 attributes only: `#[CoversClass]`, `#[Test]`, `#[DataProvider]`
- No `test` prefix on methods; method names describe expected behaviour
- `self::assertEquals()` (never `$this->`)
- Mock interfaces, not concrete classes
- Use `createStub()` for Process objects (PHPUnit 12 compatibility)
- Data providers: `public static function descriptiveNameProvider(): iterable`

### Evidence from Investigation

9 extensions affected in test project `zug12`:
- 4 on GitHub with HTTPS source URLs + dist URLs (should resolve with `--working-dir`)
- 4 on GitLab with SSH-only source URLs, no dist (require SSH auth for `--working-dir`)
- 1 local path package (correctly handled, not part of this fix)

All 22 working VCS detections are packages also on Packagist — VCS detection currently adds zero value beyond Packagist.

### Performance Guard

- `--working-dir` fallback only for NOT_FOUND from primary (zero overhead for Packagist packages)
- SSH connectivity check cached per host (one `ssh -T` per host per run, not per package)
- Unreachable SSH hosts: all packages on that host skipped immediately (no repeated 11-13s timeouts)

### Project Structure Notes

- `AnalysisContext` is in Domain layer (`src/Domain/ValueObject/`) — it should remain a pure value object with no framework dependencies. `installationPath` is a primitive string, so this is fine.
- `ComposerVersionResolver` is Infrastructure layer — framework/process imports are allowed
- SSH check uses same `Process` / `$processFactory` pattern as existing Composer CLI calls
- Service tags unchanged: `VcsResolverInterface` alias to `ComposerVersionResolver` already configured in `services.yaml`

### References

- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-04-03.md] — full proposal with ACs
- [Source: tmp/vcs-detection-investigation.md] — root cause analysis with evidence
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.5a] — epic story definition
- [Source: _bmad-output/implementation-artifacts/2-5-integrate-resolvers-and-update-data-model.md] — previous story context
- [Source: src/Infrastructure/ExternalTool/ComposerVersionResolver.php:46-101] — resolve() method to modify
- [Source: src/Infrastructure/Discovery/ExtensionDiscoveryService.php:506-559] — createExtensionFromComposerData()
- [Source: src/Infrastructure/Analyzer/VersionAvailability/Source/VcsSource.php:40-79] — checkAvailability()
- [Source: src/Domain/ValueObject/AnalysisContext.php] — value object to extend
- [Source: src/Domain/Entity/Extension.php:170-183] — repositoryUrl property (exists, unused in production)

## Dev Agent Record

### Agent Model Used

{{agent_model_name_version}}

### Debug Log References

### Completion Notes List

### File List
