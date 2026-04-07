# Code Review: Full Branch `feature/2-5-integrate-resolvers-and-update-data-model`

**Date:** 2026-04-07
**Reviewer:** claude-opus-4-6 (BMAD code-review workflow)
**Review mode:** Full (3 story specs loaded)
**Branch:** `feature/2-5-integrate-resolvers-and-update-data-model` vs `main`
**Scope:** Stories 2-5, 2-5a, 2-5b (all commits on branch)
**Stats:** 55 files changed, +4,134 / -955 lines

## Review Method

Three parallel adversarial review layers per chunk:
- **Blind Hunter** — diff-only, no project context
- **Edge Case Hunter** — diff + full project read access
- **Acceptance Auditor** — diff + spec + context docs

Findings triaged into: patch (fixable), defer (pre-existing / out of scope), reject (noise).

---

## Patch Findings (22)

### P-01: Integration test `getRequiredTools` assertion outdated [CRITICAL]

- **File:** `tests/Integration/Analyzer/VersionAvailabilityIntegrationTestCase.php:87`
- **Problem:** Asserts `['curl']` but implementation now returns `['curl', 'git']`.
- **Status:** Confirmed failing (`FAILURES! Tests: 1, Assertions: 2, Failures: 1`).
- **Fix:** Change assertion to `['curl', 'git']`.

### P-02: Shutdown function `&$cloneCache` reference breaks after `reset()` [HIGH]

- **File:** `src/Infrastructure/ExternalTool/GitVersionResolver.php:270-276`
- **Problem:** `register_shutdown_function` captures `$cache = &$this->cloneCache`. When `reset()` assigns `$this->cloneCache = []`, PHP breaks the reference. The shutdown closure holds the old (now-empty) array. Clones created after `reset()` are never cleaned up.
- **Fix:** Capture `$this` directly instead of the reference alias:
  ```php
  register_shutdown_function(function (): void {
      foreach ($this->cloneCache as $dir) {
          $this->removeDirectory($dir);
      }
  });
  ```

### P-03: Stale tmpdir from crashed process causes clone failure [HIGH]

- **File:** `src/Infrastructure/ExternalTool/GitVersionResolver.php:219`
- **Problem:** Tmpdir path is deterministic (`md5(url) + pid`). If a prior process was SIGKILL'd, the directory persists. `git clone` fails with "destination path already exists."
- **Fix:** Before cloning, check and remove stale directory:
  ```php
  if (is_dir($tmpDir)) {
      $this->removeDirectory($tmpDir);
  }
  ```

### P-04: `normalizeVcsUrl` mismatch for `ssh://` vs `git@` forms [HIGH]

- **File:** `src/Infrastructure/Discovery/ExtensionDiscoveryService.php:508-521`
- **Problem:** `git@github.com:org/repo.git` normalizes to `github.com/org/repo`. `ssh://git@github.com/org/repo.git` goes through `parse_url`, producing `github.com//org/repo` (double slash from leading `/` in path). VCS-declared URL matching fails for `ssh://` URLs.
- **Fix:** Strip leading `/` from parsed path before concatenation:
  ```php
  $path = ltrim(strtolower($parsed['path'] ?? ''), '/');
  ```

### P-05: `addRecommendations` treats VCS Unknown as "not available in any repository" [HIGH]

- **File:** `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php:234`
- **Problem:** When TER=false, Packagist=false, VCS=Unknown, `$anyAvailable` is false. Method emits "Extension not available in any known repository." This is misleading — Unknown means the check was inconclusive, not definitively absent.
- **Fix:** Add a distinct path for Unknown VCS:
  ```php
  if (!$anyAvailable) {
      $vcsIsUnknown = $vcsAvailable instanceof VcsAvailability
          && VcsAvailability::Unknown === $vcsAvailable;
      if ($vcsIsUnknown && !$terAvailable && !$packagistAvailable) {
          $result->addRecommendation(
              'VCS availability could not be verified (network/auth failure). '
              . 'TER and Packagist also report unavailable. Manual check recommended.'
          );
          return;
      }
      $result->addRecommendation('Extension not available in any known repository...');
      return;
  }
  ```

### P-06: DataProvider missing string-to-enum normalization for cached values [HIGH]

- **File:** `src/Infrastructure/Reporting/Provider/VersionAvailabilityDataProvider.php:79-81`
- **Problem:** `instanceof VcsAvailability` check fails for string values from cache. Falls through to `VcsAvailability::Unknown->value`, discarding valid cached `'available'`/`'unavailable'` status.
- **Fix:**
  ```php
  $vcsMetric = $result->getMetric('vcs_available');
  $vcsValue = match (true) {
      $vcsMetric instanceof VcsAvailability => $vcsMetric->value,
      is_string($vcsMetric) => VcsAvailability::tryFrom($vcsMetric)?->value
          ?? VcsAvailability::Unknown->value,
      default => VcsAvailability::Unknown->value,
  };
  // then use $vcsValue for 'vcs_available' key
  ```

### P-07: "From Source" marker fires for dist-installed packages with repository_url [HIGH]

- **File:** 4 template locations (HTML + MD variants of table and extension-detail)
- **Problem:** Condition `distribution_type is null or repository_url` is too broad. `repository_url` is set for VCS-declared packages AND packages without dist. Any package with a repo URL shows the fountain icon — even Packagist-distributed packages.
- **Locations:**
  - `resources/templates/html/detailed-report.html.twig:253`
  - `resources/templates/html/partials/extension-detail/version-availability-analysis.html.twig:22,25`
  - `resources/templates/html/partials/main-report/version-availability-table.html.twig:30`
  - `resources/templates/md/partials/main-report/version-availability-table.md.twig:11`
  - `resources/templates/md/partials/extension-detail/version-availability-analysis.md.twig:7`
- **Fix options:**
  - (a) Revert to `distribution_type is null` only.
  - (b) Add a dedicated `is_vcs_sourced` boolean in `VersionAvailabilityDataProvider` and use that in templates.

### P-08: SSH host cache key includes user prefix — inconsistent results for same host [MEDIUM]

- **File:** `src/Infrastructure/ExternalTool/GitVersionResolver.php:345-368`
- **Problem:** `extractSshHost` returns `git@host` or `deploy@host`. Same host with different users gets different cache keys. One probe may fail while the other succeeds.
- **Fix:** Cache by hostname only (without user prefix). Return user prefix separately for the probe command.

### P-09: SSH `getExitCode()` can return null (signal kill), treated as reachable [MEDIUM]

- **File:** `src/Infrastructure/ExternalTool/GitVersionResolver.php:393`
- **Problem:** `255 !== $process->getExitCode()` is true when exit code is null. Signal-killed SSH process is misclassified as reachable.
- **Fix:**
  ```php
  $exitCode = $process->getExitCode();
  $reachable = null !== $exitCode && 255 !== $exitCode;
  ```

### P-10: `no_availability` counter counts Unknown as unavailable [MEDIUM]

- **File:** `src/Infrastructure/Reporting/ReportContextBuilder.php:220`
- **Problem:** `'available' !== $vcsAvailable` is true for both `'unavailable'` and `'unknown'`. Extensions with indeterminate VCS status inflate the "no availability" stat.
- **Fix:** Check `'unavailable' === $vcsAvailable` (or exclude `'unknown'` from the no-availability count).

### P-11: No scan cap when `installedVersion` is non-null [MEDIUM]

- **File:** `src/Infrastructure/ExternalTool/GitVersionResolver.php:199-205`
- **Problem:** The 50-version cap only applies when `installedVersion` is null. A repo with 200+ tags all newer than `installedVersion` scans uncapped.
- **Fix:** Apply cap unconditionally (or at a higher threshold like 100 when installedVersion is set).

### P-12: Clone cache key uses raw URL — `.git` suffix variants duplicate clones [MEDIUM]

- **File:** `src/Infrastructure/ExternalTool/GitVersionResolver.php:215`
- **Problem:** `git@host:vendor/repo.git` and `git@host:vendor/repo` create two separate clones for the same repo.
- **Fix:** Normalize URL before cache lookup (at minimum strip `.git` suffix).

### P-13: SSH URLs rendered as broken `<a href="">` in HTML templates [MEDIUM]

- **File:** `resources/templates/html/detailed-report.html.twig:314`, `resources/templates/html/partials/extension-detail/version-availability-analysis.html.twig:73`
- **Problem:** `git@github.com:vendor/repo.git` is not a valid browser URL but is rendered as a clickable link.
- **Fix:** Guard with scheme check:
  ```twig
  {% if vcs_source_url starts with 'http' %}
      <a href="{{ vcs_source_url }}" target="_blank">{{ vcs_source_url }}</a>
  {% else %}
      <code>{{ vcs_source_url }}</code>
  {% endif %}
  ```

### P-14: MD detailed-report prints "None" for unknown VCS availability [MEDIUM]

- **File:** `resources/templates/md/detailed-report.md.twig:146`
- **Problem:** `vcs_available != 'available' ? 'None'` catches both `'unavailable'` and `'unknown'`. Same semantic issue as P-05/P-10 in template layer.
- **Fix:** Check `== 'unavailable'` specifically; show "Unknown" for `'unknown'`.

### P-15: 2 MD templates lack `enabled_sources` guard for VCS sections [MEDIUM]

- **Files:**
  - `resources/templates/md/detailed-report.md.twig` (lines 106-116)
  - `resources/templates/md/partials/extension-detail/version-availability-analysis.md.twig` (lines 14-19)
- **Problem:** VCS availability rendered unconditionally, unlike HTML counterparts which check `enabled_sources`.
- **Fix:** Add `{% set enabled_sources = ... %}` and wrap VCS sections in `{% if 'vcs' in enabled_sources %}`.

### P-16: `hasRequiredTools()` can throw uncaught RuntimeException [MEDIUM]

- **File:** `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php:139-142`
- **Problem:** `new Process(['git', '--version'])->run()` can throw `RuntimeException` on some platforms when the binary is absent.
- **Fix:** Wrap in try/catch:
  ```php
  try {
      $process = new Process(['git', '--version']);
      $process->run();
      return $process->isSuccessful();
  } catch (\Symfony\Component\Process\Exception\RuntimeException) {
      return false;
  }
  ```

### P-17: ExtensionDiscoveryServiceTest: vacuous assertions can pass silently [MEDIUM]

- **File:** `tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php`
- **Problem:** `testMissingSourceKeyLeavesRepositoryUrlNull` and `testEmptySourceUrlLeavesRepositoryUrlNull` iterate extensions looking for a specific composer name. If the target is not found, no assertion runs and the test passes.
- **Fix:** Add a `$found = false;` guard and `self::assertTrue($found, 'Target extension not discovered');` after the loop.

### P-18: VcsSource cache rehydration (string to enum) has zero test coverage [MEDIUM]

- **File:** `tests/Unit/Infrastructure/Analyzer/VersionAvailability/Source/VcsSourceTest.php`
- **Problem:** `returnsCachedValueOnCacheHit` returns enum objects from mock, not serialized strings. The rehydration branch at VcsSource.php:68-70 is never exercised.
- **Fix:** Add a test where `cacheService->get()` returns `['vcs_available' => 'available', ...]` and verify the string is rehydrated to `VcsAvailability::Available`.

### P-19: Duplicated string-to-enum normalization in two methods [LOW]

- **File:** `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php:179-181, 229-231`
- **Problem:** Same 3-line pattern appears in `calculateRiskScore` and `addRecommendations`.
- **Fix:** Extract to `private function normalizeVcsAvailability(mixed $value): VcsAvailability`.

### P-20: VcsSource `handleNotFound` docblock still references "Composer" [LOW]

- **File:** `src/Infrastructure/Analyzer/VersionAvailability/Source/VcsSource.php:112-117`
- **Problem:** Post-2-5b, NOT_FOUND means "no URL provided" (from GitVersionResolver), not "Composer said not found."
- **Fix:** Update docblock to reflect git-native resolver semantics.

### P-21: `installationPath` on AnalysisContext is dead code after 2-5b [LOW]

- **Files:** `src/Domain/ValueObject/AnalysisContext.php:25`, `src/Application/Command/AnalyzeCommand.php:227`
- **Problem:** Added for `--working-dir` fallback (2-5a). Entire fallback deleted in 2-5b. No current consumer calls `getInstallationPath()`.
- **Fix:** Remove property, getter, constructor parameter, `with*()` propagations, and AnalyzeCommand wiring. Or defer to Story 2-6.

### P-22: ReportContextBuilderTest uses raw string instead of enum value [LOW]

- **File:** `tests/Unit/Infrastructure/Reporting/ReportContextBuilderTest.php:1908`
- **Problem:** `'vcs_available' => 'available'` — happens to match production data shape but is less explicit.
- **Fix:** Use `VcsAvailability::Available->value` for clarity.

---

## Deferred Findings (15)

These are pre-existing issues, out-of-scope concerns, or design-level decisions not actionable within the current stories.

| ID | Title | Notes |
|----|-------|-------|
| D-01 | installationPath inconsistency with config array | Moot while property is dead code (P-21) |
| D-02 | No path validation on installationPath | Moot while property is dead code |
| D-03 | CacheService::clear() may report success on partial deletion | Pre-existing; ClearCacheCommand not in story scope |
| D-04 | ClearCacheCommand out of scope for stories 2-5/2-5a/2-5b | Separate commit, not part of story ACs |
| D-05 | Clone cleanup not in try/finally per AC-1 spec letter | Equivalent behavior via shutdown + explicit cleanup; spec deviation is form not substance |
| D-06 | isCompatible returns true for no TYPO3 requirements | Intentional design (same as deleted PackagistVersionResolver) |
| D-07 | FAILURE results not cached — repeated resolution for persistent failures | Intentional per 2-5 dev notes ("allow retry") |
| D-08 | Cache key does not include repository URL | Low probability of URL change between runs |
| D-09 | Duplicated SSH URL parsing in VcsSource and GitVersionResolver | Consolidation candidate for Story 2-6 |
| D-10 | `$hasNoDist` heuristic broadens VCS detection beyond spec | Pragmatically useful for SSH-only packages |
| D-11 | VCS-only Unknown yields risk 9.0 — needs "indeterminate" concept | Design-level; needs spec decision |
| D-12 | `curl` in getRequiredTools means PHP extension, not CLI tool | Naming ambiguity, no functional impact |
| D-13 | Label inconsistency: "VCS Available" vs "Git Available" in stats | Cosmetic; clarify per CP-5 convention |
| D-14 | `'github' in enabled_sources` is dead backward-compat code | Cleanup candidate for Story 2-6 |
| D-15 | GitSource.php still exists and produces `git_*` keys | Scheduled for deletion in Story 2-6 |

---

## Recommended Fix Order

**Phase 1 — Blocking / Critical:**
1. P-01: Fix integration test assertion (1-line fix, currently failing)

**Phase 2 — High-severity clusters:**
2. P-02 + P-03: Shutdown reference fix + stale tmpdir guard (GitVersionResolver robustness)
3. P-04: SSH URL normalization (VCS detection correctness)
4. P-05 + P-10 + P-14: Unknown-as-unavailable cluster (misleading reports — 3 files)
5. P-06: DataProvider string normalization (cached data integrity)
6. P-07: "From Source" marker logic (false markers)

**Phase 3 — Medium-severity:**
7. P-08 + P-09: SSH host cache key + null exit code (GitVersionResolver edge cases)
8. P-11 + P-12: Scan cap + clone cache normalization (performance guards)
9. P-13: SSH URL href guard (HTML template fix)
10. P-15: MD template `enabled_sources` guards
11. P-16: hasRequiredTools exception handling
12. P-17 + P-18: Test coverage gaps

**Phase 4 — Low-severity cleanup:**
13. P-19 through P-22: DRY, docblocks, dead code, test style
