# Sprint Change Proposal — 2026-03-29

## Section 1: Issue Summary

**Problem statement:** Both `PackagistVersionResolver` (Story 2.2, done) and `GenericGitResolver` (Story 2.3, review) expose an identical method signature:

```
resolve(string $packageName, string $vcsUrl, Version $targetVersion): VcsResolutionResult
```

No shared interface contract exists. Without one, Story 2.5's orchestrator (`VersionAvailabilityAnalyzer`) must depend on concrete resolver classes — coupling integration wiring to implementation detail and making the orchestrator harder to test in isolation.

**Discovery context:** The absence of an interface was filed as code-review finding `#6` during Story 2.3's review cycle (2026-03-29). It was explicitly deferred as a "spec decision or Story 2.5 scope." The user has now elected to schedule it before Story 2.3 merges — the lowest-cost insertion point.

**Evidence:** Both classes share the identical `resolve()` signature. `GenericGitResolver` is still in review status and not yet merged — the interface can be introduced without any retroactive code conflict.

---

## Section 2: Impact Analysis

### Epic Impact

Epic 2 (Complete Extension Source Coverage — All VCS Providers) is unaffected in scope or goal. The interface is additive; no epic-level resequencing is required.

### Story Impact

| Story | Status | Impact |
|-------|--------|--------|
| 2.2 `PackagistVersionResolver` | done | Retroactive: add `implements VcsResolverInterface` (one-line code change, gated on Story 2.3 merge) |
| 2.3 `GenericGitResolver` | review | Primary: create interface + add `implements` before merge |
| 2.4 Unresolvable VCS Warning | not started | None |
| 2.5 VCS Resolution Integration | not started | AC updated: orchestrator depends on `VcsResolverInterface`, not concrete classes; DI wiring note added |
| 2.6–2.7 | not started | None |

### Artifact Conflicts

| Artifact | Conflict | Action |
|----------|----------|--------|
| PRD | None | No change |
| Architecture (AR4) | Missing interface mention | Update AR4 |
| `epics.md` | Stories 2.2/2.3 summaries missing interface AC | Update both |
| Story 2.2 file | Missing Task 5 + AC 12 | Add follow-up task |
| Story 2.3 file | Missing Task 0 + AC 10–11 | Add before merge |
| Story 2.5 file | Orchestrator AC references concrete classes | Update AC |
| `config/services.yaml` | Future impact (Story 2.5 scope) | Note added to Story 2.5 AC |

### Technical Impact

- `VcsResolverInterface`: new file, no logic, one method signature
- `PackagistVersionResolver`: one-line `implements` addition, no behavioral change
- `GenericGitResolver`: one-line `implements` addition, no behavioral change
- No test changes required for existing tests; PHPStan Level 8 will verify conformance

---

## Section 3: Recommended Approach

**Option 1 — Direct Adjustment** (selected)

Introduce the interface as part of completing Story 2.3 (currently in review). Apply the retroactive `implements` to Story 2.2 as a dependency-gated follow-up task. Update Story 2.5 and architecture before that story starts.

**Rationale:**
- Story 2.3 is not yet merged — zero retroactive conflict
- Effort is minimal: one new interface file, two `implements` declarations
- Risk is low: purely additive, no logic change, existing tests remain valid
- Story 2.5 benefits directly — the orchestrator is written against the abstraction from the start
- Resolves deferred review finding #6 at the correct moment

Effort: **Low** | Risk: **Low** | Timeline impact: **None**

---

## Section 4: Detailed Change Proposals

### 4A — Story 2.3: Add `VcsResolverInterface` creation + `implements` clause

**Section:** Acceptance Criteria + Tasks

OLD AC 10:
```
10. Unit tests cover all return status variants [...]. PHPStan Level 8 reports zero errors.
```

NEW AC 10–12:
```
10. A `VcsResolverInterface` is created in `src/Infrastructure/ExternalTool/VcsResolverInterface.php`
    with a single method:
      resolve(string $packageName, string $vcsUrl, Version $targetVersion): VcsResolutionResult
    The interface carries no other methods and has no dependency on any framework type.

11. `GenericGitResolver` declares `implements VcsResolverInterface`.

12. [former 10] Unit tests cover all return status variants [...]. PHPStan Level 8 reports zero errors.
```

NEW Task 0 (insert before Task 1):
```
- [ ] Task 0: Create `VcsResolverInterface` (AC: 10–11)
  - [ ] Create `src/Infrastructure/ExternalTool/VcsResolverInterface.php`
  - [ ] Add `implements VcsResolverInterface` to `GenericGitResolver`
```

---

### 4B — Story 2.2: Retroactive amendment

**Section:** Acceptance Criteria + Tasks + File List

NEW AC 12 (insert before former AC 12, renumber PHPStan line to 13):
```
12. `PackagistVersionResolver` declares `implements VcsResolverInterface` (defined in Story 2.3).
13. PHPStan Level 8 reports zero errors.
```

NEW Task 5 (append after Task 4):
```
- [ ] Task 5: Implement `VcsResolverInterface` (AC: 12) — depends on Story 2.3 merge
  - [ ] Add `implements VcsResolverInterface` to `PackagistVersionResolver`
  - [ ] Run `composer sca:php` and `composer test` — zero errors
```

File List addition:
```
- src/Infrastructure/ExternalTool/VcsResolverInterface.php (added in Story 2.3, used here)
```

---

### 4C — Story 2.5: Orchestrator depends on `VcsResolverInterface`

**Section:** Acceptance Criteria

OLD:
```
- Then it delegates VCS resolution to `PackagistVersionResolver` → `GenericGitResolver`
  instead of `GitRepositoryAnalyzer`
```

NEW:
```
- Then it delegates VCS resolution through a `VcsResolverInterface` chain:
  `PackagistVersionResolver` (Tier 1) → `GenericGitResolver` (Tier 2),
  injected via constructor as `VcsResolverInterface $tier1Resolver` and
  `VcsResolverInterface $tier2Resolver` (or equivalent named services).
  `VersionAvailabilityAnalyzer` must not import concrete resolver class names.
```

Additional AC (insert before PHPStan line):
```
- And `config/services.yaml` wires `PackagistVersionResolver` and `GenericGitResolver`
  under the `VcsResolverInterface` contract using named service arguments or explicit
  binding — not interface auto-wiring (two implementations exist)
```

---

### 4D — Architecture: Update AR4

OLD AR4:
```
AR4: Two-tier VCS resolution — `PackagistVersionResolver` (Tier 1: Composer CLI via
`--working-dir`) + `GenericGitResolver` (Tier 2: `git ls-remote`); replaces per-provider
API clients; auth via Composer `auth.json` / SSH agent; no tool-specific tokens
```

NEW AR4:
```
AR4: Two-tier VCS resolution — `VcsResolverInterface` is the shared contract for both
tiers. `PackagistVersionResolver` (Tier 1: Composer CLI) + `GenericGitResolver`
(Tier 2: `git ls-remote`) both implement `VcsResolverInterface`. The orchestrator
(`VersionAvailabilityAnalyzer`, Story 2.5) depends on the interface, not concrete
classes. Auth via Composer `auth.json` / SSH agent; no tool-specific tokens.
Note: `--working-dir` is never used (11–13 s overhead per call, VcsResolutionSpike §8).
```

---

### 4E — Epics: Update Story 2.2 and 2.3 summaries

**Story 2.2 AC list — append:**
```
- And `PackagistVersionResolver` implements `VcsResolverInterface` (defined in Story 2.3,
  applied via follow-up Task 5 after Story 2.3 merges)
```

**Story 2.3 AC list — append:**
```
- And `VcsResolverInterface` is created in `Infrastructure/ExternalTool/VcsResolverInterface.php`
  with method `resolve(string $packageName, string $vcsUrl, Version $targetVersion): VcsResolutionResult`
- And `GenericGitResolver` implements `VcsResolverInterface`
```

---

## Section 5: Implementation Handoff

**Change scope classification: Minor**

All changes are story-file edits and a single new interface file. No backlog reorganization or architectural replan required.

### Handoff plan

| Role | Responsibility |
|------|---------------|
| Dev agent (Story 2.3) | Create `VcsResolverInterface`, add `implements` to `GenericGitResolver`, complete Task 0 before merge |
| Dev agent (Story 2.2 follow-up) | Add `implements VcsResolverInterface` to `PackagistVersionResolver` after Story 2.3 merges (Task 5) |
| Dev agent (Story 2.5) | Inject `VcsResolverInterface` into orchestrator constructor; wire named services in `services.yaml` |

### Success criteria

- `src/Infrastructure/ExternalTool/VcsResolverInterface.php` exists with one method
- `PackagistVersionResolver implements VcsResolverInterface` — PHPStan Level 8 clean
- `GenericGitResolver implements VcsResolverInterface` — PHPStan Level 8 clean
- `VersionAvailabilityAnalyzer` (Story 2.5) has no import of concrete resolver class names
- All existing tests continue to pass without modification
