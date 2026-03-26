# Review: Git Provider Architecture Is Over-Engineered

**Date:** 2026-03-26
**Reviewer:** Dirk Wenzel + Claude (adversarial review)
**Triggered by:** [Simon Schaufelberger (@simonschaufi)](https://github.com/simonschaufi) — [PR #191 comment](https://github.com/CPS-IT/typo3-upgrade-analyser/pull/191#issuecomment-4130333859): *"Why is this feature even relevant? Do we want to know the exact sources or isn't it enough to know that the extension is fetched from 'a git repository'?"* — He also pointed out that additional providers like Codeberg (e.g. `sjbr/static-info-tables`) further demonstrate the futility of maintaining per-provider API clients.
**Status:** Pending course correction
**Scope:** Epic 2 (Stories 2.1–2.4), Story P2-3, feature plan `GitRepositoryVersionSupport.md`, existing `GitProvider/` code, architecture document "Git Source Detection" section

---

## Thesis

The entire `GitProvider` subsystem — the provider interface, factory, GitHub GraphQL client, and planned GitLab/Bitbucket API clients — is a hand-rolled reimplementation of functionality that Composer already provides. Composer's VCS repository driver can resolve tags and branches from any git URL declared in `composer.json` `repositories`, using pre-existing authentication (`auth.json`, SSH agent). The provider-per-host approach inflates scope, creates ongoing API maintenance burden, and does not deliver proportional value. FR11, FR12, and FR13 should collapse into a single requirement: "check extension availability on any VCS source declared in the installation's `composer.json`."

---

## Evidence

### 1. Composer already resolves VCS sources generically

Composer's `vcs` repository type handles GitHub, GitLab, Bitbucket, Gitea, and any git-accessible URL through a single mechanism. It reads tags and branches, resolves version constraints, and respects `auth.json` and SSH keys. The tool's goal — "does a compatible version exist for the target TYPO3 version?" — maps directly to `composer show --available vendor/package` or Composer's PHP API. No provider-specific code is needed to answer this question.

### 2. The GitHubClient is 383 lines of provider-specific complexity

`src/Infrastructure/ExternalTool/GitProvider/GitHubClient.php` contains:
- GitHub GraphQL queries (which require authentication even for public repos)
- REST API calls for composer.json content retrieval (with base64 decoding)
- Branch guessing logic (`main` vs `master`)
- Contributor count via pagination header parsing

All of this answers the same question that `git ls-remote --tags <url>` answers in one line, or that Composer answers natively.

### 3. "Repository health" is GitHub vanity metrics, not upgrade risk

`GitRepositoryHealth` tracks stars, forks, open/closed issues, contributor count, archived status. These are GitHub-specific metrics that:
- Do not generalize to GitLab, Bitbucket, or self-hosted instances
- Do not answer the core question: "is a compatible version available?"
- Inflate risk scoring with noise (a 0-star repo with a matching tag is more useful than a popular repo without one)

FR15 ("detect abandoned or unmaintained extensions") can be satisfied much more cheaply by checking the age of the latest tag or commit, which `git ls-remote` or Composer metadata provides.

### 4. Authentication is duplicated

The architecture prescribes `GITHUB_API_TOKEN`, `GITLAB_TOKEN`, `BITBUCKET_TOKEN` env vars plus per-instance config in `.typo3-analyzer.yaml`. Meanwhile NFR11 and NFR12 explicitly state:
- "Private GitLab instances are accessed via existing git credentials or API tokens"
- "Private Packagist instances are accessed via Composer auth.json mechanisms"

Composer already uses `auth.json` and SSH agent. Any user whose `composer install` works on a private repo already has authentication configured. The tool's custom token management duplicates this and requires users to configure credentials in a second location.

### 5. The provider resolution order duplicates Composer's repository resolution

`GitProviderFactory` resolves providers by: known public hosts (domain match) -> configured private providers -> HTTPS fallback. Composer resolves packages in the order repositories are declared in `composer.json`. These are two competing resolution mechanisms. The architecture document says "No URL-sniffing heuristics" but the factory does exactly that: domain-matching on `github.com`, `gitlab.com`, `bitbucket.org`.

### 6. P2-3 spike produces fixtures for a distinction that should not exist

Story P2-3 creates separate fixture categories for GitLab SaaS public, GitLab SaaS private, GitLab self-hosted, Bitbucket public, Bitbucket private. As the story itself notes: "no structural difference from public — auth at API level." To Composer, all five are the same thing: a VCS URL. The spike is answering "how do we identify which provider hosts this repo?" — a question we should not be asking.

### 7. Stories 2.2 and 2.3 each require a full API client implementation

Each provider client means learning a different REST API (GitLab v4, Bitbucket 2.0), implementing different pagination, authentication, rate-limiting, and error handling. Estimated "Medium" effort per provider, with ongoing maintenance for API changes. With a Composer-based approach: zero provider-specific code.

### 8. FR11/FR12/FR13 are artificially split

These three requirements exist because the original implementation was GitHub-API-specific. They describe the same capability — "check version availability on a VCS source" — split by hosting provider. If the implementation delegates to Composer or `git ls-remote`, they collapse into one FR.

### 9. The public/private distinction adds no analytical value

Whether a repository is public or private is irrelevant to version detection. If authentication works, versions are resolved. If not, the tool gets an error and reports it. The tool does not need to classify repositories by access level.

### 10. Story 2.4 (unmatched source warning) prescribes the wrong remedy

Warning when a source can't be analyzed is correct. But advising "add a provider in `.typo3-analyzer.yaml`" requires the user to learn the tool's custom provider system. If the tool delegates to Composer, the guidance becomes: "ensure your Composer authentication is configured for this URL" — something the user already knows.

---

## Affected Artifacts

### Stories to cancel or rewrite

| Story | Current status | Recommendation |
|-------|---------------|----------------|
| P2-3 (GitLab/Bitbucket spike) | ready-for-dev | **Cancel.** Fixtures for provider-type identification are unnecessary if we delegate to Composer. |
| 2.1 (Composer Source Parser) | backlog | **Rewrite.** Still needed to parse `repositories` from `composer.json`, but scope reduces to extracting VCS URLs — no provider identification logic. |
| 2.2 (GitLab Provider) | backlog | **Cancel.** Replace with Composer-based VCS resolution. |
| 2.3 (Bitbucket Provider) | backlog | **Cancel.** Replace with Composer-based VCS resolution. |
| 2.4 (Unmatched source warning) | backlog | **Rewrite.** Warning mechanism is valid, but remedy changes from "configure a provider" to "ensure Composer auth is configured." |

### PRD requirements to consolidate

| Requirement | Action |
|-------------|--------|
| FR11 (GitHub availability) | Merge into single FR: "System can check extension availability on any VCS source declared in composer.json" |
| FR12 (GitLab availability) | Merge into consolidated FR |
| FR13 (Bitbucket availability) | Merge into consolidated FR |
| FR15 (abandoned detection) | Simplify: latest tag/commit age from Composer metadata or `git ls-remote`, not provider-specific health APIs |

### Architecture sections to revise

| Section | Change |
|---------|--------|
| "Git Source Detection Pattern" | Remove `GitProviderFactory` resolution order. Replace with: delegate to Composer VCS driver or `git ls-remote`. |
| "External integration boundaries" table | Remove GitHub/GitLab/Bitbucket provider rows. Add single "Composer VCS resolution" row. |
| `GitProviderInterface` (8 methods) | Replace with a simpler interface: `resolveAvailableVersions(string $url): VersionList` using Composer or git CLI. |
| `DeclaredRepository` value object | Simplify: `url` and `type` only. Remove provider identification concern. |

### Existing code to evaluate for removal

| File | LOC | Action |
|------|-----|--------|
| `GitProvider/GitProviderInterface.php` | 72 | Replace with simpler VCS resolution interface |
| `GitProvider/AbstractGitProvider.php` | ~100 | Remove |
| `GitProvider/GitHubClient.php` | 383 | Replace with Composer-based or git CLI resolution |
| `GitProvider/GitProviderFactory.php` | 84 | Remove or reduce to thin delegation |
| `GitProvider/GitProviderException.php` | ~20 | Keep (rename if needed) |
| `GitRepositoryAnalyzer.php` | ~150 | Rewrite to use Composer resolution |
| `Repository/RepositoryUrlHandler.php` | ~80 | Evaluate — may still be needed for URL normalization |
| `GitRepositoryHealth.php` | ~50 | Remove (vanity metrics) |
| All associated test files | ~800 | Rewrite to match new approach |

---

## Proposed Alternative: Composer-Based VCS Resolution

### Core principle

The analyzed installation's `composer.json` already declares all package sources. Composer knows how to resolve versions from VCS URLs. The tool should delegate to Composer rather than reimplementing provider-specific API clients.

### Resolution strategy

1. Parse `repositories` section from the analyzed installation's `composer.json` (Story 2.1, simplified)
2. For each extension with a VCS source URL:
   - Use `composer show --available vendor/package` (if Composer is available in the environment) to get available versions
   - Fallback: `git ls-remote --tags <url>` to list tags, then match against target TYPO3 version constraints
3. Authentication: Composer uses `auth.json` and SSH agent automatically. `git ls-remote` uses SSH agent. No tool-specific token configuration needed.
4. If resolution fails: warn with the URL and suggest checking Composer authentication configuration.

### What this eliminates

- Zero provider-specific API clients (no GitHubClient, GitLabProvider, BitbucketProvider)
- Zero provider-specific authentication configuration
- Zero GraphQL queries
- Zero "repository health" scoring
- Zero domain-matching heuristics

### What this preserves

- VCS URL parsing from `composer.json` (simplified Story 2.1)
- Unmatched source warnings (simplified Story 2.4)
- Version compatibility assessment against target TYPO3 version
- Graceful degradation when resolution fails

---

## Risk Assessment of Proposed Change

| Risk | Severity | Mitigation |
|------|----------|------------|
| Composer CLI may not be installed in analysis environment | Medium | Fallback to `git ls-remote`. Document Composer as recommended dependency. |
| `git ls-remote` requires network access and may be slow | Low | Cache results (existing cache layer applies). Same network requirement as current API approach. |
| Loss of "repository health" data in risk scoring | Low | This data had questionable value. Tag age from `git ls-remote` is a more reliable maintenance signal. |
| Existing GitHub integration tests break | Low | Tests need rewriting regardless. Current tests mock HTTP responses, not real Composer behavior. |
| `composer show --available` output format may vary | Low | Use Composer's PHP API (`Composer\Repository\VcsRepository`) instead of CLI parsing for robustness. |

---

## Decision Required

This review recommends a course correction that cancels 3 stories, rewrites 2, consolidates 3 PRD requirements, and replaces ~700 lines of existing provider-specific code with Composer-based delegation. The change reduces long-term maintenance burden and eliminates an entire class of provider-specific bugs.

Next step: process through `bmad-correct-course` to update sprint plan and affected artifacts.
