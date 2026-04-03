# Sprint Change Proposal — 2026-04-03b

## 1. Issue Summary

**Trigger:** Adversarial review of Story 2-5a (VCS Detection Fix) surfaced 12 findings across the implementation: one confirmed bug (template condition never matches), two security concerns (unsanitized shell arguments), and nine medium-severity design, correctness, and coverage issues.

**Discovery context:** Dirk performed an adversarial review (`_bmad-output/reviews/feature-2-5a-adversarial-review.md`) on commits 0a9db9d..8f0ef97 (7 commits, ~2400 lines added, ~640 removed) on branch `feature/2-5-integrate-resolvers-and-update-data-model`. No code review has been performed yet — additional findings may surface during code review.

**Evidence:**

- F5 (High): `detailed-report.html.twig:228` uses `is same as(true)` — a strict boolean check — against a string value (`'available'`). The VCS column always shows the wrong indicator. Confirmed bug.
- F7 (Medium-High): `ComposerVersionResolver::isSshHostReachable()` passes unsanitized hostnames from `composer.json` repository entries to `ssh -T`. A crafted hostname could cause connections to arbitrary SSH servers.
- F12 (Medium-High): `AnalysisContext::installationPath` is passed directly to `--working-dir=` shell argument without `realpath()` or existence validation.
- F1, F3, F4, F6, F8, F9, F10, F11 (Medium): UX naming, documentation gap (F3 resolved — file exists on `main`), config migration, enum naming, unbounded iteration, correctness conflation, enum dual representation, missing integration test.
- F2 (Low-Medium): Pre-1.0 `github → vcs` mapping baggage.

**Impact:** Story 2-5a cannot merge. Story 2-5 remains blocked on 2-5a. Epic 2 completion delayed until rework is done.

## 2. Impact Analysis

### Epic Impact

| Epic | Impact | Details |
|------|--------|---------|
| Epic 2 | Direct | Story 2-5a requires rework before merge. Story 2-5 remains blocked. Story 2-6 absorbs F2 (github mapping removal). |
| Epic 2 Stories 2-6, 2-7 | Minimal | 2-6 absorbs F2. No other impact. |
| Epics 3–6 | None | No dependency on VCS detection internals. |

### Artifact Conflicts

| Artifact | Impact | Details |
|----------|--------|---------|
| PRD | None | Findings are implementation defects, not requirement gaps. |
| Architecture | Action needed | AR4 references stale two-tier architecture and states `--working-dir` is never used. Must be updated. |
| Epics doc | None | No story additions or removals. |
| Sprint status | None | 2-5a stays in review status, re-enters in-progress during rework. |
| Scoring docs | None | File exists on `main` — branch divergence resolved by merging `main`. |

## 3. Recommended Approach

**Selected path:** Direct Adjustment — rework Story 2-5a on the same branch.

### Rationale

- All findings are defects in 2-5a's own implementation, not new requirements or architectural issues.
- No new stories needed. The rework is a normal review-rework cycle.
- No rollback, no MVP scope reduction, no epic resequencing.
- F2 (github mapping) defers naturally to Story 2-6 (Legacy Git Provider Cleanup).
- F3 (branch divergence) resolved by merging `main` into the feature branch before starting rework.

### Effort and Risk

- **Effort:** Medium (12 localized fixes across templates, resolver, enum, tests, and architecture doc)
- **Risk:** Low (all changes are additive or corrective; existing Packagist-based resolution unaffected)
- **Timeline impact:** Rework cycle before 2-5a can pass review. No new stories added to the sequence.

## 4. Detailed Change Proposals

### Pre-rework: Merge `main` into feature branch

**Action:** `git merge main` into `feature/2-5-integrate-resolvers-and-update-data-model`
**Rationale:** Closes branch divergence. Scoring documentation and any other `main` changes incorporated before rework begins.

---

### CP-1: Fix template bug — string comparison for VCS availability (F5)

**Story:** 2-5a rework
**Files:** All Twig templates (HTML, Markdown) that check `vcs_available`

OLD:
```twig
data.version_analysis.vcs_available is same as(true)
```

NEW:
```twig
data.version_analysis.vcs_available == 'available'
```

Same pattern for `'unavailable'` and `'unknown'` branches across all templates.

**Rationale:** `VersionAvailabilityDataProvider` serializes to string values. Strict boolean check never matches. `==` is idiomatic Twig for string equality.

---

### CP-2: Sanitize SSH hostnames before shell execution (F7)

**Story:** 2-5a rework
**File:** `src/Infrastructure/ExternalTool/ComposerVersionResolver.php` — `isSshHostReachable()`

NEW:
- After extracting host from SSH URL, validate against RFC 952/1123 pattern: `/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/`
- Reject any host failing validation with a warning log; treat as unreachable
- Pass validated host as separate array element to `Process` (never string-interpolated)

**Rationale:** Host extracted from user-provided `composer.json` repository URLs. Validation is defense-in-depth alongside array-based `Process` construction.

---

### CP-3: Sanitize `installationPath` before shell argument use (F12)

**Story:** 2-5a rework
**File:** `src/Infrastructure/ExternalTool/ComposerVersionResolver.php` — `resolve()`

NEW:
- `realpath()` to resolve symlinks and normalize
- `is_dir()` to verify the resolved path exists and is a directory
- Pass as separate array element to `Process` (never string-interpolated)

**Rationale:** `installationPath` originates from user CLI input. Plain PHP validation — no service abstraction needed for a guard clause.

---

### CP-4: Rename `SourceAvailability` to `VcsAvailability` (F6)

**Story:** 2-5a rework
**Files:** Domain enum file, all imports and type hints across Infrastructure and Application layers

OLD:
```php
enum SourceAvailability: string
```

NEW:
```php
enum VcsAvailability: string
```

Template variable names (`vcs_available`) and serialization keys unchanged.

**Rationale:** TER and Packagist use plain booleans. Only VCS uses the tri-state enum. Name should be honest about scope.

---

### CP-5: Replace "VCS" with "Git" in user-facing templates (F1)

**Story:** 2-5a rework
**Files:** All Twig templates displaying VCS status to end users

OLD: `VCS Repository`, `VCS ✓`, `VCS ?`
NEW: `Git Repository`, `Git ✓`, `Git ?`

Internal code naming (`VcsSource`, `VcsAvailability`, `vcs_available` keys) unchanged. JSON output key `vcs_available` unchanged.

**Rationale:** TYPO3 developers think "Git", not "VCS". Internal abstraction is correct; leaking it into the UI is not.

---

### CP-6: Keep `git` as configuration value (F4)

**Story:** 2-5a rework
**Decision:** `git` remains the accepted config source name. No deprecation of `git`, no introduction of `vcs` as a config value.

Internal code uses `VcsSource`/`VcsAvailability` (modeling Composer's VCS repository concept). Config values reflect tested capabilities: `git` today, `svn`/`mercurial` added as separate values if/when tested.

No deprecation warning needed. No migration path needed.

**Rationale:** Config values are a user contract. `vcs` over-promises support for untested VCS types. `git` is honest.

---

### CP-7: Fix NOT_FOUND vs FAILURE conflation (F9)

**Story:** 2-5a rework
**File:** `src/Infrastructure/Analyzer/VcsSource.php`

OLD: Both `VcsResolutionStatus::NOT_FOUND` and `FAILURE` → `VcsAvailability::Unknown`
NEW:
- `NOT_FOUND` → `VcsAvailability::Unavailable` (definitive answer)
- `FAILURE` → `VcsAvailability::Unknown` (system couldn't determine status)

**Rationale:** NOT_FOUND is a definitive answer from Composer. `Unknown` reserved for genuine indeterminate states (SSH failure, timeout, crash). The distinction affects risk scoring — `Unavailable` is higher risk than `Unknown`.

---

### CP-8: Use `VcsAvailability` enum consistently through reporting chain (F10)

**Story:** 2-5a rework
**Files:** `ReportContextBuilder`, `VersionAvailabilityDataProvider`

OLD: Enum serialized to string early; `ReportContextBuilder` compares `VcsAvailability::Available->value === $vcsAvailable`
NEW: Keep enum object through internal code. Serialize to string only at template data boundary.

**Rationale:** Dual representation (enum and string) invites bugs when someone passes the wrong form. Single serialization point at the template boundary.

---

### CP-9: Add integration test for `--working-dir` fallback (F11)

**Story:** 2-5a rework
**File:** New test in `tests/Integration/Infrastructure/ExternalTool/`

- Integration test using fixture-based Composer project with a non-Packagist VCS package
- Exercises full path: `VcsSource` → `ComposerVersionResolver` → `--working-dir` fallback
- Asserts non-Packagist package resolves successfully from fixture
- Gated by environment variable (requires real Composer installation)

**Rationale:** The `--working-dir` fallback is the core fix of 2-5a. Story ACs require an integration test. Unit tests with mocked processes cannot catch argument ordering or output format issues.

---

### CP-10: Add iteration cap to linear version scan (F8)

**Story:** 2-5a rework
**File:** `src/Infrastructure/ExternalTool/ComposerVersionResolver.php` — `linearScan`

NEW:
```php
private const MAX_LINEAR_SCAN_VERSIONS = 50;
// After reaching cap: stop, log warning, return best result found so far
```

**Rationale:** Guard against packages with hundreds of versions spawning hundreds of subprocesses. 50 covers all realistic TYPO3 extension scenarios. Proper optimization (binary search, batch query) deferred.

---

### CP-11: Defer `github` → `vcs` mapping removal to Story 2-6 (F2)

**Story:** 2-6 (Legacy Git Provider Cleanup)
**Files:** `VersionAvailabilityAnalyzer.php` (lines ~79, ~121), templates

Mapping becomes `'github' === $source ? 'git' : $source` or is dropped entirely during 2-6 cleanup. Fits naturally into 2-6's scope.

No action in 2-5a rework.

---

### CP-12: Update Architecture document AR4

**Story:** 2-5a rework
**File:** `_bmad-output/planning-artifacts/architecture.md`

OLD:
```
AR4: Two-tier VCS resolution — PackagistVersionResolver (Tier 1) + GenericGitResolver (Tier 2).
Note: --working-dir is never used (11–13 s overhead per call).
```

NEW:
```
AR4: Single-tier VCS resolution — ComposerVersionResolver implements VcsResolverInterface.
Primary: composer show --all (Packagist). Fallback for non-Packagist packages:
composer show --working-dir=$installationPath (11–13s overhead, acceptable for non-Packagist
packages only). GenericGitResolver cancelled (sprint change proposal 2026-03-29c).
Input sanitization: installationPath validated via realpath()+is_dir(); SSH hostnames validated
against RFC 952/1123 pattern before shell execution.
```

---

## 5. Implementation Handoff

### Change Scope: Minor

Direct implementation by development team. No backlog reorganization, no architectural replan.

### Handoff

| Role | Responsibility |
|------|---------------|
| Dev (bmad-dev) | Merge `main` into feature branch, then rework 2-5a (CP-1 through CP-10, CP-12) |
| Dev (bmad-dev) | After rework: run full test suite + PHPStan + linter, submit for code review |
| SM (bmad-sm) | No sprint status changes needed — 2-5a remains in existing sequence |
| Dev (bmad-dev) | Absorb F2 into Story 2-6 when that story is created |

### Success Criteria

1. All Twig templates show correct VCS availability indicators (CP-1 verified by functional test or manual check)
2. SSH hostnames validated before shell execution; invalid hosts logged and treated as unreachable (CP-2)
3. `installationPath` validated via `realpath()` + `is_dir()` before any `Process` call (CP-3)
4. `SourceAvailability` renamed to `VcsAvailability` throughout codebase (CP-4)
5. User-facing templates show "Git", not "VCS" (CP-5)
6. `git` remains accepted config source name, no deprecation (CP-6)
7. NOT_FOUND maps to Unavailable, FAILURE maps to Unknown (CP-7)
8. `VcsAvailability` enum stays as object until template serialization boundary (CP-8)
9. Integration test exercises `--working-dir` fallback with fixture (CP-9)
10. Linear scan capped at 50 versions (CP-10)
11. AR4 updated to reflect single-tier architecture with sanitization requirements (CP-12)
12. All existing tests pass (no regression)
13. PHPStan Level 8: 0 errors; `composer lint:php`: 0 issues

### Sequence

```
Current state:  2-5a in review, adversarial findings pending
                 |
Merge main:     git merge main (resolve branch divergence)
                 |
Rework 2-5a:    CP-1 through CP-10, CP-12 (on same feature branch)
                 |
Code review:    Run code review (not yet done — may surface additional items)
                 |
Re-verify:      Full test suite + manual verification
                 |
Merge 2-5:      PR to develop
                 |
Story 2-6:      Legacy Git Provider Cleanup (absorbs F2)
```
