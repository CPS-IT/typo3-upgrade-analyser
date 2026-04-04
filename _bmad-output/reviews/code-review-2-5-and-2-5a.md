# Code Review: Stories 2-5 and 2-5a

**Date:** 2026-04-03
**Branch:** feature/2-5-integrate-resolvers-and-update-data-model
**Scope:** All code changes vs main (45 files, ~2775 lines, planning docs excluded)
**Stories:** 2-5 (VCS integration + data model) + 2-5a (VCS detection fix for non-Packagist packages)
**Prior adversarial review:** `feature-2-5a-adversarial-review.md` (CP-1 through CP-12)

---

## Summary

**1 intent gap, 0 bad spec, 7 patch, 3 defer. 5 findings rejected as noise.**

---

## Intent Gaps

### IG-1: AC-5 (SSH connectivity pre-check) not implemented — spec decision needed

Story 2-5a ACs 17-20 require `ssh -T -o ConnectTimeout=5 <host>` connectivity probing before
`--working-dir` fallback for SSH-based packages, with per-host caching and a single WARNING per
unreachable host.

The implementation documents a conscious decision not to implement this:
> `--working-dir` reads local vendor/lock data only, no VCS network access required.

**Resolution:** Implement the SSH pre-check (user decision 2026-04-03).

---

## Patch Findings

### P-1: NOT_FOUND results not cached in VcsSource

`handleNotFound()` returns metrics without calling `cacheAndReturn()`. NOT_FOUND is a definitive
answer (the docblock says so). Every analysis run re-invokes the full Composer subprocess chain
for the same package.

**Location:** `src/Infrastructure/Analyzer/VersionAvailability/Source/VcsSource.php:89`

**Fix:** Wrap the NOT_FOUND match arm in `cacheAndReturn()` the same way RESOLVED_NO_MATCH is.

---

### P-2: Cache key does not include installationPath

The cache key is based only on extension key + target version. If a user runs analysis without an
installationPath (VCS returns NOT_FOUND → Unavailable, now cached), then runs again with
installationPath set, the cached Unavailable result is returned instead of triggering the fallback.

**Location:** `src/Infrastructure/Analyzer/VersionAvailability/Source/VcsSource.php:57-59`

**Fix:** Include `installationPath` (or a hash/empty-string sentinel) in the cache key parameters.

---

### P-3: MixedAnalysisIntegrationTestCase compares VcsAvailability enum with boolean `true` (bug)

Three locations use `true === ($metrics['vcs_available'] ?? null)`. Since `vcs_available` is now
a `VcsAvailability` enum, these never match. VCS-related assertions are silently skipped.

**Locations:**
- `tests/Integration/MixedAnalysisIntegrationTestCase.php:133`
- `tests/Integration/MixedAnalysisIntegrationTestCase.php:149`
- `tests/Integration/MixedAnalysisIntegrationTestCase.php:176`

**Fix:** Replace with `VcsAvailability::Available === ($metrics['vcs_available'] ?? null)`.

---

### P-4: ReportContextBuilderTest uses boolean `true` for `vcs_available` in mock data

Mock data sets `'vcs_available' => true`. `ReportContextBuilder` compares against the string
`'available'`. `true !== 'available'`, so the VCS availability counter stays at 0. Test passes
but validates wrong behavior.

**Location:** `tests/Unit/Infrastructure/Reporting/ReportContextBuilderTest.php:101`

**Fix:** Change mock data to `'vcs_available' => 'available'` (the serialized string value that
`VersionAvailabilityDataProvider` produces).

---

### P-5: Markdown templates still show "VCS" in user-facing labels (CP-5 incomplete)

HTML templates were fixed to show "Git Repository", "Git ✓" etc. Markdown templates still expose
"VCS" to users in two places.

**Locations:**
- `resources/templates/md/main-report.md.twig:26` — "VCS Available:"
- `resources/templates/md/partials/main-report/version-availability-table.md.twig:3,7` — "VCS repositories", "VCS" column header

**Fix:** Replace "VCS" with "Git" in user-facing label text in both Markdown templates.

---

### P-6: linearScan increments scan counter on subprocess failure, consuming scan budget

When `fetchVersionRequires()` returns null (subprocess failure), `$scanned` still increments.
With MAX_LINEAR_SCAN_VERSIONS=50, transient failures consume scan budget. In the worst case all
50 slots are consumed by failures and the scan terminates without checking any version.

**Location:** `src/Infrastructure/ExternalTool/ComposerVersionResolver.php:214-218`

**Fix:** Move `++$scanned` inside a block that only executes when `fetchVersionRequires` returns non-null.

---

### P-7: VcsAvailability dual-representation risks silent scoring bypass via higher-level cache

`VcsSource` rehydrates cached strings back to enum (line 64-65). But `AbstractCachedAnalyzer` also
caches `AnalysisResult` objects containing the raw metrics array. If the analyzer-level cache is hit,
`VersionAvailabilityAnalyzer::calculateRiskScore()` receives the VcsAvailability value from the
serialized metrics — potentially a string if the cache round-tripped through JSON. The `instanceof
VcsAvailability` check then fails silently, and VCS is excluded from risk scoring entirely.

**Location:** `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php` (calculateRiskScore)

**Fix:** Before the instanceof check, normalize string → enum:
```php
if (is_string($vcsAvailable)) {
    $vcsAvailable = VcsAvailability::tryFrom($vcsAvailable) ?? VcsAvailability::Unknown;
}
```

---

## Deferred Findings

Pre-existing issues surfaced by this review. Not caused by the current changes; no action required now.

### D-1: realpath() resolves symlinks, may break relative Composer repository paths

`realpath()` in installationPath validation changes symlinks to physical paths. If the installation
uses symlinks (common in CI/CD, e.g. `/var/www/current -> /var/www/releases/20260403`), relative
repository paths in the local `composer.json` may resolve differently. Not a new issue — was
introduced as CP-3 fix and is an acceptable trade-off.

### D-2: `no_availability` counter includes VCS "unknown" as unavailable

`ReportContextBuilder` increments `no_availability` when VCS is `'unknown'`. Extensions where VCS
resolution failed (network error) are counted the same as extensions with no availability at all.
Inflates the "no availability" stat slightly. Design choice, not a regression.

### D-3: installationPath not validated at AnalysisContext construction

Validation happens downstream in `ComposerVersionResolver`. Acceptable with a single consumer.
Risk if a second consumer is added without the same guard.

---

## Rejected Findings (5)

- Warning dedup key inconsistency when repositoryUrl is null — harmless, dedup falls back to packageName.
- Whitespace-only installationPath — caught downstream by realpath().
- configuredSources type coercion from malformed YAML — Symfony Config validates before it reaches the analyzer.
- Return type annotation `mixed` on normalizedSources lambda — PHPStan Level 8 passes; cosmetic.
- Removed risk score tier 7.0 — branch was unreachable after binary VCS scoring; correctly deleted.

---

## Acceptance Audit Notes

- Story 2-5: All ACs pass. Console warning delivery (AC 11/12) depends on Monolog ConsoleHandler
  configuration, which is not in scope of this review.
- Story 2-5a: AC-5 (SSH pre-check) formally unmet — resolved by implementing it (IG-1 above).
  AC-10 item 39 (SSH connectivity cache test) will be needed once AC-5 is implemented.
- Rework CP-1 through CP-10, CP-12: all verified fixed. CP-5 partially fixed (HTML only) → P-5 above.
