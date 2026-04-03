# Adversarial Review: Feature 2-5a (VCS Detection Fix + Data Model Update)

**Date:** 2026-04-03
**Branch:** feature/2-5-integrate-resolvers-and-update-data-model
**Scope:** Commits 0a9db9d..8f0ef97 (7 commits, ~2400 lines added, ~640 removed)

---

## Findings

### 1. "VCS" is user-hostile terminology in reports

Templates now display "VCS" to end users ("VCS Repository", "VCS ✓", "VCS ?"). No TYPO3 developer thinks in terms of "VCS" — they think "Git", "GitHub", "GitLab". The internal naming `VcsSource` is defensible (it models Composer VCS repositories), but leaking that abstraction into the UI is wrong. The report should say "Git" (or the actual detected provider), not a generic acronym that even a PR reviewer flagged as confusing.

### 2. The `github -> vcs` mapping is still there despite being pre-1.0

`VersionAvailabilityAnalyzer.php:79` and `:121` both do `'github' === $source ? 'vcs' : $source`. Every template also checks `'github' in enabled_sources or 'vcs' in enabled_sources`. This is backwards-compatibility baggage for a tool with no released version. The mapping should be removed and `'github'` should not be a recognized source value at all.

### 3. Deleted `documentation/developers/scoring-system.md` (334 lines) with no replacement

The diff removes the entire scoring documentation. The new VCS scoring model (binary 2-point Available/Unavailable, skip on Unknown) replaces the old health-weighted model but is documented nowhere. The deletion of a significant developer doc with zero replacement is a net loss.

### 4. No configuration documentation for the new source name

If a user has an existing config file referencing `sources: [ter, packagist, git]`, it silently stops working. The old `'git'` source name is no longer valid, but there is no migration note, no deprecation warning at runtime, and no CHANGELOG entry. The `config/services.yaml` diff confirms `GitSource` is gone, replaced by `VcsSource`, but no user-facing docs explain this.

### 5. BUG: Template logic inconsistency — `is same as(true)` vs string comparison

`detailed-report.html.twig:228` uses `data.version_analysis.vcs_available is same as(true)` — a strict boolean check. But `VersionAvailabilityDataProvider` serializes `SourceAvailability` to its string value (`'available'`, `'unavailable'`, `'unknown'`). The string `'available'` is never `same as(true)`. This condition will never match. The overview table row will always show the red cross for VCS regardless of actual availability. This is a confirmed bug.

### 6. `SourceAvailability` lives in Domain but is only meaningful for VCS sources

TER and Packagist still use plain booleans (`ter_available: true/false`, `packagist_available: true/false`). The tri-state enum was introduced specifically for VCS, making the naming `SourceAvailability` misleading — it implies a general-purpose concept but applies to one source only. If this is domain-worthy, all sources should use it. If it is VCS-specific, the name should say so.

### 7. `ComposerVersionResolver` has SSH host probing with security implications

The `isSshHostReachable()` method runs `ssh -T -o ConnectTimeout=5 $host` for arbitrary SSH URLs extracted from `composer.json` repository entries. A malicious repository entry with a crafted hostname could cause the tool to connect to arbitrary SSH servers. The host is extracted from user-provided data without sanitization.

### 8. Linear scan over all versions is unbounded

`ComposerVersionResolver::linearScan` iterates every version calling `composer show --all --format=json $package $version` for each. For packages with hundreds of versions, this spawns hundreds of subprocesses. There is no cap on iteration count. The comment referencing "binary search available in git history" acknowledges this but does nothing about it.

### 9. `VcsSource::handleFailure` conflates NOT_FOUND and FAILURE

Both `VcsResolutionStatus::NOT_FOUND` and `VcsResolutionStatus::FAILURE` return `SourceAvailability::Unknown`. A package genuinely not found (expected for non-VCS packages) and a resolver crash (unexpected) produce identical output. NOT_FOUND should map to `Unavailable`, not `Unknown` — the system asked and got a definitive answer.

### 10. `ReportContextBuilder` compares enum value as string

Line 214+: `SourceAvailability::Available->value === $vcsAvailable`. This works because `VersionAvailabilityDataProvider` already serializes to `->value`. But it means the Domain enum is used both as an enum (in the analyzer) and as a raw string (in reporting). This dual representation invites bugs when someone later passes the enum object instead of its string value.

### 11. No integration or functional tests for the new fallback path

The `--working-dir` fallback in `ComposerVersionResolver::resolve()` is the core fix of story 2-5a, yet there is no integration test exercising it through `VcsSource -> ComposerVersionResolver` with a real or fixture-based Composer project. The existing `VcsDetectionNonPackagistTest` appears to be unit-level with mocked processes.

### 12. `AnalysisContext::installationPath` added with no validation

The new `?string $installationPath` parameter is passed directly into `ComposerVersionResolver` and used in a `--working-dir=` shell argument. If the path contains special characters or is a symlink to an unexpected location, it is used as-is. No `realpath()`, no existence check, no sanitization before being passed to a subprocess command.
