# Sprint Change Proposal — 2026-03-29 (2)

## Section 1: Issue Summary

**Problem statement:** Contributor commit `1c2781e` ([TASK] Refactor external sources)
introduced `VersionSourceInterface` and wired `VersionAvailabilityAnalyzer` against a
`version_availability.source` tagged iterator. This makes the analyzer source-agnostic —
it no longer knows or cares about `TerApiClient`, `PackagistClient`, or
`GitRepositoryAnalyzer`.

As a result, `VersionAvailabilityAnalyzer` already does what Story 2.5 planned to do at
the analyzer level. The unfinished half is `GitSource`: it still delegates to
`GitRepositoryAnalyzer` → `GitProviderFactory` → `GitHubClient`. Story 2.5's integration
point must shift from `VersionAvailabilityAnalyzer` to `GitSource`. Additionally,
`GitSource` should be renamed `VcsSource` and inject `VcsResolverInterface` via a
priority-tagged iterator — making it extensible to future VCS types (SVN, Mercurial,
Fossil) without any class-level changes.

**Discovery context:** Identified by Dirk on 2026-03-29, after reviewing commit `1c2781e`.
The contributor's structural approach is accepted. The sprint plan has not yet been updated
to reflect the implemented state.

**Evidence:**
- `VersionAvailabilityAnalyzer` constructor: `iterable<VersionSourceInterface> $sources` — concrete resolver classes are gone
- `GitSource` constructor: `GitRepositoryAnalyzer $gitAnalyzer` — old chain still active
- `config/services.yaml` lines 89–94: `GitSource` wired with `GitRepositoryAnalyzer`
- `VcsResolverInterface`, `PackagistVersionResolver`, `GenericGitResolver`: built, tested, not wired into any source

---

## Section 2: Impact Analysis

### Epic Impact

Epic 2 goal is unaffected. Sequence and story count unchanged. Only Story 2.5's scope
description needs updating.

### Story Impact

| Story | Status | Impact |
|---|---|---|
| 2.2 `PackagistVersionResolver` | done | None — already implements `VcsResolverInterface` |
| 2.3 `GenericGitResolver` | done | None — already implements `VcsResolverInterface` |
| 2.4 Unresolvable VCS Warning | not started | None |
| **2.5 Integration** | not started | **Scope rewritten**: integration point changes from `VersionAvailabilityAnalyzer` to `VcsSource` (renamed from `GitSource`); wiring uses `vcs_resolver` tagged iterator, not concrete classes |
| 2.6 Legacy cleanup | not started | Unchanged — `GitHubClient`, `GitProviderFactory`, `GitProviderInterface`, `GitRepositoryAnalyzer` still targeted for deletion |

### Artifact Conflicts

| Artifact | Conflict | Action |
|---|---|---|
| `epics.md` | Story 2.5 AC references `VersionAvailabilityAnalyzer` as integration point; no mention of `VcsSource` or `vcs_resolver` tag | Rewrite |
| `architecture.md` | Lines 319, 328: name `VersionAvailabilityAnalyzer` as the integration target; no mention of `VcsSource` or tagged resolver pattern | Update both |
| `src/.../Source/GitSource.php` | Should be renamed `VcsSource.php`; should inject `VcsResolverInterface` iterable, not `GitRepositoryAnalyzer` | Story 2.5 scope |
| `services.yaml` | `GitSource` still wired with `GitRepositoryAnalyzer`; `vcs_resolver` tag not yet defined | Story 2.5 scope |
| PRD | No conflict | No change |

### Technical Impact

- `GitSource.php` → `VcsSource.php`: class rename + replace `GitRepositoryAnalyzer` dep
  with `iterable<VcsResolverInterface>`; implement priority iteration with fallback logic;
  rename `git_*` metrics to `vcs_*`
- `VersionAvailabilityAnalyzer.php`: update `calculateRiskScore()` and
  `addRecommendations()` to read `vcs_*` metric keys; remove health-weighted scoring
- `services.yaml`: add `vcs_resolver` tag with priority to both resolvers; rewire
  `VcsSource` with `!tagged_iterator vcs_resolver`; unwire `GitRepositoryAnalyzer` from
  source args; unwire `GitHubClient`, `GitProviderFactory` (not deleted)
- `ReportContextBuilder` + templates: `git_*` → `vcs_*` rename; remove health card

---

## Section 3: Recommended Approach

**Direct Adjustment** — Story 2.5 scope rewrite. No stories added or removed.

**Rationale:**
- `VersionAvailabilityAnalyzer` integration is already done by the contributor
- `VcsSource` with a `vcs_resolver` tagged iterator is the correct single-responsibility
  location for resolver chain logic; the analyzer must not know tier details
- Priority-tagged wiring (`priority: 100` / `priority: 50`) follows the project's existing
  `!tagged_iterator` pattern; future resolvers need only a tag — no `VcsSource` change
- Story 2.6 cleanup scope is unaffected
- Metric rename and template work are identical in volume to the original plan

Effort: **Low** | Risk: **Low** | Timeline impact: **None**

---

## Section 4: Detailed Change Proposals

### 4A — `epics.md`: Rewrite Story 2.5

**OLD:**

```
### Story 2.5: VCS Resolution Integration, Data Model Migration, and Template Updates

As a developer,
I want the new Composer-based VCS resolution wired into `VersionAvailabilityAnalyzer`,
replacing the existing `GitRepositoryAnalyzer` delegation,
So that all VCS sources are resolved through the new two-tier chain and reports reflect
the provider-agnostic data model.

Acceptance Criteria:
- Given Stories 2.1–2.4 are complete and tested
- When `VersionAvailabilityAnalyzer` is updated
- Then it delegates VCS resolution to `PackagistVersionResolver` → `GenericGitResolver`
  instead of `GitRepositoryAnalyzer`
[...]
- And `services.yaml` wires `PackagistVersionResolver` and `GenericGitResolver`;
  `GitRepositoryAnalyzer` is unwired but not deleted
[...]
Note: This is the integration gate. The old `GitHubClient` is unwired here but not
deleted — that happens in Story 2.6.
```

**NEW:**

```
### Story 2.5: VCS Resolution Integration in VcsSource, Data Model Migration,
and Template Updates

As a developer,
I want `GitSource` renamed to `VcsSource` and updated to iterate `VcsResolverInterface`
implementations via a priority-tagged iterator,
So that VCS availability is resolved through the existing two-tier chain (and any future
resolvers) without coupling the source class to concrete implementations.

Acceptance Criteria:

- Given Stories 2.1–2.4 are complete and tested
- When `GitSource` is refactored into `VcsSource`
- Then `src/Infrastructure/Analyzer/VersionAvailability/Source/GitSource.php` is renamed
  to `VcsSource.php`; the class is renamed `VcsSource`; it still implements
  `VersionSourceInterface`
- And `VcsSource` injects `iterable<VcsResolverInterface> $resolvers` via constructor
  (no concrete resolver class names in the constructor signature)
- And `VcsSource::checkAvailability()` iterates `$resolvers` in declared priority order;
  for each resolver it calls `resolve()`; if the result's `shouldTryFallback()` returns
  `true`, it continues to the next resolver; otherwise it stops and maps the result to metrics
- And `VersionAvailabilityAnalyzer` is NOT modified — it already iterates
  `VersionSourceInterface` via tagged iterator (done in commit 1c2781e)
- And `services.yaml` introduces a `vcs_resolver` tag; `PackagistVersionResolver` is tagged
  `{ name: vcs_resolver, priority: 100 }`; `GenericGitResolver` is tagged
  `{ name: vcs_resolver, priority: 50 }`; `VcsSource` injects
  `!tagged_iterator vcs_resolver`
- And `GitSource` is removed from `services.yaml`; `VcsSource` replaces it with the
  `version_availability.source` tag
- And `GitRepositoryAnalyzer` is removed from `VcsSource` args but not deleted
- And `GitHubClient`, `GitProviderFactory`, and `GitProviderInterface` are unwired
  from `services.yaml` but not deleted (Story 2.6)
- And analysis result metrics returned by `VcsSource` are renamed:
  `git_available` → `vcs_available`, `git_repository_url` → `vcs_source_url`,
  `git_latest_version` → `vcs_latest_version`
- And `git_repository_health` metric is removed entirely
- And risk scoring in `VersionAvailabilityAnalyzer` reads `vcs_available` (not
  `git_available`); health-weighted scoring is removed; binary VCS availability used
- And `ReportContextBuilder` extracts `vcs_*` keys instead of `git_*`
- And HTML templates: "Git" status card → "VCS"; "Repository Health" removed;
  "Latest Compatible Version" retained; column header "Git" → "VCS"
- And JSON output uses `vcs_*` field names (breaking schema change — documented)
- And all existing functional and integration tests are updated to the new metric names
- And PHPStan Level 8 reports zero errors

Note: This is the integration gate. `VersionAvailabilityAnalyzer` was made
source-agnostic in commit 1c2781e. `VcsSource` is the remaining integration point.
Old Git provider classes are unwired here and deleted in Story 2.6. Future VCS resolvers
(SVN, Mercurial, Fossil) can be added by implementing `VcsResolverInterface` and
tagging `{ name: vcs_resolver, priority: N }` — no change to `VcsSource` required.
```

---

### 4B — `architecture.md`: Update dependency sequence notes

**Line 319 — OLD:**
```
6. Integration: wire new resolvers into VersionAvailabilityAnalyzer, update metrics/templates (Story 2.5)
```

**Line 319 — NEW:**
```
6. Integration: rename GitSource → VcsSource; wire VcsResolverInterface tagged iterator
   into VcsSource (VersionAvailabilityAnalyzer already source-agnostic via commit 1c2781e);
   update metrics/templates (Story 2.5)
```

**Line 328 — OLD:**
```
- PackagistVersionResolver and GenericGitResolver replace GitRepositoryAnalyzer in VersionAvailabilityAnalyzer
```

**Line 328 — NEW:**
```
- PackagistVersionResolver and GenericGitResolver (and future VCS resolvers) are wired into
  VcsSource (renamed from GitSource) via vcs_resolver tagged iterator with priority ordering;
  VersionAvailabilityAnalyzer is source-agnostic since commit 1c2781e
```

---

## Section 5: Implementation Handoff

**Change scope classification: Minor**

All changes are story-file updates and two line replacements in `architecture.md`. No
backlog reorganization or architectural replan required.

### Handoff plan

| Role | Responsibility |
|---|---|
| SM / bmad-create-story | Create Story 2.5 file with the AC text from 4A above |
| Dev agent (Story 2.5) | Rename `GitSource` → `VcsSource`; replace constructor dep with `iterable<VcsResolverInterface>`; implement priority iteration with `shouldTryFallback()` cascade; rename metrics; update risk scoring, templates, `ReportContextBuilder`; rewire `services.yaml` with `vcs_resolver` tag |

### Success criteria

- `GitSource.php` no longer exists; `VcsSource.php` exists in its place
- `VcsSource` constructor has no import of `GitRepositoryAnalyzer`, `PackagistVersionResolver`, or `GenericGitResolver`
- `services.yaml` has `vcs_resolver` tag on both resolvers with distinct priorities
- `VcsSource` output returns `vcs_*` keys only
- PHPStan Level 8 clean, all tests green
- Adding a third `VcsResolverInterface` implementation requires only a tag in `services.yaml` — no `VcsSource` change
