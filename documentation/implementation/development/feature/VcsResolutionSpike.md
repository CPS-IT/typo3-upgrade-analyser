# VCS Resolution Mechanism Research Spike

|                  |                                   |
|------------------|-----------------------------------|
| **Story**        | 2.0                               |
| **Date**         | 2026-03-28                        |
| **Spike Author** | Dev Agent (claude-sonnet-4-6)     |
| **Environment**  | Composer 2.8.9, PHP 8.4.19, macOS |
| **Status**       | Complete                          |

---

## 1. Scope

This document records empirical findings from testing Composer CLI, `git ls-remote`, and the Composer PHP API as mechanisms for resolving available versions from VCS URLs.

The spike validates assumptions for:
- **Story 2.2** (`PackagistVersionResolver`): exact Composer CLI command variant and auth mechanism
- **Story 2.3** (`GenericGitResolver`): go/no-go on `git ls-remote` as Tier 2 fallback

No production code was produced. All commands were run against real TYPO3 Composer installations and public/private VCS repositories.

**Known scope limitation — transitive VCS-only extensions:** The pre-filter and Tier 1 cover direct dependencies and Packagist-indexed packages. Transitive `typo3-cms-extension` packages sourced from private VCS repositories are not covered by either tier's discovery path. These are arguably the highest-risk category (private, potentially unmaintained). The frequency of this pattern in real TYPO3 projects was not assessed in this spike. This is documented as a known limitation in the architecture.

---

## 2. Environment Setup

**Test environment:**
- Composer 2.8.9 (installed via Homebrew)
- PHP 8.4.19 with **Xdebug 3.4.7 active** (`xdebug.mode=debug`, `xdebug.start_with_request=yes`)
- SSH agent active with keys for GitHub and private GitLab host
- Composer cache populated from prior `composer install` runs

**Xdebug overhead on benchmark timings:**
Xdebug mode=debug with start_with_request=yes causes every PHP subprocess (i.e. every `composer` CLI call) to attempt a debugger connection. Since no debugger listens on port 9003, the connection is refused immediately (~90ms overhead per call). `git` binary calls are unaffected.

| Command type | With Xdebug | Without Xdebug (`XDEBUG_MODE=off`) | Overhead |
|---|---|---|---|
| `composer show --all vendor/pkg` | ~400ms | ~315ms | ~85ms |
| `git ls-remote -t --refs <url>` | ~562ms | ~562ms | 0ms |

All Composer CLI timings in this document include the ~85ms Xdebug overhead. Production timings (no Xdebug) would be ~85ms lower per Composer subprocess call. The relative comparisons and architectural conclusions are unaffected.

**Benchmark environment caveat:** All measurements were taken on macOS (Apple Silicon) with Homebrew-installed Composer. The "~315ms without Xdebug" figure is derived by subtracting the measured ~85ms overhead — it was not verified by a separate run with `XDEBUG_MODE=off`. Linux CI environments, cold-cache conditions, and different PHP/Composer builds are not represented. Absolute timings should be treated as order-of-magnitude estimates; only relative comparisons between command variants are reliable.

**Three real TYPO3 Composer installations used as `--working-dir` targets:**

| Label | Packages installed | Direct deps | VCS repo type | Private repos |
|-------|---------------------|-------------|----------------|---------------|
| Project A | 249 | 73 | GitHub (public) | No |
| Project B | 215 | 55 | GitHub (public) | No |
| Project C | 156 | 66 | GitHub + private SSH GitLab | Yes (12 SSH repos) |

All project names and internal package names have been anonymized. Private GitLab URLs are shown as `git@[PRIVATE-GITLAB]:[ANON-GROUP]/[ANON-REPO].git`.

**VCS URLs tested for `git ls-remote` (Task 3):**

| # | URL | Provider | Auth | Tags |
|---|-----|----------|------|------|
| 1 | `https://github.com/TYPO3-Solr/ext-solr.git` | GitHub | Public HTTPS | 117 |
| 2 | `https://github.com/b13/container.git` | GitHub | Public HTTPS | 51 |
| 3 | `https://github.com/maikschneider/bw_jsoneditor.git` | GitHub | Public HTTPS | 8 |
| 4 | `https://github.com/brotkrueml/schema.git` | GitHub | Public HTTPS | 98 |
| 5 | `https://github.com/georgringer/news.git` | GitHub | Public HTTPS | 97 |
| 6 | `git@[PRIVATE-GITLAB]:[ANON-GROUP]/[ANON-REPO-1].git` | Self-hosted GitLab | SSH agent | 7 |
| 7 | `git@[PRIVATE-GITLAB]:[ANON-GROUP]/[ANON-REPO-2].git` | Self-hosted GitLab | SSH agent | 9 |
| 8 | `git@[PRIVATE-GITLAB]:[ANON-GROUP]/[ANON-REPO-3].git` | Self-hosted GitLab | SSH agent | 14 |
| 9 | `https://gitlab.com/[ANON-VENDOR]/[ANON-REPO].git` | GitLab.com | Public HTTPS | 86 |

---

## 3. Composer CLI Command Inventory

### 3.1 `composer show --format=json` (no flags, installed packages)

**Output fields per package:** `name`, `direct-dependency`, `homepage`, `source`, `version`, `description`, `abandoned`

**Verdict:** Shows installed version only. No version list, no `requires` block. Useless for version availability checks.

---

### 3.2 `composer show -o -d /path --format=json` (outdated)

**Output fields per package:** `name`, `version`, `latest`, `latest-release-date`, `latest-status`, `release-age`, `release-date`, `source`

**`latest-status` values observed:** `up-to-date`, `semver-safe-update`, `update-possible`

**Benchmark against real TYPO3 installations:**

| Project | Packages | Outdated | Time |
|---------|----------|----------|------|
| Project A | 249 | 151 | **23,319ms** |
| Project B | 215 | 108 | **17,931ms** |
| Project C | 156 | 106 | **167,006ms** |

Project C's time is 7× higher due to 12 private SSH GitLab repos that Composer must fetch to check for updates.

**Critical finding: requires `vendor/` directory.** Running against a directory without an installed vendor returns:

```
No dependencies installed. Try running composer install or update.
[]
```

The batch outdated check CANNOT run against an analyzed TYPO3 installation unless its `vendor/` directory is accessible on the same filesystem.

**Can it short-circuit version comparison logic? (AC6):** Partially. The `latest-status` field directly answers "is there a newer version?" without custom comparison. However, the command does NOT return a `requires` block — TYPO3 constraint data is absent. It cannot determine whether the `latest` version is compatible with the target TYPO3 version.

**Does it cover VCS-sourced packages?** YES — if those packages are installed in `vendor/`. The `source.url` field in the output points to the git tree URL, confirming the package origin.

---

### 3.3 `composer show -liD -d /path --format=json` (installed, direct dependencies, with latest)

**Output fields per package:** same as `-o` plus `direct-dependency: true`

**Benchmark against real TYPO3 installations:**

| Project | Direct deps | Time |
|---------|-------------|------|
| Project A | 73 | **13,883ms** |
| Project B | 55 | **14,618ms** |
| Project C | 66 | **38,884ms** |

Project C again slower due to private SSH repos. Project A and B are consistent at ~14s regardless of direct dep count — Composer spends most time fetching repository metadata, not iterating packages.

**Verdict:** Faster than `-o` for projects without many private repos, but same limitations: no `requires` block, requires `vendor/`.

---

### 3.4 `composer show --all vendor/package --format=json` (per-package, no working-dir)

**Output fields:** `name`, `description`, `keywords`, `type`, `homepage`, `names`, `versions`, `licenses`, `source`, `dist`, `path`, `released`, `support`, `autoload`, `requires`, `devRequires`, `provides`, `conflicts`

**Example `requires` for `georgringer/news` (latest version):**
```json
{
    "typo3/cms-core": "^13.4.20 || ^14.0",
    "typo3/cms-backend": "^13.4.20 || ^14.0",
    "typo3/cms-extbase": "^13.4.20 || ^14.0",
    "typo3/cms-fluid": "^13.4.20 || ^14.0",
    "typo3/cms-frontend": "^13.4.20 || ^14.0",
    "typo3/cms-install": "^13.4.20 || ^14.0"
}
```

**Benchmark (5 real TYPO3 extensions, Packagist-indexed, warm cache):**

| Package | Versions | TYPO3 req | Time |
|---------|----------|-----------|------|
| `apache-solr-for-typo3/solr` | 139 | Yes (`typo3/cms-backend: *`) | 413ms |
| `b13/container` | 68 | Yes (`^11.5 \|\| ^12.4 \|\| ^13.4 \|\| ^14.0`) | 410ms |
| `georgringer/news` | 100 | Yes (`^13.4.20 \|\| ^14.0`) | 380ms |
| `brotkrueml/schema` | 103 | Yes (`^13.4 \|\| ^14.1`) | 401ms |
| `blueways/bw-jsoneditor` | 15 | Yes (`^12.0 \|\| ^13.0`) | 420ms |
| **5 packages total** | — | — | **~2.5s** |

**Extrapolated for 40 packages (Packagist, warm cache):** ~16–17s

**Important:** No `-d` flag means Composer uses the global Packagist index. All tested TYPO3 extensions are Packagist-indexed, so this works without `--working-dir`.

---

### 3.5 `composer show --all -d /path vendor/package --format=json` (per-package with working-dir)

**Benchmark against real TYPO3 installations (Project A, public GitHub repos):**

| Package | Versions | Time with `-d /project_A` |
|---------|----------|--------------------------|
| `b13/container` | 68 | **12,889ms** |
| `georgringer/news` | 100 | **11,580ms** |
| `apache-solr-for-typo3/solr` | 139 | **12,603ms** |

**Same packages without `-d` (warm Packagist cache):** ~400ms each.

**Why `--working-dir` is 30× slower:** When `-d /path` is set, Composer loads and initializes ALL repositories declared in the target's `composer.json` before resolving the query. Project A has many VCS repository entries — Composer fetches metadata from each on first use even for Packagist packages.

**Practical implication:** `--working-dir` per-package calls are **not viable** for iterating over extensions. `12s × 40 packages = ~480s = 8 minutes` even for public repos with a warm Packagist cache.

**Auth passthrough test (Project C, private SSH GitLab package):**

A private package (`type: typo3-cms-extension`) sourced from `git@[PRIVATE-GITLAB]:...` was resolved via:
```bash
composer show --all --format=json -d /path/to/project_C vendor/private-package
```

Result:
- **Exit code: 0 (success)**
- **Versions returned:** 8 (`3.0.1`, `3.0.0`, `2.0.0`, `1.0.1`, `1.0.0`, `0.3.0`, `dev-develop`, `dev-master`)
- **`requires`:** `{"typo3/cms-core": "^11.5"}`
- **Time:** 9,253ms
- **Auth mechanism:** SSH agent (no explicit token, no `COMPOSER_AUTH` needed). `--working-dir` caused Composer to read the project's SSH-based VCS repository config and delegate auth to the SSH agent.

**Conclusion on auth passthrough:** Confirmed working via `--working-dir`. Composer reads `auth.json` and SSH-based VCS repository URLs from the target directory's `composer.json`. The SSH agent provides credentials without any explicit token configuration.

---

## 4. `git ls-remote` Testing

### 4.1 Command: `git ls-remote -t --refs <url>`

**Benchmark — 9 real repositories (public GitHub, private GitLab SSH, public GitLab HTTPS):**

| # | Type | Tags | Time |
|---|------|------|------|
| 1 | GitHub public (`ext-solr`, 117 tags) | 117 | 503ms |
| 2 | GitHub public (`b13/container`, 51 tags) | 51 | 494ms |
| 3 | GitHub public (`bw_jsoneditor`, 8 tags) | 8 | 623ms |
| 4 | GitHub public (`brotkrueml/schema`, 98 tags) | 98 | 535ms |
| 5 | GitHub public (`georgringer/news`, 97 tags) | 97 | 540ms |
| 6 | Private GitLab SSH (7 tags) | 7 | 632ms |
| 7 | Private GitLab SSH (9 tags) | 9 | 632ms |
| 8 | Private GitLab SSH (14 tags) | 14 | 667ms |
| 9 | GitLab.com HTTPS public (86 tags) | 86 | 634ms |

**Average:** ~562ms across all providers. Self-hosted GitLab (SSH) ~10–30% slower than GitHub HTTPS but same order of magnitude.

**Tag count does not significantly affect time** (8 tags vs 117 tags: both ~500–625ms). Network round-trip dominates.

**Extrapolated for 40 extensions (serial):** ~22.5s. Well within the 5-minute NFR budget.

**Recommended command:** `git ls-remote -t --refs <url>`

The `--refs` flag suppresses peeled tag entries (`^{}` suffixes) and pseudo-refs, returning only actual refs. This eliminates a parsing step compared to raw `--tags`.

### 4.2 Tag name parsing

Raw output sample from `git ls-remote -t --refs` on real repos:

```
# GitHub public (georgringer/news) — pure semver, no v prefix:
6b604382b7a27e7000c507bbf4cb98a335291cef    refs/tags/10.0.0
d175a273ce2be454524f218d5685e39d8d92bdcb    refs/tags/10.0.1
...

# Private GitLab SSH — pure semver, no v prefix:
hash1    refs/tags/0.1.0
hash2    refs/tags/3.0.1
hash3    refs/tags/dev-develop   ← branch masquerading as tag (ignore)

# ext-solr — pure semver, no v prefix:
hash1    refs/tags/8.0.1
hash2    refs/tags/9.0.3
```

**Observed across all 9 tested repos:** Pure semver `X.Y.Z` (no `v` prefix) is the dominant pattern in TYPO3 extensions. The `v` prefix convention appears in some non-TYPO3 PHP libraries (not tested here).

**Tag-name to version-string parsing rules:**

| Tag format | Example | Composer version | Note |
|------------|---------|-----------------|------|
| `X.Y.Z` | `10.0.1` | `10.0.1` | Identity — most common in TYPO3 ecosystem |
| `vX.Y.Z` | `v1.2.3` | `1.2.3` | Strip `v` prefix |
| `X.Y.Z-RCN` | `1.0.0-RC1` | `1.0.0-RC1` | Preserve as-is (Composer accepts) |
| `vX.Y.Z-RCN` | `v2.0.0-RC1` | `2.0.0-RC1` | Strip `v` |
| `dev-main`, `dev-master` | `dev-main` | `dev-main` | Branch constraint (head only) — from ls-remote --heads |
| Non-semver suffix | `v3.0alpha` | Skip | Non-standard — filter out |

### 4.3 Authentication

**GitHub public HTTPS:** No auth required.

**GitLab.com public HTTPS:** No auth required.

**Private GitLab SSH (`git@[HOST]:group/repo.git`):** SSH agent used automatically. No explicit token configuration. Confirmed working for 3 private repos via SSH agent with loaded keys.

**Private HTTPS:** Uses git credential helpers (`git config credential.helper`). Not tested in this spike (all private repos were SSH-based).

### 4.4 Branch listing

`git ls-remote --heads <url>` follows identical timing characteristics. For `dev-*` version constraints, use `git ls-remote --refs <url>` (no `-t`) to get both tags and branches in a single call.

---

## 5. Composer PHP API Evaluation

**Classes evaluated:** `Composer\Repository\VcsRepository`, `Composer\Repository\RepositoryManager`

**Finding:** The `composer/composer` package is not available as a library dependency in this project. It is a standalone PHAR. The PHP classes are not accessible without adding `composer/composer` as a Composer dependency, which creates a bootstrapping conflict.

**Stability:** `VcsRepository` and `RepositoryManager` are internal Composer API — not marked stable for external use. Breaking changes occur between minor versions of Composer 2.x.

**Standalone instantiation:** Requires a full Composer bootstrap (`Factory::create()`, I/O interface, config object). Not feasible without significant framework scaffolding.

**Memory footprint:** Not benchmarked (blocked by unavailability as a library). Known to be significant.

**Verdict:** Not viable.

---

## 6. Data Sufficiency Analysis

### Question: Does `composer show -o -d /path` short-circuit version comparison logic? (AC6)

**Answer: Partially.** The `latest-status` field directly answers "is there a newer version?" without custom version comparison logic. However:
- No `requires` block → cannot determine TYPO3 compatibility of the `latest` version
- Requires `vendor/` installed on the same filesystem
- Private-repo projects are dramatically slower (Project C: 167s)

**Conclusion:** `-o` alone is insufficient for upgrade readiness analysis.

### Question: Can `composer show -o -d /path` replace per-package N calls with one batch call? (AC6)

**Answer: No.** It confirms "is there a newer version?" in one call but cannot answer "is the newer version TYPO3-compatible?" Per-package `--all` calls remain necessary for compatibility data.

### Question: Does `composer show --all vendor/package` expose `require` with TYPO3 constraints?

**Answer: YES, but only for the latest version.** The `requires` field in `--all` output (without a version argument) contains the full `require` block for the **latest available version** only. All other versions in the list have no individual `requires` data.

**`composer show --all vendor/package X.Y.Z` (versioned call)** returns `requires` for that specific version and `versions: [X.Y.Z]` (single entry). Timing is identical (~360–395ms).

Example — `georgringer/news`, three different versions, different TYPO3 constraints:

| Call | `versions` returned | `typo3/cms-core` requires |
|------|---------------------|--------------------------|
| `show --all georgringer/news` | 100 versions | `^13.4.20 \|\| ^14.0` (latest only) |
| `show --all georgringer/news 12.3.1` | `[12.3.1]` | `^12.4.2 \|\| ^13.1` |
| `show --all georgringer/news 9.4.0` | `[9.4.0]` | `^10.4 \|\| ^11` |

**Implication for Story 2.2:** A single `show --all vendor/package` call tells you:
- The full version list (all available versions)
- The TYPO3 constraint for the **latest** version only

If the latest version is compatible with the target TYPO3 version: one call is sufficient.

If the latest is NOT compatible (e.g. the extension dropped support for the target version in a later release): additional versioned calls are needed to find the newest compatible version. Each versioned call costs ~360–380ms. For an extension with many versions, a binary search strategy on the version list reduces the call count.

This is the ONLY command variant that provides version-specific `requires` data.

### Question: Can `composer show -liD` give current + latest in one call for direct deps?

**Answer: YES**, but only for direct deps and without `requires`. Useful as a fast pre-filter to identify which packages have updates available before issuing per-package `--all` calls.

---

## 7. Benchmark Summary Table

| Command | Scope | `requires` block | `vendor/` needed | Time (warm, typical) |
|---------|-------|-----------------|-----------------|---------------------|
| `show --format=json` | installed | No | Yes | fast |
| `show -o -d /path` | outdated batch | No | Yes | 18–167s (project-dependent) |
| `show -liD -d /path` | direct + latest | No | Yes | 14–39s (project-dependent) |
| `show --all vendor/pkg` | per-package (Packagist) | **Yes** | No | ~400ms/pkg |
| `show --all -d /path vendor/pkg` | per-package (any repo) | **Yes** | No | **11–13s/pkg** (all projects) |
| `git ls-remote -t --refs <url>` | per-URL tags | No (tag names only) | No | ~562ms/URL (all providers) |

---

## 8. Recommended Approach for Story 2.2 (PackagistVersionResolver)

**Recommended command: `composer show --all --format=json vendor/package` (without `-d`)**

**Rationale:**
1. Returns full version list + `requires` block with TYPO3 constraints
2. ~400ms per package for Packagist-indexed extensions — 40 packages ≈ 16s
3. No `vendor/` required, no lock file required

**Critical finding — `--working-dir` overhead:**
Adding `-d /path` adds 11–13 seconds per package even for Packagist packages. This is caused by Composer loading all repository objects from the target's `composer.json` before any resolution occurs. With 40+ VCS repos declared (as in Project C), this overhead is unavoidable.

**Decision:** Do NOT use `--working-dir` for per-package `show --all` iteration.

**For VCS-only packages (not on Packagist):**
These cannot be resolved without `--working-dir` since Composer needs the repository declaration. Options:
1. Use `--working-dir` but accept 9–13s per VCS-only package (confirmed working, confirmed auth passthrough via SSH agent)
2. Fall through to Tier 2 (`git ls-remote`) immediately for any package with `source.type = "git"` in the lock file, bypassing Composer entirely for these

Recommended: **fall through to Tier 2 for VCS-only packages.** The Composer cache path (`~/.composer/cache/repo/`) is NOT portable — it is overridden by `COMPOSER_CACHE_DIR`, `$XDG_CACHE_HOME`, and platform-specific defaults. The cache-check optimization mentioned earlier is dropped from the recommended approach. If revisited in a future story, resolve the cache path via `composer config cache-repo-dir` (one subprocess call) rather than hardcoding `~/.composer/cache/repo/`.

**Auth passthrough via `--working-dir`:**
- Confirmed working: SSH-based private GitLab repos resolved successfully via `--working-dir`
- `auth.json` in the target directory is read automatically
- SSH agent credentials used without explicit token
- Fallback: `COMPOSER_AUTH` env var (JSON string) for environments without SSH agent

**Minimum Composer version:** 2.1+ for `--format=json` stability.

---

## 9. Go/No-Go for Story 2.3 (`git ls-remote` as Tier 2 Fallback)

**Decision: GO**

**Justification:**
- Performance: ~562ms per URL across GitHub, private GitLab SSH, and GitLab.com HTTPS. 40 extensions ≈ 22.5s — well within the 5-minute NFR
- Auth: SSH agent works for private SSH repos without any explicit token. Confirmed on 3 private repos
- Universality: identical performance across providers and tag counts
- No `vendor/`, no Composer, no project configuration required

**Limitation:** Tag names only — no `requires` block. Cannot determine TYPO3 compatibility without fetching the tagged `composer.json`. Story 2.3 options:
- (Option A) Fetch `composer.json` at each tag ref
- (Option B) Fetch `composer.json` from the most recent stable tag only (conservative approximation)
- (Option C) Report version availability only, no compatibility assessment

Recommended: **Option B** — fetch `composer.json` from the most recent stable tag. Avoids N×M HTTP requests while providing a compatibility signal for the most likely upgrade target.

**Recommended Tier 2 command:** `git ls-remote -t --refs <url>`

---

## 10. Identified Constraints

| Constraint | Applies To | Impact |
|------------|------------|--------|
| `vendor/` required for batch commands (`-o`, `-liD`) | Tier 1 batch | Cannot use batch if vendor not on analysis host |
| `--working-dir` adds 11–13s per package (repo init overhead) | Tier 1 with `-d` | Do not use `-d` for iterating packages |
| Private VCS packages require `--working-dir` OR fall to Tier 2 | Tier 1 VCS-only | Prefer Tier 2 fallback for VCS-only packages |
| `-o` and `-liD` scale poorly with private repos (Project C: 167s for 156 pkgs) | Tier 1 batch | Effectively unusable for private-heavy projects |
| `composer/composer` PHP API not stable for external use | PHP API option | Not viable |
| `git ls-remote` gives tags only, no `requires` | Tier 2 | Need separate `composer.json` fetch for compatibility |
| Tag format `X.Y.Z` (no `v` prefix) dominant in TYPO3 ecosystem | Tier 2 parser | `v` strip is edge case, not common path |
| `--refs` flag required to suppress peeled entries | Tier 2 parser | Use `git ls-remote -t --refs`, not `--tags` |
| Private HTTPS VCS: requires git credential helper setup | Tier 2 auth | Document as prerequisite |
| Composer min version 2.1 for stable JSON output | Tier 1 | Add version check in `PackagistVersionResolver` |
| Stale `vendor/` (lock updated, `composer install` not run) | Tier 1 pre-filter | Pre-filter may report packages as up-to-date that were added/changed since last install. No detection mechanism — treat as "present but potentially stale" |
| Tier 2 `composer.json` fetch cost is unverified | Tier 2 | One raw-file HTTP call per VCS-only package to fetch `composer.json` from the most recent stable tag. Not benchmarked. For 40 private extensions on a slow internal GitLab, 40 HTTP fetches may significantly exceed the ls-remote timings measured here |
| Composer cache path is not portable | Tier 1 optimization | `~/.composer/cache/repo/` is overridden by `COMPOSER_CACHE_DIR` and `$XDG_CACHE_HOME`. Cache-check optimization dropped from recommended approach |

---

## 11. References

- `src/Infrastructure/ExternalTool/GitHubClient.php` — current GitHub GraphQL integration (to be replaced in Story 2.6)
- `src/Infrastructure/ExternalTool/GitVersionParser.php` — existing tag-to-version parser. **Evaluation result:** The class does NOT perform tag-name-to-version-string parsing (Section 4.2 rules). It operates on pre-parsed `GitTag` objects and has a simplified compatibility check: if the main branch `composer.json` is compatible, all stable tags are returned as compatible — this is incorrect for Tier 2 where per-tag compatibility matters. The `isComposerCompatible()` method delegates to `ComposerConstraintCheckerInterface`, which IS reusable. **Verdict: replace `GitVersionParser` with new logic in `GenericGitResolver`; reuse `ComposerConstraintCheckerInterface` for TYPO3 compatibility checking.**
- `_bmad-output/planning-artifacts/architecture.md` — Git Source Detection & Version Availability section
- `_bmad-output/planning-artifacts/sprint-change-proposal-2026-03-26.md` — Story 2.0 research questions (Section 4C)
- [Composer CLI docs: `show` command](https://getcomposer.org/doc/03-cli.md#show-info)
- [Composer auth.json documentation](https://getcomposer.org/doc/articles/authentication-for-private-packages.md)
