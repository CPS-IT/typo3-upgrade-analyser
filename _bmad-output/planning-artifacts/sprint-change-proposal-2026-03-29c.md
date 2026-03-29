# Sprint Change Proposal — 2026-03-29 (3): Drop GenericGitResolver

## Section 1: Issue Summary

**Problem statement:** `GenericGitResolver::fetchComposerJson` uses `git archive --remote`
to read `composer.json` from a tagged ref. Real-world testing confirms that GitHub, GitLab,
Bitbucket, and virtually all hosted git services reject this command — they disable the
`git-upload-archive` protocol. The compatibility check always fails, falling back to
"treat as compatible." `GenericGitResolver` cannot fulfil its contract (verify TYPO3
compatibility at a specific tag) for any hosted repository.

Combined with the architectural insight from the overengineering review: Composer CLI
already resolves versions from all VCS types (git, SVN, Mercurial, Fossil) declared in
`composer.json` repositories. The analyzed TYPO3 installation was installed via Composer,
so every package was resolvable by Composer at install time. If `composer show` fails at
analysis time, the environment is broken — not the package. A git-based fallback adds
complexity, performance cost, and temp-directory lifecycle concerns for an edge case
(environment changed between installation and analysis).

**Discovery context:** Dirk ran the `git archive` command manually against a real GitHub
repo on 2026-03-29. Both SSH and HTTPS variants failed. Team discussion (party mode)
confirmed the limitation is fundamental, not fixable.

**Evidence:**
```
$ git archive --remote=git@github.com:dwenzel/t3extension_tools.git refs/tags/5.0.2 -- composer.json | tar -xO
Invalid command: git-upload-archive 'dwenzel/t3extension_tools.git'

$ git archive --remote=https://github.com/dwenzel/t3extension_tools.git refs/tags/5.0.2 -- composer.json | tar -xO
fatal: operation not supported by protocol
```

---

## Section 2: Impact Analysis

### Epic Impact

Epic 2 goal is unchanged ("version availability data for extensions hosted on any VCS
provider"). The implementation narrows from two-tier to single-tier resolution. Scope
reduces; no new work introduced.

### Story Impact

| Story | Status | Impact |
|---|---|---|
| 2.1 ComposerSourceParser | done | None |
| 2.2 Composer VCS Resolver | done | Rename `PackagistVersionResolver` → `ComposerVersionResolver`; remove AC 12 (`implements VcsResolverInterface` from Story 2.3) — replaced by: `ComposerVersionResolver` implements `VcsResolverInterface` (defined standalone); remove "control passes to fallback resolver (Story 2.3)" from AC |
| **2.3 GenericGitResolver** | done | **Cancelled.** Code reverted from branch. Rationale: `git archive --remote` non-functional for hosted repos; Composer CLI covers all VCS types. |
| **2.4 Unresolvable VCS Warning** | not started | **Absorbed into Story 2.5.** Core failure handling (catch, log, continue) already exists in `GitSource`. Story 2.4's distinctive ACs (Console WARNING format, `null` vs `false` metric, verbosity-independent output, stderr in CI) are merged into Story 2.5 where `VcsSource` is rebuilt. No standalone story needed. |
| **2.5 Integration** | not started | **Simplified + expanded:** `VcsSource` injects single `VcsResolverInterface` (not iterable/tagged); absorbs Story 2.4 Console WARNING ACs; prerequisite changes from "Stories 2.1–2.4" to "Stories 2.1–2.2" |
| 2.6 Legacy cleanup | not started | Scope expanded: also delete `GenericGitResolver.php`, `GenericGitResolverTest.php` (if any residual files remain) |

### Artifact Conflicts

| Artifact | Sections affected | Action |
|---|---|---|
| `architecture.md` | Lines 184 (two-tier decision), 192, 204–241 (resolution chain), 318, 328, 430, 528–532, 592–593, 651–653, 684–685, 736–737, 748, 816 | Remove all Tier 2 / GenericGitResolver references; rename PackagistVersionResolver → ComposerVersionResolver |
| `epics.md` | Epic 2 description (line 200–201), Story 2.2 (lines 420–434), Story 2.3 (lines 438–459), Story 2.4 (lines 463–479), Story 2.5 (lines 483–510) | Cancel 2.3; absorb 2.4 into 2.5; update 2.2, 2.5 |
| Story 2.2 file (`2-2-composer-vcs-resolver.md`) | AC 12, AC about fallback to Story 2.3 | Update |
| Story 2.3 file (`2-3-generic-git-resolver.md`) | Entire file | Mark cancelled |
| Sprint change proposal 2026-03-29b | Superseded | Note at top |
| Code on branch | `GenericGitResolver.php`, `GenericGitResolverTest.php`, `VcsResolverInterface.php` (keep), `PackagistVersionResolver.php` (rename) | Revert / rename |

### Technical Impact

**Files to delete:**
- `src/Infrastructure/ExternalTool/GenericGitResolver.php`
- `tests/Unit/Infrastructure/ExternalTool/GenericGitResolverTest.php`

**Files to rename:**
- `PackagistVersionResolver.php` → `ComposerVersionResolver.php`
- `PackagistVersionResolverTest.php` → `ComposerVersionResolverTest.php`

**Files to keep:**
- `VcsResolverInterface.php` — retained as the contract between `VcsSource` and its resolver
- `VcsResolutionResult.php`, `VcsResolutionStatus.php` — unchanged

**`VcsResolverInterface` usage:**
- `ComposerVersionResolver implements VcsResolverInterface`
- `VcsSource` injects `VcsResolverInterface` (single instance, not iterable)
- No tagged iterator; standard auto-wiring suffices (one implementation)

---

## Section 3: Recommended Approach

**Hybrid: Direct Adjustment + Partial Rollback**

1. Cancel Story 2.3 and revert its code from the feature branch
2. Rename `PackagistVersionResolver` → `ComposerVersionResolver` (class, file, test, services.yaml)
3. Keep `VcsResolverInterface` as the contract; `ComposerVersionResolver` implements it
4. Simplify Story 2.5: `VcsSource` injects `VcsResolverInterface` directly (not iterable, no tagged iterator, no priority)
5. Absorb Story 2.4 into Story 2.5: Console WARNING ACs, `null` vs `false` metric distinction, verbosity-independent output, stderr in CI mode — all applied when `VcsSource` is rebuilt
6. Update architecture: single-tier Composer-based resolution

**Rationale:**
- `git archive --remote` is non-functional for hosted repos — the core use case
- Composer CLI already resolves all VCS types declared in `composer.json`
- A shallow-clone workaround adds complexity and performance cost for an edge case
- Single-tier resolution is simpler to understand, test, maintain, and explain
- `VcsResolverInterface` preserves the extension point if a future use case emerges

Effort: **Low** (net code deletion) | Risk: **Low** | Timeline impact: **Positive** (two stories cancelled/absorbed, Story 2.5 simplified)

---

## Section 4: Detailed Change Proposals

### 4A — `epics.md`: Cancel Story 2.3, update Epic 2 description

**Epic 2 description — OLD (line 200–201):**
```
Developer gets version availability data for extensions hosted on any VCS provider
(GitHub, GitLab, Bitbucket, Codeberg, self-hosted, etc.), using Composer CLI as primary
resolver and generic git CLI as fallback. Replaces per-provider API clients with
provider-agnostic resolution. Unresolvable sources produce a visible warning.
Implements: `ComposerSourceParser` (AR3), `VcsResolverInterface`,
`PackagistVersionResolver`, `GenericGitResolver` (AR4). Transition: existing `GitHubClient`
remains wired until Story 2.5 validates replacement; removed in Story 2.6.
```

**Epic 2 description — NEW:**
```
Developer gets version availability data for extensions hosted on any VCS provider
(GitHub, GitLab, Bitbucket, Codeberg, self-hosted, etc.), using Composer CLI as the
sole resolver. Replaces per-provider API clients with provider-agnostic Composer-based
resolution. Unresolvable sources produce a visible warning.
Implements: `ComposerSourceParser` (AR3), `VcsResolverInterface`,
`ComposerVersionResolver` (AR4). Transition: existing `GitHubClient` remains wired until
Story 2.5 validates replacement; removed in Story 2.6.
```

---

### 4B — `epics.md`: Update Story 2.2 ACs

**Remove AC about fallback (line 425) — OLD:**
```
- And if resolution fails (network error, auth failure, Composer not installed), the
  failure is recorded per-extension and control passes to the fallback resolver (Story 2.3)
```

**NEW:**
```
- And if resolution fails (network error, auth failure, Composer not installed), the
  failure is recorded per-extension with a WARNING; analysis continues for other extensions
```

**Update AC about VcsResolverInterface (line 428) — OLD:**
```
- And `PackagistVersionResolver` implements `VcsResolverInterface` (defined in Story 2.3,
  applied via follow-up Task 5 after Story 2.3 merges)
```

**NEW:**
```
- And `ComposerVersionResolver` (renamed from `PackagistVersionResolver`) implements
  `VcsResolverInterface`
```

---

### 4C — `epics.md`: Cancel Story 2.3

**OLD (lines 438–459):**
```
### Story 2.3: Generic Git Resolver (Tier 2 Fallback)
[...full story text...]
```

**NEW:**
```
### Story 2.3: ~~Generic Git Resolver (Tier 2 Fallback)~~ — CANCELLED

**Status:** Cancelled (Sprint Change Proposal 2026-03-29c)

**Rationale:** `git archive --remote` — the mechanism for reading `composer.json` from
a tagged ref — is rejected by GitHub, GitLab, Bitbucket, and all major hosted git
services (they disable `git-upload-archive`). Without the ability to read `composer.json`,
the resolver cannot verify TYPO3 compatibility, reducing it to a tag-existence check.
Composer CLI already resolves versions for all VCS types declared in `composer.json`.
The fallback tier adds complexity and performance cost for an edge case (environment
changed between installation and analysis). Code reverted from feature branch.

**Preserved from this story:** `VcsResolverInterface` (used by `ComposerVersionResolver`
and `VcsSource`).
```

---

### 4D — `epics.md`: Absorb Story 2.4 into Story 2.5

**OLD (lines 463–479):**
```
### Story 2.4: Unresolvable VCS Source Warning
[...full story text...]
```

**NEW:**
```
### Story 2.4: ~~Unresolvable VCS Source Warning~~ — ABSORBED INTO STORY 2.5

**Status:** Absorbed into Story 2.5 (Sprint Change Proposal 2026-03-29c)

**Rationale:** Core failure handling (catch, log, continue analysis) already exists in
`GitSource` (commit 1c2781e). The distinctive ACs of this story — Console WARNING format,
`null` vs `false` metric distinction, verbosity-independent output, stderr in CI mode —
are now ACs within Story 2.5, where `VcsSource` is rebuilt from scratch. A standalone
story is not warranted since the integration point (`VersionAvailabilityAnalyzer`) named
in the original ACs is no longer the correct location; the warning logic belongs in
`VcsSource`.
```

---

### 4E — `epics.md`: Simplify Story 2.5 (absorbs Story 2.4)

**OLD (lines 483–510):** *(full text as currently written with tagged iterator, priority, GenericGitResolver)*

**NEW:**
```
### Story 2.5: VCS Resolution Integration in VcsSource, Data Model Migration,
and Template Updates

As a developer,
I want `GitSource` renamed to `VcsSource` and updated to use `VcsResolverInterface`,
So that VCS availability is resolved through Composer-based resolution, unresolvable
sources produce a visible Console warning, and reports reflect the provider-agnostic
data model.

**Acceptance Criteria:**

- **Given** Stories 2.1 and 2.2 are complete and tested
- **When** `GitSource` is refactored into `VcsSource`
- **Then** `src/Infrastructure/Analyzer/VersionAvailability/Source/GitSource.php` is
  renamed to `VcsSource.php`; the class is renamed `VcsSource`; it still implements
  `VersionSourceInterface`; `getName()` returns `'vcs'`
- **And** `VcsSource` injects `VcsResolverInterface $resolver` via constructor
  (single instance, not iterable)
- **And** `VcsSource::checkAvailability()` calls `$resolver->resolve()` and maps the
  `VcsResolutionResult` to metrics
- **And** `VersionAvailabilityAnalyzer` is NOT modified — it already iterates
  `VersionSourceInterface` via tagged iterator (done in commit 1c2781e)
- **And** `services.yaml` wires `ComposerVersionResolver` as `VcsResolverInterface`;
  `VcsSource` replaces `GitSource` with the `version_availability.source` tag
- **And** `GitRepositoryAnalyzer` is removed from `VcsSource` args but not deleted
- **And** `GitHubClient`, `GitProviderFactory`, and `GitProviderInterface` are unwired
  from `services.yaml` but not deleted (Story 2.6)
- **And** analysis result metrics returned by `VcsSource` are renamed:
  `git_available` → `vcs_available`, `git_repository_url` → `vcs_source_url`,
  `git_latest_version` → `vcs_latest_version`
- **And** `git_repository_health` metric is removed entirely
- **And** when `ComposerVersionResolver` fails for an extension, `VcsSource` records the
  VCS availability metric as `null` (not `false`) to distinguish "unknown" from "not
  available"
- **And** when resolution fails, a Console-level WARNING is written:
  `[WARNING] VCS source "{url}" could not be resolved. Ensure Composer authentication
  is configured for this URL.`
- **And** the Console WARNING appears regardless of verbosity level
- **And** in non-interactive / CI mode the warning is written to stderr
- **And** risk scoring in `VersionAvailabilityAnalyzer` reads `vcs_available` (not
  `git_available`); health-weighted scoring is removed; binary VCS availability used;
  `null` VCS metric treated as "unknown" (distinct from `false` = "checked, not available")
- **And** `ReportContextBuilder` extracts `vcs_*` keys instead of `git_*`
- **And** HTML templates: "Git" status card → "VCS"; "Repository Health" removed;
  "Latest Compatible Version" retained; column header "Git" → "VCS"
- **And** JSON output under `analyzers.version-availability` uses the new `vcs_*` field
  names (breaking schema change — documented)
- **And** all existing functional and integration tests are updated to the new metric names
- **And** unit tests cover: successful resolution (no warning), resolution failure
  (warning emitted, metric is `null`), multiple failures (one warning per source)
- **And** PHPStan Level 8 reports zero errors

**Note:** This story absorbs Story 2.4 (Unresolvable VCS Source Warning).
`VersionAvailabilityAnalyzer` was made source-agnostic in commit 1c2781e. `VcsSource` is
the remaining integration point. Old Git provider classes are unwired here and deleted in
Story 2.6. If a second `VcsResolverInterface` implementation is needed in the future,
`VcsSource` can be changed to accept `iterable<VcsResolverInterface>` with minimal effort.
```

---

### 4F — `architecture.md`: Replace two-tier decision with single-tier

**Lines 184 — OLD:**
```
**Decision:** `composer.json`/`composer.lock` in the analyzed installation is the source
of truth for extension origins. Version resolution uses a two-tier strategy: Composer CLI
as primary resolver, generic git CLI as fallback. No per-provider API clients. No
URL-sniffing heuristics.
```

**NEW:**
```
**Decision:** `composer.json`/`composer.lock` in the analyzed installation is the source
of truth for extension origins. Version resolution uses Composer CLI as the sole resolver
(`ComposerVersionResolver` via `VcsResolverInterface`). No per-provider API clients. No
URL-sniffing heuristics. No git CLI fallback — Composer already handles all VCS types
(git, SVN, Mercurial, Fossil) natively.
```

**Line 192 — OLD:**
```
`composer` is required because Tier 1 and the pre-filter depend on it; Tier 2 does not
use it but is never intended to operate as a standalone resolution path.
```

**NEW:**
```
`composer` is required because the version resolver and the pre-filter depend on it.
```

**Lines 204–241 (Tier 1 + Tier 2 sections + capability table):** Replace entire Tier 1/Tier 2 structure with:

```
3. **Composer CLI resolution** (`ComposerVersionResolver`):
   - Command: `composer show --all --format=json vendor/package` (no `--working-dir`) —
     resolves any package Composer can see: Packagist-indexed, VCS-sourced, or private
     (~315ms/pkg; ~400ms with Xdebug active)
   - **Two-call strategy per package:** first call (no version) returns full version list
     + `requires` for the latest version. If latest is not compatible with the target
     TYPO3 version, use binary search on the version list with versioned calls
     (`composer show --all --format=json vendor/package X.Y.Z`) to find the newest
     compatible version (~315ms per additional call)
   - `--working-dir` is NOT used — confirmed to add 11–13s overhead per call
   - Authentication: Composer uses `auth.json` and SSH agent automatically. No
     tool-specific tokens.
   - **When this fails:** network error, auth failure → emit Console WARNING, record
     `null`, analysis continues for remaining extensions

4. **No git CLI fallback tier.** The original two-tier design included
   `GenericGitResolver` using `git ls-remote` + `git archive --remote`. This was
   cancelled (Sprint Change Proposal 2026-03-29c) because `git archive --remote` is
   rejected by all major hosted git services (GitHub, GitLab, Bitbucket disable
   `git-upload-archive`). Without `composer.json` access, the resolver cannot verify
   TYPO3 compatibility. Since the analyzed installation was installed via Composer, all
   packages were resolvable at install time. Environment drift between install and
   analysis is the only scenario where Composer fails — a rare edge case that does not
   justify the complexity.

**Legacy installations (v11 non-Composer):** Extensions in `typo3conf/ext/` have no
composer provenance. Version availability falls back to TER + key-based lookup only.
```

**Line 318 — OLD:**
```
5. ComposerSourceParser + PackagistVersionResolver + GenericGitResolver (Stories 2.1–2.3)
```

**NEW:**
```
5. ComposerSourceParser + ComposerVersionResolver (Stories 2.1–2.2; Story 2.3 cancelled)
```

**Line 328 — OLD:**
```
- PackagistVersionResolver and GenericGitResolver (and future VCS resolvers) are wired
  into VcsSource (renamed from GitSource) via vcs_resolver tagged iterator with priority
  ordering; VersionAvailabilityAnalyzer is source-agnostic since commit 1c2781e
```

**NEW:**
```
- ComposerVersionResolver is wired into VcsSource (renamed from GitSource) via
  VcsResolverInterface; VersionAvailabilityAnalyzer is source-agnostic since commit 1c2781e
```

**Line 430 — OLD:**
```
Resolver classes (`PackagistVersionResolver`, `GenericGitResolver`) have no
tool-checking responsibility.
```

**NEW:**
```
`ComposerVersionResolver` has no tool-checking responsibility.
```

**Lines 528–532 — OLD:**
```
1. Pass `DeclaredRepository` to `PackagistVersionResolver` (Tier 1 — Composer CLI)
2. On failure, pass to `GenericGitResolver` (Tier 2 — `git ls-remote`)
3. On failure of both tiers, emit Console WARNING and record `null`

Both `PackagistVersionResolver` and `GenericGitResolver` implement
`VcsResolverInterface`. The orchestrator (`VersionAvailabilityAnalyzer`) depends on
`VcsResolverInterface`, not concrete classes. DI wiring uses named service arguments
(not auto-wiring, as two implementations exist for the same interface).
```

**NEW:**
```
1. Pass `DeclaredRepository` to `ComposerVersionResolver` (via `VcsResolverInterface`)
2. On failure, emit Console WARNING and record `null`

`ComposerVersionResolver` implements `VcsResolverInterface`. `VcsSource` depends on the
interface, not the concrete class.
```

**Lines 592–593 — OLD:**
```
- `PackagistVersionResolver`: successful resolution, failure fallthrough to Tier 2
- `GenericGitResolver`: successful tag listing, tag-to-version parsing, network failure, SSH URL handling
```

**NEW:**
```
- `ComposerVersionResolver`: successful resolution, no compatible version, network
  failure, auth failure, Composer not installed
```

**Lines 651–653 — OLD:**
```
│   │   ├── VcsResolverInterface.php           # NEW — shared contract for Tier 1 and Tier 2 resolvers
│   │   ├── PackagistVersionResolver.php       # NEW — Tier 1: resolves versions via Composer CLI (Packagist)
│   │   ├── GenericGitResolver.php             # NEW — Tier 2: resolves versions via git ls-remote
```

**NEW:**
```
│   │   ├── VcsResolverInterface.php           # NEW — contract for VCS version resolution
│   │   ├── ComposerVersionResolver.php        # NEW — resolves versions via Composer CLI
```

**Lines 684–685 — OLD:**
```
│       │   ├── PackagistVersionResolverTest.php
│       │   └── GenericGitResolverTest.php
```

**NEW:**
```
│       │   └── ComposerVersionResolverTest.php
```

**Lines 736–737 — OLD:**
```
| VCS repositories (Tier 1) | `PackagistVersionResolver` | None (Packagist is public)         | Network/auth failure → fall through to Tier 2     |
| VCS repositories (Tier 2) | `GenericGitResolver`       | SSH agent / git credential helpers | Log warning, null result                          |
```

**NEW:**
```
| VCS repositories           | `ComposerVersionResolver`  | `auth.json` / SSH agent (Composer) | Log warning, null result                          |
```

**Line 748 — OLD:**
```
| FR9–FR11, FR14–FR15 Version Availability | `Infrastructure/ExternalTool/` — `TerApiClient`, `PackagistClient`, `PackagistVersionResolver`, `GenericGitResolver` (FR12/FR13 merged into FR11) |
```

**NEW:**
```
| FR9–FR11, FR14–FR15 Version Availability | `Infrastructure/ExternalTool/` — `TerApiClient`, `PackagistClient`, `ComposerVersionResolver` (FR12/FR13 merged into FR11) |
```

**Line 816 — OLD:**
```
| FR11 VCS availability (all providers) | `PackagistVersionResolver` (Tier 1) + `GenericGitResolver` (Tier 2) — replaces per-provider clients; covers GitHub, GitLab, Bitbucket, Gitea, self-hosted |
```

**NEW:**
```
| FR11 VCS availability (all providers) | `ComposerVersionResolver` — replaces per-provider clients; covers all VCS types Composer supports (git, SVN, Mercurial, Fossil) |
```

---

### 4G — Story 2.3 implementation file: mark cancelled

**`_bmad-output/implementation-artifacts/2-3-generic-git-resolver.md` — change Status line:**

OLD: `Status: done`

NEW: `Status: cancelled (Sprint Change Proposal 2026-03-29c — git archive --remote non-functional for hosted git services; Composer CLI covers all VCS types)`

---

### 4H — Code changes on feature branch

1. Delete `src/Infrastructure/ExternalTool/GenericGitResolver.php`
2. Delete `tests/Unit/Infrastructure/ExternalTool/GenericGitResolverTest.php`
3. Rename `PackagistVersionResolver.php` → `ComposerVersionResolver.php` (class name, file, namespace references)
4. Rename `PackagistVersionResolverTest.php` → `ComposerVersionResolverTest.php`
5. Keep `VcsResolverInterface.php`; ensure `ComposerVersionResolver implements VcsResolverInterface`
6. Update any imports referencing the old class name

---

## Section 5: Implementation Handoff

**Change scope classification: Moderate**

Cancels a completed story, requires code revert/rename, and updates 12+ sections across
two planning artifacts. No epic resequencing or PRD changes.

### Handoff plan

| Role | Responsibility |
|---|---|
| Dev (immediate) | Delete GenericGitResolver + test; rename PackagistVersionResolver → ComposerVersionResolver; ensure VcsResolverInterface is retained; run tests + PHPStan |
| SM / doc update | Apply all epics.md and architecture.md changes from Section 4 |
| SM / story file | Mark Story 2.3 file as cancelled; update Story 2.2 file |
| Dev (Story 2.5) | Implement simplified VcsSource with single VcsResolverInterface injection + Console WARNING for unresolvable sources (absorbed from Story 2.4) |

### Success criteria

- `GenericGitResolver.php` and its test file do not exist
- `ComposerVersionResolver.php` exists and implements `VcsResolverInterface`
- `VcsResolverInterface.php` exists with `resolve()` method
- No reference to `GenericGitResolver` or `PackagistVersionResolver` in any source file
- No reference to "two-tier" or "Tier 2" in architecture.md
- PHPStan Level 8 clean, all tests green
