# Story 2.6: Legacy Git Provider Cleanup

Status: ready-for-dev

## Story

As a developer,
I want the old per-provider Git code removed from the codebase,
So that the codebase reflects the current architecture without dead code.

## Context

Story 2.5 replaced `GitSource` with `VcsSource` and wired `VcsResolverInterface` to `GitVersionResolver`
(Story 2-5b). The old Git provider subsystem (`GitHubClient`, `AbstractGitProvider`, `GitProviderFactory`,
etc.) was unwired from `services.yaml` but left in place. `GenericGitResolver` was cancelled in Sprint
Change Proposal 2026-03-29c but also never deleted. `GitSource` (the old source class) still exists
alongside `VcsSource`. This story deletes all of it.

**Prerequisites:** Story 2.5 + 2.5a + 2.5b complete, all tests green.

**Current state of the codebase (verified 2026-04-10):**
- `src/Infrastructure/Analyzer/VersionAvailability/Source/GitSource.php` — still exists, not wired in services.yaml
- `src/Infrastructure/ExternalTool/GitProvider/` — 5 files, not wired in services.yaml (unwired in 2.5)
- `src/Infrastructure/ExternalTool/GenericGitResolver.php` — cancelled class, not wired, still on disk
- `src/Infrastructure/ExternalTool/GitRepositoryAnalyzer.php` — not wired (removed from VcsSource in 2.5)
- `src/Infrastructure/ExternalTool/GitRepositoryHealth.php`, `GitRepositoryInfo.php`, `GitRepositoryMetadata.php`, `GitTag.php`, `GitVersionParser.php`, `GitAnalysisException.php` — orphaned support classes
- `config/services.yaml` lines 227–250: explicit entries for `GitProviderFactory`, `GitHubClient`, `GitRepositoryAnalyzer`, `GitVersionParser` — must be removed
- `src/Infrastructure/Repository/RepositoryUrlHandler.php` / `RepositoryUrlHandlerInterface.php` — still **actively used** by `PackagistClient` — **DO NOT DELETE**
- `src/Infrastructure/ExternalTool/GitVersionResolver.php` — the **new** resolver from Story 2-5b — **DO NOT DELETE**

## Acceptance Criteria

### AC-1: GitProvider subsystem deleted

1. `src/Infrastructure/ExternalTool/GitProvider/GitProviderInterface.php` deleted
2. `src/Infrastructure/ExternalTool/GitProvider/AbstractGitProvider.php` deleted
3. `src/Infrastructure/ExternalTool/GitProvider/GitHubClient.php` deleted
4. `src/Infrastructure/ExternalTool/GitProvider/GitProviderFactory.php` deleted
5. `src/Infrastructure/ExternalTool/GitProvider/GitProviderException.php` deleted (no `VcsResolutionException` needed — `VcsResolutionStatus::FAILURE` already covers failure cases)

### AC-2: Orphaned ExternalTool classes deleted

6. `src/Infrastructure/ExternalTool/GenericGitResolver.php` deleted (cancelled in Sprint Change Proposal 2026-03-29c)
7. `src/Infrastructure/ExternalTool/GitRepositoryAnalyzer.php` deleted
8. `src/Infrastructure/ExternalTool/GitRepositoryHealth.php` deleted
9. `src/Infrastructure/ExternalTool/GitRepositoryInfo.php` deleted
10. `src/Infrastructure/ExternalTool/GitRepositoryMetadata.php` deleted
11. `src/Infrastructure/ExternalTool/GitTag.php` deleted
12. `src/Infrastructure/ExternalTool/GitVersionParser.php` deleted
13. `src/Infrastructure/ExternalTool/GitAnalysisException.php` deleted

### AC-3: Legacy source class deleted

14. `src/Infrastructure/Analyzer/VersionAvailability/Source/GitSource.php` deleted

### AC-4: services.yaml cleaned up

15. The `GitProviderFactory` service block (lines ~227–233) removed from `config/services.yaml`
16. The `GitHubClient` service block (lines ~235–240) removed from `config/services.yaml`
17. The `GitRepositoryAnalyzer` service block (lines ~242–248) removed from `config/services.yaml`
18. The `GitVersionParser` service block (lines ~250–251) removed from `config/services.yaml`
19. No other reference to deleted class FQCNs remains in `services.yaml`

### AC-5: No orphaned imports or references in surviving source files

20. No surviving `.php` file in `src/` imports or references any deleted class
21. No surviving `.php` file in `tests/` imports or references any deleted class (except the test files being deleted themselves)

### AC-6: Associated test files deleted

22. `tests/Unit/Infrastructure/ExternalTool/GenericGitResolverTest.php` deleted
23. `tests/Unit/Infrastructure/ExternalTool/GitProvider/AbstractGitProviderTest.php` deleted
24. `tests/Unit/Infrastructure/ExternalTool/GitProvider/GitHubClientTest.php` deleted
25. `tests/Unit/Infrastructure/ExternalTool/GitProvider/GitProviderFactoryTest.php` deleted
26. `tests/Unit/Infrastructure/ExternalTool/GitRepositoryAnalyzerTest.php` deleted
27. `tests/Unit/Infrastructure/ExternalTool/GitRepositoryHealthTest.php` deleted (if exists)
28. `tests/Unit/Infrastructure/ExternalTool/GitRepositoryMetadataTest.php` deleted (if exists)
29. `tests/Unit/Infrastructure/ExternalTool/GitTagTest.php` deleted (if exists)
30. `tests/Unit/Infrastructure/ExternalTool/GitVersionParserTest.php` deleted
31. `tests/Unit/Infrastructure/Analyzer/VersionAvailability/Source/GitSourceTest.php` deleted

### AC-7: Quality gate

32. `composer test` — all tests pass, 0 failures
33. `composer sca:php` — PHPStan Level 8: 0 errors
34. `composer lint:php` — 0 issues

## Tasks / Subtasks

- [ ] Task 1: Grep all deleted class FQCNs in surviving source before touching anything
  - [ ] 1.1 Run: `grep -rn "GitProvider\|GitRepositoryAnalyzer\|GitRepositoryHealth\|GitRepositoryInfo\|GitRepositoryMetadata\|GitTag\|GitVersionParser\|GitAnalysisException\|GenericGitResolver\|GitSource" src/ --include="*.php" -l`
  - [ ] 1.2 Confirm hits are only the files being deleted (not in VcsSource, PackagistClient, etc.)
  - [ ] 1.3 Confirm `RepositoryUrlHandler` is NOT in the hit list (it is used by PackagistClient — keep it)
  - [ ] 1.4 Confirm `GitVersionResolver.php` is NOT in the hit list (it is the new resolver from 2-5b — keep it)

- [ ] Task 2: Delete GitProvider subsystem (AC-1)
  - [ ] 2.1 Delete `src/Infrastructure/ExternalTool/GitProvider/GitProviderInterface.php`
  - [ ] 2.2 Delete `src/Infrastructure/ExternalTool/GitProvider/AbstractGitProvider.php`
  - [ ] 2.3 Delete `src/Infrastructure/ExternalTool/GitProvider/GitHubClient.php`
  - [ ] 2.4 Delete `src/Infrastructure/ExternalTool/GitProvider/GitProviderFactory.php`
  - [ ] 2.5 Delete `src/Infrastructure/ExternalTool/GitProvider/GitProviderException.php`
  - [ ] 2.6 Remove now-empty `src/Infrastructure/ExternalTool/GitProvider/` directory

- [ ] Task 3: Delete orphaned ExternalTool classes (AC-2)
  - [ ] 3.1 Delete `src/Infrastructure/ExternalTool/GenericGitResolver.php`
  - [ ] 3.2 Delete `src/Infrastructure/ExternalTool/GitRepositoryAnalyzer.php`
  - [ ] 3.3 Delete `src/Infrastructure/ExternalTool/GitRepositoryHealth.php`
  - [ ] 3.4 Delete `src/Infrastructure/ExternalTool/GitRepositoryInfo.php`
  - [ ] 3.5 Delete `src/Infrastructure/ExternalTool/GitRepositoryMetadata.php`
  - [ ] 3.6 Delete `src/Infrastructure/ExternalTool/GitTag.php`
  - [ ] 3.7 Delete `src/Infrastructure/ExternalTool/GitVersionParser.php`
  - [ ] 3.8 Delete `src/Infrastructure/ExternalTool/GitAnalysisException.php`

- [ ] Task 4: Delete legacy GitSource (AC-3)
  - [ ] 4.1 Delete `src/Infrastructure/Analyzer/VersionAvailability/Source/GitSource.php`

- [ ] Task 5: Clean up services.yaml (AC-4)
  - [ ] 5.1 Remove `GitProviderFactory` service block from `config/services.yaml`
  - [ ] 5.2 Remove `GitHubClient` service block from `config/services.yaml`
  - [ ] 5.3 Remove `GitRepositoryAnalyzer` service block from `config/services.yaml`
  - [ ] 5.4 Remove `GitVersionParser` service block from `config/services.yaml`
  - [ ] 5.5 Verify no other reference to deleted FQCNs remains in `services.yaml`

- [ ] Task 6: Delete associated test files (AC-6)
  - [ ] 6.1 Delete `tests/Unit/Infrastructure/ExternalTool/GenericGitResolverTest.php`
  - [ ] 6.2 Delete `tests/Unit/Infrastructure/ExternalTool/GitProvider/AbstractGitProviderTest.php`
  - [ ] 6.3 Delete `tests/Unit/Infrastructure/ExternalTool/GitProvider/GitHubClientTest.php`
  - [ ] 6.4 Delete `tests/Unit/Infrastructure/ExternalTool/GitProvider/GitProviderFactoryTest.php`
  - [ ] 6.5 Delete `tests/Unit/Infrastructure/ExternalTool/GitRepositoryAnalyzerTest.php`
  - [ ] 6.6 Delete `tests/Unit/Infrastructure/ExternalTool/GitRepositoryHealthTest.php` (if exists)
  - [ ] 6.7 Delete `tests/Unit/Infrastructure/ExternalTool/GitRepositoryMetadataTest.php` (if exists)
  - [ ] 6.8 Delete `tests/Unit/Infrastructure/ExternalTool/GitTagTest.php` (if exists)
  - [ ] 6.9 Delete `tests/Unit/Infrastructure/ExternalTool/GitVersionParserTest.php`
  - [ ] 6.10 Delete `tests/Unit/Infrastructure/Analyzer/VersionAvailability/Source/GitSourceTest.php`
  - [ ] 6.11 Remove now-empty `tests/Unit/Infrastructure/ExternalTool/GitProvider/` directory

- [ ] Task 7: Full verification (AC-7)
  - [ ] 7.1 `composer test` — all tests pass
  - [ ] 7.2 `composer sca:php` — PHPStan Level 8: 0 errors
  - [ ] 7.3 `composer lint:php` — 0 issues

## Dev Notes

### Critical: Do NOT Delete These Files

The following files have names that look like the git provider subsystem but are **not** legacy code:

| File | Reason to keep |
|------|----------------|
| `src/Infrastructure/ExternalTool/GitVersionResolver.php` | Created in Story 2-5b — the current VCS resolver |
| `tests/Unit/Infrastructure/ExternalTool/GitVersionResolverTest.php` | Test for the current VCS resolver |
| `src/Infrastructure/Repository/RepositoryUrlHandler.php` | Used by `PackagistClient` — active code |
| `src/Infrastructure/Repository/RepositoryUrlHandlerInterface.php` | Interface for `RepositoryUrlHandler` |

### Deletion Strategy

This story is pure deletion — no new code written. The safe approach:

1. Run the grep in Task 1 first. Abort if any surviving source file (outside the deletion list) imports a class to be deleted.
2. Delete files in order: source files first, then test files, then config.
3. Run `composer test` after each batch of deletions to catch problems early.
4. If PHPStan complains about a reference you missed, grep for the FQCN and clean it up.

### services.yaml: Exact Blocks to Remove

From `config/services.yaml` (approximately lines 227–251):

```yaml
  CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory:
    arguments:
      $providers:
        - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient'

  CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient:
    arguments:
      - ...
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandlerInterface'

  CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer:
    arguments:
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory'
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser'

  CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser: ~
```

The `RepositoryUrlHandlerInterface` alias and the `RepositoryUrlHandler` service entries **above** these blocks (lines ~54–57, 72) must **not** be removed — they serve `PackagistClient`.

### GenericGitResolver Note

`GenericGitResolver` was cancelled in Sprint Change Proposal 2026-03-29c (git archive --remote non-functional on hosted git services). The file was never removed from the codebase. The class still implements `VcsResolverInterface` — it was updated in Story 2-5b only to match the new interface signature. No active consumer. Safe to delete.

### GitSource Note

`GitSource` was the pre-2.5 VCS source. It was replaced by `VcsSource` in Story 2.5. It is no longer tagged in `services.yaml` (unwired in 2.5). It still exists on disk. The file and its test (`GitSourceTest.php`) are dead code.

### Testing Patterns (for reference, no new tests written in this story)

- PHPUnit ^12.5 attributes: `#[CoversClass]`, `#[Test]`, `#[DataProvider]`
- No `test` prefix on methods
- `self::assertEquals()` not `$this->`

## Dev Agent Record

### Implementation Notes

_(to be filled in after implementation)_

## File List

### Deleted (Source)
- `src/Infrastructure/ExternalTool/GitProvider/GitProviderInterface.php`
- `src/Infrastructure/ExternalTool/GitProvider/AbstractGitProvider.php`
- `src/Infrastructure/ExternalTool/GitProvider/GitHubClient.php`
- `src/Infrastructure/ExternalTool/GitProvider/GitProviderFactory.php`
- `src/Infrastructure/ExternalTool/GitProvider/GitProviderException.php`
- `src/Infrastructure/ExternalTool/GenericGitResolver.php`
- `src/Infrastructure/ExternalTool/GitRepositoryAnalyzer.php`
- `src/Infrastructure/ExternalTool/GitRepositoryHealth.php`
- `src/Infrastructure/ExternalTool/GitRepositoryInfo.php`
- `src/Infrastructure/ExternalTool/GitRepositoryMetadata.php`
- `src/Infrastructure/ExternalTool/GitTag.php`
- `src/Infrastructure/ExternalTool/GitVersionParser.php`
- `src/Infrastructure/ExternalTool/GitAnalysisException.php`
- `src/Infrastructure/Analyzer/VersionAvailability/Source/GitSource.php`

### Deleted (Tests)
- `tests/Unit/Infrastructure/ExternalTool/GenericGitResolverTest.php`
- `tests/Unit/Infrastructure/ExternalTool/GitProvider/AbstractGitProviderTest.php`
- `tests/Unit/Infrastructure/ExternalTool/GitProvider/GitHubClientTest.php`
- `tests/Unit/Infrastructure/ExternalTool/GitProvider/GitProviderFactoryTest.php`
- `tests/Unit/Infrastructure/ExternalTool/GitRepositoryAnalyzerTest.php`
- `tests/Unit/Infrastructure/ExternalTool/GitRepositoryHealthTest.php`
- `tests/Unit/Infrastructure/ExternalTool/GitRepositoryMetadataTest.php`
- `tests/Unit/Infrastructure/ExternalTool/GitTagTest.php`
- `tests/Unit/Infrastructure/ExternalTool/GitVersionParserTest.php`
- `tests/Unit/Infrastructure/Analyzer/VersionAvailability/Source/GitSourceTest.php`

### Modified (Config)
- `config/services.yaml` — remove GitProviderFactory, GitHubClient, GitRepositoryAnalyzer, GitVersionParser service blocks

## Change Log

- 2026-04-10: Story created — full deletion scope documented based on codebase audit.
