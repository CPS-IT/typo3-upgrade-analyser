# Sprint Change Proposal: Replace Git Provider Architecture with Composer-Based VCS Resolution

**Date:** 2026-03-26 (revised 2026-03-27)
**Author:** Dirk Wenzel + Claude (Correct Course workflow)
**Status:** Pending approval
**Change Scope:** Moderate
**Triggered by:** [Review: Git Provider Architecture Over-Engineering](review-git-provider-overengineering.md)

---

## 1. Issue Summary

The `GitProvider` subsystem — `GitProviderInterface`, `AbstractGitProvider`, `GitHubClient`, `GitProviderFactory`, `GitRepositoryHealth`, and the planned `GitLabProvider`/`BitbucketProvider` — reimplements functionality that Composer and git CLI already provide. This was identified through an adversarial review triggered by Simon Schaufelberger's [PR #191 comment](https://github.com/CPS-IT/typo3-upgrade-analyser/pull/191#issuecomment-4130333859) questioning why per-provider identification matters when the only question is "does a compatible version exist?"

The per-provider approach inflates scope (each provider requires a full API client with auth, pagination, rate-limiting), creates ongoing maintenance burden for API changes, duplicates Composer's authentication mechanisms, and introduces provider-specific vanity metrics (stars, forks, issues) that do not improve risk scoring. Additional providers like Codeberg further demonstrate the futility of maintaining per-provider clients.

**Core problem type:** Failed approach requiring different solution.

**Evidence:** 10 points documented in the review document. Key facts:

- `GitHubClient.php` is 383 LOC of GitHub-specific complexity (GraphQL queries, REST calls, branch guessing, pagination header parsing)
- Composer already resolves VCS sources generically across all providers
- Authentication is duplicated: tool-specific tokens (`GITHUB_TOKEN`, `GITLAB_TOKEN`) vs Composer's `auth.json` / SSH agent
- `GitRepositoryHealth` tracks GitHub vanity metrics irrelevant to version availability
- FR11/FR12/FR13 are artificially split by hosting provider
- Provider resolution order in `GitProviderFactory` duplicates Composer's repository resolution

---

## 2. Impact Analysis

### Epic Impact

| Epic       | Impact                                                                                                                                                                                                                                                            |
|------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Epic 2** | Major rewrite. 3 stories cancelled, 2 rewritten, 4 new stories added. Net: 4 stories → 7 (including research spike). Transition path preserves existing GitHub resolution until replacement is validated and integrated (Story 2.5), then cleaned up (Story 2.6). |
| Epic 1     | No impact. `VersionProfileRegistry` and v11 bug fix are independent.                                                                                                                                                                                              |
| Epic 3     | No impact. Streaming infrastructure is independent.                                                                                                                                                                                                               |
| Epic 4     | No impact. Customer report templates are independent.                                                                                                                                                                                                             |
| Epic 5     | No impact. JSON schema namespace `analyzers.version-availability` unchanged; data source changes transparently.                                                                                                                                                   |
| Epic 6     | No impact. PHPStan analyzer and `CachingAnalyzerDecorator` are independent.                                                                                                                                                                                       |

### Story Impact

| Story                              | Current Status | Action                                                                                                                                    |
|------------------------------------|----------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| P2-3 (GitLab/Bitbucket spike)      | ready-for-dev  | **Cancel.** Provider-type fixture identification unnecessary.                                                                             |
| 2.1 (ComposerSourceParser)         | backlog        | **Rewrite.** Scope reduces: extract VCS URLs, no provider-type classification.                                                            |
| 2.2 (GitLabProvider)               | backlog        | **Cancel.** Replaced by Composer-based and generic git resolution.                                                                        |
| 2.3 (BitbucketProvider)            | backlog        | **Cancel.** Replaced by Composer-based and generic git resolution.                                                                        |
| 2.4 (Unmatched source warning)     | backlog        | **Rewrite → 2.4.** Warning mechanism valid; remedy changes from "configure a provider" to "check Composer auth."                          |
| NEW 2.0 (Research spike)           | —              | **New.** Evaluate Composer CLI, git CLI, Composer PHP API before implementation.                                                          |
| NEW 2.2 (PackagistVersionResolver) | —              | **New.** Composer CLI version resolution (Tier 1).                                                                                        |
| NEW 2.3 (GenericGitResolver)       | —              | **New.** Generic git CLI fallback (Tier 2) replacing all provider-specific clients.                                                       |
| NEW 2.5 (Integration + data model) | —              | **New.** Wire new resolvers into analyzer, update metrics (`git_*` → `vcs_*`), update 10 templates, update risk scoring. Transition gate. |
| NEW 2.6 (Remove GitProvider)       | —              | **New.** Delete old code after 2.5 is validated. Pure cleanup.                                                                            |

### Artifact Conflicts

| Artifact                                | Sections Affected                                                                                               |
|-----------------------------------------|-----------------------------------------------------------------------------------------------------------------|
| PRD                                     | FR11/FR12/FR13 consolidation; FR15 simplification; MVP Feature #3 description                                   |
| Architecture                            | Git Source Detection Pattern; External integration boundaries table; Project Structure; Implementation Patterns |
| Epics                                   | Epic 2 summary and all stories                                                                                  |
| `VersionAvailabilityAnalyzer.php`       | Dependencies, metrics, risk scoring, recommendation logic                                                       |
| `ReportContextBuilder.php`              | Metric extraction (lines 202–206), availability stats (lines 108–129)                                           |
| 10 report templates (HTML + MD)         | Column headers, metric variable names, health display removal                                                   |
| JSON output schema                      | Field renames (`git_*` → `vcs_*`), field removal (`git_repository_health`) — **breaking change**                |
| `composer-source-parser-design-note.md` | Sections 6 (DeclaredRepository type field) and 7 (provider resolution order)                                    |
| `GitRepositoryVersionSupport.md`        | Superseded by new approach                                                                                      |
| `config/services.yaml`                  | GitProvider service wiring (6 lines)                                                                            |

### Data Structure and View Impact

The current system stores and renders availability as three separate boolean columns: TER, Packagist, Git (with Git meaning "GitHub specifically"). The new system changes the semantics of the "Git" column: it becomes "available in any VCS source" — provider-agnostic.

**Metrics affected in `VersionAvailabilityAnalyzer`:**

| Current Metric                  | Status               | Notes                                                                              |
|---------------------------------|----------------------|------------------------------------------------------------------------------------|
| `ter_available` (bool)          | **Unchanged**        | TER check is independent                                                           |
| `packagist_available` (bool)    | **Unchanged**        | Packagist check is independent                                                     |
| `git_available` (bool)          | **Semantics change** | Was "found on GitHub." Becomes "found in any VCS source declared in composer.json" |
| `git_repository_health` (float) | **Remove**           | GitHub-specific vanity metric. No generic equivalent.                              |
| `git_repository_url` (string)   | **Keep**             | Still useful — now the resolved VCS URL from composer.lock                         |
| `git_latest_version` (string)   | **Keep**             | Still useful — latest compatible version from VCS                                  |

**Risk scoring affected (`calculateRiskScore`):**
- Currently weights `git_available` by `git_repository_health`. Health score is removed.
- Git weight simplifies: available = fixed weight (no health multiplier).
- Thresholds and TER/Packagist weights remain unchanged.

**Recommendation logic affected (`addRecommendations`):**
- References to `git_repository_health` removed (lines 267, 295–296).
- "Repository appears well-maintained" / "poor maintenance" recommendations removed.
- "Only available via Git repository" recommendations reworded: no longer implies a single known provider.

**Report templates affected (10 files):**

| Template                                                                 | Change                                                                           |
|--------------------------------------------------------------------------|----------------------------------------------------------------------------------|
| `html/partials/extension-detail/version-availability-analysis.html.twig` | Remove "Repository Health" display. "Git Repository" label becomes "VCS Source." |
| `html/partials/main-report/version-availability-table.html.twig`         | Column header "Git" → "VCS." Remove `git_repository_health` reference.           |
| `md/partials/extension-detail/version-availability-analysis.md.twig`     | Same changes as HTML variant                                                     |
| `md/partials/main-report/version-availability-table.md.twig`             | Same changes as HTML variant                                                     |
| `html/extension-detail.html.twig`                                        | Verify — may reference git metrics directly                                      |
| `md/extension-detail.md.twig`                                            | Verify — may reference git metrics directly                                      |
| Remaining 4 report templates                                             | Audit for `git_repository_health` or `git_available` references                  |

**`ReportContextBuilder` affected:**
- Lines 202–206: `git_repository_health` metric extraction removed.
- Lines 108–129: Availability stats counter — `git_available` key remains but semantics change. Consider renaming to `vcs_available` for clarity.

**JSON output schema (Story 5.3, NFR16):**
- `analyzers.version-availability.git_available` → consider renaming to `vcs_available`
- `analyzers.version-availability.git_repository_health` → removed
- `analyzers.version-availability.git_repository_url` → rename to `vcs_repository_url`
- `analyzers.version-availability.git_latest_version` → rename to `vcs_latest_version`
- This is a **breaking schema change** for any downstream consumers of the JSON. Must be documented.

**Note:** The `git_` → `vcs_` rename is optional but recommended. Keeping `git_` while the source is no longer necessarily GitHub creates a naming mismatch. If downstream consumers exist, this needs a deprecation strategy or version bump.

### Technical Impact

- ~1,189 LOC source code to remove/replace (`GitProvider/`, `GitRepositoryHealth`, `GitRepositoryInfo`)
- ~2,076 LOC test code to remove/rewrite
- `VersionAvailabilityAnalyzer`: dependency changes from `GitRepositoryAnalyzer` to new resolver; risk scoring simplified; recommendation logic rewritten
- `ReportContextBuilder`: metric extraction updated
- 10 report templates: column/label changes, health metric removal
- `config/services.yaml` wiring simplified
- JSON output schema: field renames and removals (breaking change)

---

## 3. Recommended Approach

**Selected: Direct Adjustment + Research Spike**

A research spike (Story 2.0) validates the Composer/git CLI mechanism before implementation begins. Then Epic 2 is rewritten to use Composer-based VCS resolution, and the existing `GitProvider/` subsystem is removed.

| Dimension             | Assessment                                                                                                        |
|-----------------------|-------------------------------------------------------------------------------------------------------------------|
| Effort                | **Medium** — simpler than original 4-story plan; net code reduction. Research spike adds 1 day.                   |
| Risk                  | **Low** — Composer VCS resolution is mature. Spike de-risks performance and auth concerns before code is written. |
| Timeline impact       | **Neutral to positive** — fewer implementation stories, though spike adds a serial dependency.                    |
| Team impact           | **Positive** — reduces cognitive load; no need to learn 3 provider APIs.                                          |
| Long-term maintenance | **Significantly reduced** — zero provider-specific API maintenance.                                               |

**Alternatives considered:**

- **Option 2 (Rollback only):** Remove GitProvider but don't replace. Rejected — VCS version availability is a core requirement.
- **Option 3 (MVP Review):** Reduce MVP scope. Not needed — this change reduces scope, not expands it.

---

## 4. Detailed Change Proposals

### 4A. PRD Changes

#### PRD-1: Consolidate FR11/FR12/FR13

**Section:** Functional Requirements > Version Availability Checking

**OLD:**
```
- FR11: System can check extension availability on GitHub repositories (tags, branches, version constraints)
- FR12: System can check extension availability on GitLab repositories, including private instances with authentication
- FR13: System can check extension availability on Bitbucket repositories (tags, branches, version constraints)
```

**NEW:**
```
- FR11: System can check extension availability on any VCS repository declared in the installation's
  composer.json, using Composer CLI, Composer PHP API, or git CLI as fallback, regardless of
  hosting provider (GitHub, GitLab, Bitbucket, Gitea, self-hosted, etc.)
- FR12: (removed — merged into FR11)
- FR13: (removed — merged into FR11)
```

**Rationale:** Per-provider requirements are implementation detail, not user-facing capability.

#### PRD-2: Simplify FR15

**Section:** Functional Requirements > Version Availability Checking

**OLD:**
```
- FR15: System can detect abandoned or unmaintained extensions based on repository and registry metadata
```

**NEW:**
```
- FR15: System can detect abandoned or unmaintained extensions based on latest tag/commit age
  from Composer metadata or git history, and registry metadata (TER last-update, Packagist
  abandonment flag)
```

**Rationale:** Provider-specific health APIs (stars, forks, issues) are GitHub-only vanity metrics. Tag/commit age is provider-agnostic and a more reliable maintenance signal.

#### PRD-3: Update MVP Feature #3

**Section:** MVP Feature Set > Must-have capabilities

**OLD:**
```
| 3 | GitLab/Bitbucket availability checks | Many agency projects host extensions on GitLab,
    not GitHub. Without this, version availability is incomplete | Medium |
```

**NEW:**
```
| 3 | VCS source availability checks (any provider) | Many agency projects host extensions on
    GitLab, Bitbucket, or self-hosted Git. Composer-based resolution covers all providers without
    per-host API clients | Small–Medium |
```

---

### 4B. Architecture Changes

#### ARCH-1: Rewrite "Git Source Detection & Version Availability" section

Replace the entire "Git Source Detection Pattern" section with:

```
### Git Source Detection & Version Availability

**Decision:** `composer.json`/`composer.lock` in the analyzed installation is the source of truth
for extension origins. Version resolution uses a two-tier strategy: Composer CLI as primary
resolver, generic git CLI as fallback. No per-provider API clients.

**Resolution chain (explicit fallback order):**

1. `ComposerSourceParser` extracts VCS URLs from `composer.lock` `packages[].source.url`
   (primary) and `composer.json` `repositories[].url` (fallback for packages missing from lock)

2. **Tier 1 — Composer CLI resolution** (`PackagistVersionResolver`):
   - Uses `composer show` with `--working-dir` pointing to the analyzed installation
   - Exact command variant determined by research spike (Story 2.0). Candidates:
     - `composer show -o -d /path --format=json` (batch: all outdated in one call)
     - `composer show -liD -d /path --format=json` (batch: direct deps with latest)
     - `composer show -a vendor/package --format=json` (per-package: all versions)
   - Authentication: Composer uses the installation's `auth.json` and SSH agent automatically
   - **When this tier is used:** Composer is available in the analysis environment and the
     analyzed installation has a valid `composer.lock`
   - **When this tier fails:** Composer not installed, `composer.lock` missing/corrupt,
     network error, auth failure → falls through to Tier 2

3. **Tier 2 — Generic git CLI resolution** (`GenericGitResolver`):
   - Uses `git ls-remote --tags <url>` to list available tags without cloning
   - Parses tag names into Composer-compatible version strings
   - Optionally: `git ls-remote --heads <url>` for branch-based constraints (e.g. `dev-main`)
   - Authentication: uses SSH agent and git credential helpers already configured on the host
   - **When this tier is used:** Composer resolution failed or is unavailable; or for
     repositories not in the installation's Composer config (e.g. discovered via
     `repositories[].url` only)
   - **When this tier fails:** network error, auth failure → warn and record null

4. **Failure handling:** If both tiers fail for a given repository, emit a Console WARNING
   with the URL and resolution errors from both tiers. Record availability as `null`
   (not `false`). Analysis continues for remaining extensions.

**What each tier provides:**

| Capability                         | Tier 1 (Composer) | Tier 2 (git CLI) |
|------------------------------------|-------------------|------------------|
| List available versions            | Yes (rich)        | Yes (tags only)  |
| Version constraint matching        | Yes (native)      | Manual parsing   |
| Dependency metadata (require)      | Yes               | No               |
| Branch-based versions (dev-*)      | Yes               | Via ls-remote    |
| Works without composer.lock        | Partially         | Yes              |
| Works without Composer installed   | No                | Yes              |
| Authentication                     | auth.json / SSH   | SSH / git creds  |
| Batch resolution (all packages)    | Yes (some modes)  | No (per-URL)     |

**Legacy installations (v11 non-Composer):** Extensions in `typo3conf/ext/` have no composer
provenance. Version availability falls back to TER + key-based lookup only. Tier 2 (git CLI)
may be used if a repository URL can be derived from extension metadata.

**Rationale:** Two-tier approach ensures coverage: Composer provides rich resolution for the
common case (Composer-based installations), git CLI provides a universal fallback that works
with any git-accessible URL regardless of hosting provider. Together they eliminate per-provider
API clients while reusing existing authentication.
```

#### ARCH-2: Update "External integration boundaries" table

Replace four provider-specific rows with:

```
| VCS repositories | `PackagistVersionResolver` | Composer `auth.json` / SSH agent (via --working-dir) | Log warning, null result |
```

Remove rows for: GitHub (`GitHubClient`), GitLab public (`GitLabProvider`), GitLab private (`GitLabProvider`), Bitbucket (`BitbucketProvider`).

#### ARCH-3: Update Project Structure diagram

Replace:

```
│   ├── ExternalTool/
│   │   ├── GitProvider/
│   │   │   ├── GitLabProvider.php
│   │   │   └── BitbucketProvider.php
│   │   └── DeclaredRepository.php
```

With:

```
│   ├── ExternalTool/
│   │   ├── PackagistVersionResolver.php           # NEW — resolves versions via Composer/git CLI
│   │   ├── VcsResolutionException.php        # Renamed from GitProviderException
│   │   └── DeclaredRepository.php            # SIMPLIFIED — url and packages only, no type
```

#### ARCH-4: Simplify `DeclaredRepository` pattern

Remove provider-type field:

```php
final class DeclaredRepository
{
    /** @param array<string> $packages */
    public function __construct(
        public readonly string $url,
        public readonly array $packages,
    ) {}
}
```

#### ARCH-5: Update AR4

**OLD:**
```
AR4: `GitProviderFactory` extension — provider resolution order: known public hosts → configured
private providers → HTTPS fallback; GitLabProvider and BitbucketProvider for FR12/FR13; private
instances configured in `.typo3-analyzer.yaml`
```

**NEW:**
```
AR4: `PackagistVersionResolver` — resolves VCS versions via Composer CLI or `git ls-remote` fallback;
uses Composer `auth.json` / SSH agent for authentication (via --working-dir pointing to analyzed
installation); no per-provider API clients; no tool-specific token configuration
```

---

### 4C. Epic 2 Rewrite

#### Epic 2 Summary (new)

```
### Epic 2: Complete Extension Source Coverage — All VCS Providers

Developer gets version availability data for extensions hosted on any VCS repository declared
in the installation's composer.json — GitHub, GitLab, Bitbucket, Gitea, self-hosted, or any
other — using Composer-based resolution that covers all providers through a single mechanism.
Replaces the provider-specific GitHub client with a generic approach. Unresolvable sources
produce a visible warning.

Implements: `ComposerSourceParser` (AR3 simplified), `PackagistVersionResolver` (AR4 replaced),
removal of existing `GitProvider/` subsystem.
**FRs covered:** FR11 (consolidated), FR14 (completing aggregation)
```

#### Story 2.0: VCS Resolution Mechanism Research Spike (new)

As a developer,
I want to evaluate Composer CLI, Composer PHP API, and git CLI approaches for resolving available versions from VCS URLs,
So that the implementation uses a validated mechanism with known performance characteristics and constraints.

**Research Questions:**

1. **Composer CLI command inventory and switches:**
   - `composer show --all --format=json vendor/package` — list all available versions from configured repositories
   - `composer show -a vendor/package <version> --format=json` — detailed info for a specific version including dependencies
   - `composer show --installed --direct --latest --format=json` (`-liD`) — installed direct dependencies with latest available version
   - `composer show --outdated --format=json` (`-o`) — only outdated packages with current vs latest
   - `composer show --working-dir=/path/to/installation` (`-d`) — run against a foreign project's `composer.json` without changing directory
   - Output formats: `--format=json`, `--format=text`, others — evaluate which format delivers the most useful structured data
   - Investigate whether combining `-d` with `--all`, `-o`, `-liD` gives us what we need without any custom resolution logic
   - Document which commands require a lock file vs only `composer.json`
   - Document which commands trigger network requests vs work offline

2. **Authentication and working directory:**
   - Does `--working-dir` cause Composer to use the target installation's `auth.json` and `repositories` configuration?
   - If not, can `COMPOSER_AUTH` env var or `COMPOSER_HOME` override bridge this gap?
   - Test with a private repository requiring authentication

3. **`git ls-remote --tags <url>`:**
   - Timing: average per-repository
   - Does it work through SSH agent and Composer-configured HTTPS tokens?
   - How to parse tag names into Composer-compatible version strings

4. **`git branch -a -r` (requires clone):**
   - Is there a use case where branches matter and tags don't? (e.g., `dev-main` constraint matching)
   - Cost of shallow clone vs full clone vs ls-remote

5. **Composer PHP API (`VcsRepository`, `RepositoryManager`):**
   - Stability across Composer 2.x versions
   - Can it be instantiated standalone against a foreign project's config?
   - Memory footprint for 40+ package resolutions

6. **Data sufficiency:**
   - Does `composer show -a vendor/package <version> --format=json` expose `require`/`require-dev` entries for TYPO3 version constraint matching?
   - Does `composer show -o -d /path --format=json` directly tell us which packages have newer versions available — potentially short-circuiting our own version comparison logic?
   - Can `composer show -liD -d /path --format=json` give us current + latest version in one call for all direct dependencies?

7. **Performance benchmark:**
   - Test each approach against 5-10 real VCS URLs (mix of GitHub, GitLab, Bitbucket, self-hosted)
   - Measure wall time per package and total for a 40-extension installation
   - Compare: single `composer show -o -d /path` (one call, all packages) vs per-package `composer show -a vendor/package` (N calls)
   - Test with warm and cold Composer cache
   - Evaluate whether batch commands (`-o`, `-liD`) eliminate the need for per-package resolution entirely

**Deliverable:** Decision document in `_bmad-output/planning-artifacts/` with benchmark results, recommended approach, and identified constraints. No production code.

**Timebox:** 1 day

---

#### Story 2.1: Composer Source Parser (rewritten)

As a developer,
I want the tool to extract VCS source URLs from the analyzed installation's `composer.lock`,
So that downstream resolution can check version availability for all VCS-sourced extensions.

**Acceptance Criteria:**

- **Given** a TYPO3 installation with a `composer.lock` containing packages with `source.url` entries
- **When** `ComposerSourceParser::parse(string $composerLockPath)` is called
- **Then** it returns a `DeclaredRepository[]` array where each entry carries `url` and `packages`
- **And** packages with `source.type === 'path'` are skipped (local packages)
- **And** packages without a `source` key are skipped (dist-only installs — not a warning)
- **And** SSH URLs (`git@host:path`) are handled correctly for host extraction
- **And** both `packages` and `packages-dev` arrays are scanned
- **And** if `composer.lock` is missing, falls back to `composer.json` `repositories[].url`
- **And** malformed JSON is handled gracefully with a logged warning and empty result
- **And** `DeclaredRepository` is a readonly VO with `url: string` and `packages: array<string>` — no provider-type field
- **And** `ComposerSourceParser` lives in `Infrastructure/Discovery/`
- **And** 100% unit test coverage with fixture-based test cases
- **And** PHPStan Level 8 reports zero errors

---

#### Story 2.2: Composer-Based VCS Version Resolution (new)

As a developer,
I want the tool to resolve available versions for VCS-sourced extensions using Composer CLI,
So that version availability is checked for all VCS providers without per-host API clients.

**Acceptance Criteria:**

- **Given** `ComposerSourceParser` has extracted `DeclaredRepository[]` with VCS URLs
- **When** `PackagistVersionResolver` resolves versions for those repositories
- **Then** it uses the Composer CLI approach determined by the research spike (batch command or per-package command)
- **And** `--working-dir` points to the analyzed installation so Composer uses its `auth.json` and `repositories` configuration
- **And** resolved versions are matched against the target TYPO3 version constraints to determine compatibility
- **And** if resolution fails (network error, auth failure, Composer not installed), the failure is recorded per-extension and control passes to the fallback resolver (Story 2.3)
- **And** operations use configurable timeouts (NFR2, NFR14)
- **And** `PackagistVersionResolver` lives in `Infrastructure/ExternalTool/`
- **And** unit tests cover: successful resolution, no compatible version, network failure, auth failure, Composer not installed
- **And** PHPStan Level 8 reports zero errors

**Note:** Exact command variant and method signatures depend on Story 2.0 findings.

**Transition contract:** During development, the existing `GitRepositoryAnalyzer` + `GitHubClient` remain functional and wired. `VersionAvailabilityAnalyzer` is not switched to the new resolver until Story 2.5.

---

#### Story 2.3: Generic Git CLI Resolver (new)

As a developer,
I want a generic git CLI resolver that can check version availability for any git-accessible URL,
So that extensions hosted on any provider (GitHub, GitLab, Bitbucket, Gitea, self-hosted) are covered when Composer resolution is unavailable or fails.

**Acceptance Criteria:**

- **Given** a VCS URL for which Composer resolution failed or was unavailable
- **When** `GenericGitResolver::resolveAvailableVersions(string $url)` is called
- **Then** it executes `git ls-remote --tags <url>` to list available tags without cloning
- **And** tag names are parsed into Composer-compatible version strings (e.g. `v1.2.3` → `1.2.3`, `1.0.0-RC1` → `1.0.0-RC1`)
- **And** optionally executes `git ls-remote --heads <url>` for branch-based constraints (e.g. `dev-main`)
- **And** authentication uses SSH agent and git credential helpers already configured on the host — no tool-specific token configuration
- **And** if `git ls-remote` fails (network error, auth failure), it returns `null` for that URL (not an exception)
- **And** operations use configurable timeouts
- **And** `GenericGitResolver` lives in `Infrastructure/ExternalTool/`
- **And** `GenericGitResolver` replaces the role of all provider-specific clients (`GitHubClient`, planned `GitLabProvider`, planned `BitbucketProvider`) with a single generic mechanism
- **And** unit tests cover: successful tag listing, no tags, network failure, SSH URL, HTTPS URL, tag name parsing edge cases
- **And** PHPStan Level 8 reports zero errors

**Key difference from current `GitHubClient`:** No provider-specific API calls (GraphQL, REST). No repository health metrics. No branch guessing. Just "list tags from any git URL and parse them."

---

#### Story 2.4: Unresolvable VCS Source Warning (rewritten from old 2.4)

As a developer,
I want the tool to warn me when a VCS source URL cannot be resolved by either Composer or git CLI,
So that I know the analysis is incomplete and can fix my authentication.

**Acceptance Criteria:**

- **Given** a VCS-sourced extension where both Composer resolution (Tier 1) and git CLI resolution (Tier 2) failed
- **When** the analysis runs
- **Then** a Console-level WARNING is written: `[WARNING] Could not resolve versions from "{url}". Ensure Composer authentication (auth.json) or git credentials (SSH agent) are configured.`
- **And** the warning includes which tiers were attempted and why they failed (e.g. "Composer: not installed; git: auth failure")
- **And** the extension's VCS availability is recorded as `null` (not `false`) to distinguish "unknown" from "not available"
- **And** analysis continues for all other extensions
- **And** in non-interactive mode the warning is written to stderr
- **And** unit tests cover: both tiers succeed (no warning), Tier 1 fails / Tier 2 succeeds (no warning), both fail (warning emitted), multiple failures (one warning per source)
- **And** PHPStan Level 8 reports zero errors

---

#### Story 2.5: Integrate New Resolvers and Update Data Model (transition story)

As a developer,
I want `VersionAvailabilityAnalyzer` to use the new `PackagistVersionResolver` and `GenericGitResolver` instead of the old `GitRepositoryAnalyzer`,
So that the analyzer produces provider-agnostic VCS availability data with updated metrics and reporting.

**Prerequisite:** Stories 2.2 and 2.3 are complete and independently tested.

**Acceptance Criteria:**

**Integration:**
- **Given** `PackagistVersionResolver` and `GenericGitResolver` are implemented
- **When** `VersionAvailabilityAnalyzer` is updated
- **Then** the constructor dependency changes from `GitRepositoryAnalyzer` to the new resolvers
- **And** `checkGitAvailability()` is rewritten to call Composer resolver first, then git CLI resolver as fallback
- **And** `config/services.yaml` wiring is updated accordingly

**Metric changes:**
- **And** metric `git_available` is renamed to `vcs_available` (bool — "found in any VCS source")
- **And** metric `git_repository_health` is removed (no generic equivalent)
- **And** metric `git_repository_url` is renamed to `vcs_repository_url`
- **And** metric `git_latest_version` is renamed to `vcs_latest_version`

**Risk scoring changes:**
- **And** `calculateRiskScore()` no longer uses `git_repository_health` as a weight multiplier
- **And** VCS availability contributes a fixed weight (value TBD — research spike may inform this)
- **And** TER and Packagist weights remain unchanged

**Recommendation logic changes:**
- **And** "Repository appears well-maintained" / "poor maintenance" recommendations are removed
- **And** "Only available via Git repository" becomes "Only available via VCS source"
- **And** recommendations reference Composer/git authentication, not provider-specific tokens

**Report template changes (10 files):**
- **And** column header "Git" → "VCS" in main report availability table (HTML and Markdown)
- **And** "Git Repository" label → "VCS Source" in extension detail pages
- **And** "Repository Health" display section removed from extension detail
- **And** all `git_available` / `git_repository_*` template variable references updated to `vcs_*`

**`ReportContextBuilder` changes:**
- **And** metric extraction updated: `git_repository_health` removed, `git_*` keys renamed to `vcs_*`
- **And** availability stats counter key `git_available` renamed to `vcs_available`

**JSON output schema:**
- **And** `analyzers.version-availability.git_available` → `vcs_available`
- **And** `analyzers.version-availability.git_repository_health` → removed
- **And** `analyzers.version-availability.git_repository_url` → `vcs_repository_url`
- **And** `analyzers.version-availability.git_latest_version` → `vcs_latest_version`
- **And** this is a **breaking schema change** and must be documented

**Transition safety:**
- **And** the old `GitProvider/` code is not deleted in this story — it is simply no longer wired
- **And** all existing tests are updated to use the new metric names
- **And** all report generation tests pass with updated template variable names
- **And** PHPStan Level 8 reports zero errors

---

#### Story 2.6: Remove Provider-Specific Git Subsystem (cleanup)

As a developer,
I want the now-unused provider-specific `GitProvider/` subsystem deleted,
So that the codebase carries no dead code.

**Prerequisite:** Story 2.5 is complete and all tests pass with the new resolvers.

**Acceptance Criteria:**

- **Given** `VersionAvailabilityAnalyzer` no longer depends on `GitRepositoryAnalyzer` or any `GitProvider/` types (confirmed in Story 2.5)
- **When** the cleanup is performed
- **Then** the following are deleted:
  - `GitProvider/GitProviderInterface.php`
  - `GitProvider/AbstractGitProvider.php`
  - `GitProvider/GitHubClient.php`
  - `GitProvider/GitProviderFactory.php`
  - `GitRepositoryHealth.php`
  - `GitRepositoryInfo.php`
  - `GitRepositoryAnalyzer.php`
  - All associated test files (~2,076 LOC)
- **And** `GitProviderException.php` is renamed to `VcsResolutionException.php` (if not already done)
- **And** `config/services.yaml` has no remaining `GitProvider` or `GitRepository` service entries
- **And** no source file imports any deleted class
- **And** all tests pass and PHPStan Level 8 reports zero errors
- **And** net code reduction: ~1,100 LOC source, ~2,000 LOC tests

---

### 4D. Design Note Updates

`composer-source-parser-design-note.md`:
- **Section 6** (`DeclaredRepository` type field): Remove `type` field. VO carries `url` and `packages` only.
- **Section 7** (provider resolution order): Replace with "pass URL to `PackagistVersionResolver`."
- **Sections 1–5** remain valid (source.url as primary field, SSH URL handling, path repositories, dist-only packages, packages-dev scanning).

`GitRepositoryVersionSupport.md`: Superseded by new approach. Mark as archived or remove.

---

## 5. Implementation Handoff

### Change Scope: Moderate

Requires backlog reorganization (Epic 2 rewrite) and architectural document updates. Does not require fundamental replan. Scope decreases rather than increases.

### Story Sequence

```
2.0 (Research spike)
  → 2.1 (ComposerSourceParser)
  → 2.2 (PackagistVersionResolver)    ─┐
  → 2.3 (GenericGitResolver)      ─┤ can be parallelized
  → 2.4 (Unresolvable warning)   ─┘ depends on 2.2 + 2.3 failure contract
  → 2.5 (Integration + data model + templates)  ← transition: old code still exists but unwired
  → 2.6 (Remove old GitProvider code)           ← cleanup: safe deletion
```

**Transition path:** The existing `GitHubClient` and `GitRepositoryAnalyzer` remain functional through Stories 2.0–2.4. They are only unwired in Story 2.5, and only deleted in Story 2.6. At no point is the existing GitHub resolution broken before the replacement is validated.

Story 2.0 is a serial prerequisite. Stories 2.2 and 2.3 can be parallelized. Story 2.5 is the integration gate. Story 2.6 is pure cleanup.

### Handoff Responsibilities

| Role         | Responsibility                                                                                  |
|--------------|-------------------------------------------------------------------------------------------------|
| PO/SM (Dirk) | Approve this proposal; update planning artifacts per sections 4A–4D                             |
| Development  | Execute Story 2.0 first; implement 2.1–2.4 based on spike findings                              |
| Architect    | Review architecture updates after Story 2.0 completes (spike may refine the resolution pattern) |

### Success Criteria

- Epic 2 delivers version availability for all VCS-sourced extensions regardless of hosting provider
- Two-tier resolution chain (Composer → git CLI) with clear fallback semantics
- `GitProvider/` subsystem fully removed; no provider-specific API code remains
- Version resolution uses Composer's existing authentication — no tool-specific token configuration
- Data model updated: `git_*` metrics → `vcs_*` metrics; `git_repository_health` removed
- All 10 report templates updated: "Git" column → "VCS", health display removed
- JSON output schema breaking change documented
- All tests pass, PHPStan Level 8 clean
- PRD, architecture, and epic documents reflect the Composer-based approach
- Performance within NFR1 bounds (5 min for 40-extension installation) — validated by spike
