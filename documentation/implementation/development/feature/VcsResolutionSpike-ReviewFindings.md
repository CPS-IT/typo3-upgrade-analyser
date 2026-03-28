# VCS Resolution Spike — Adversarial Review Findings

**Reviewed:** Story 2.0 spike + architecture update (commit `8cca26a`)
**Date:** 2026-03-28
**Status:** All findings resolved (2026-03-28)

---

## Priority 1 — Decisions Required Before Editing

These have architectural implications. Agreement needed before any changes are made.

**Finding 12 — Two-tier rationale is internally inconsistent** — RESOLVED
The architecture frames Tier 2 as "not a fallback for Composer unavailability, but a Packagist-coverage split." Yet `composer` absence is a hard-fail at startup, preventing Tier 2 from running standalone. If the split is purely about Packagist coverage, the hard precondition on `composer` contradicts the stated rationale. Decide: is Tier 2 truly independent of Composer availability or not?
> **Resolution:** Documentation clarification, not an architectural change. The two facts are orthogonal: `composer` is a hard precondition because Tier 1 and the pre-filter depend on it; Tier 2 does not use it but is never intended to operate as a standalone resolution path. Clarifying sentence added to the architecture precondition section.

**Finding 1 — `ComposerVcsResolver` name is misleading** — RESOLVED
After dropping `--working-dir`, Tier 1 only queries Packagist. It is a Packagist client, not a VCS resolver. The name will mislead implementors of Stories 2.2 and 2.3. Decide: rename to `PackagistVersionResolver` (or similar) and update all architecture references.
> **Resolution:** Renamed to `PackagistVersionResolver` in architecture and spike. The class resolves version availability from Packagist; the Composer CLI is an implementation detail. All references updated: file tree, test files, resolution chain, coverage requirements, integration table, requirements mapping.

**Finding 7 — `assertToolsAvailable()` on resolver classes is a SRP violation** — RESOLVED
Pre-flight environment validation is not a resolver concern. Placing it on `ComposerVcsResolver` and `GenericGitResolver` and then calling it from `AnalyzeCommand` mixes infrastructure-discovery state into a data-fetching class. Decide: extract to a dedicated `ToolPreconditionChecker` or similar command-level service.
> **Resolution:** Extracted to `ToolAvailabilityChecker` service in `Infrastructure/ExternalTool/`. Exposes `assertAvailable(string ...$tools): void` and `isAvailable(string $tool): bool`. `AnalyzeCommand` calls `$checker->assertAvailable('composer', 'git')` at startup. Resolver classes have no tool-checking responsibility. Also addresses architecture review recommendation #4 (ExternalToolManager abstraction).

**Finding 6 — Hard precondition error message is unspecified** — RESOLVED
"Fail fast with a clear actionable error message" is stated but neither the spike nor architecture specifies the message content. Without a spec, this will be left to the implementor. Decide: draft the message text in the architecture or spike now.
> **Resolution:** Error message template specified in architecture precondition section: `Required tool '<name>' not found on PATH. Install <Name> (<url>) and ensure it is available in your shell environment.` Concrete messages for `composer` and `git` defined with download URLs. Tool-to-URL mapping is an internal constant of `ToolAvailabilityChecker`.

---

## Priority 2 — Straightforward Documentation Fixes

These do not require decisions — they are clear gaps or omissions in the existing documents.

**Finding 4 — Binary search omission is an NFR risk** — RESOLVED
The spike identifies binary search as the strategy for finding the newest compatible version via versioned calls. The architecture replaced this with "working backwards" — O(N) with no upper bound. For an extension like `ext-solr` (139 versions) where the latest dropped target TYPO3 support, this could mean dozens of 315ms calls for a single extension. Fix: restore the binary search strategy in the architecture's Tier 1 description.
> **Resolution:** Architecture Tier 1 description updated to specify binary search on the version list, reducing worst-case from O(N) to O(log N).

**Finding 3 — Stale `vendor/` not handled** — RESOLVED
The architecture handles absent `vendor/` (soft precondition, skip pre-filter). It says nothing about `vendor/` existing in a stale state (lock updated but `composer install` not run). The pre-filter would silently report packages as up-to-date that have been added since the last install. Fix: add to the constraints table in the spike and the architecture.
> **Resolution:** Stale `vendor/` caveat added to architecture soft precondition section. Constraint added to spike constraints table (Section 10).

**Finding 10 — Transitive VCS-only extensions are a silent scope gap** — RESOLVED
The pre-filter and Tier 1 cover direct deps and Packagist packages. Transitive `typo3-cms-extension` packages sourced from private VCS are the highest-risk category and are not covered. The spike does not assess how common this is in real projects. Fix: document explicitly as a known limitation in the architecture and spike scope section.
> **Resolution:** Known limitation documented in both the architecture (pre-filter section) and spike (Section 1 scope).

**Finding 2 — Composer cache shortcut is fragile and unspecified** — RESOLVED
Section 8 of the spike recommends checking `~/.composer/cache/repo/` as an optimization for VCS-only packages. This path is overridden by `COMPOSER_CACHE_DIR` and `$XDG_CACHE_HOME`. The architecture does not capture this optimization at all. Fix: either drop the recommendation from the spike or document the portability concern and the correct way to resolve the cache path.
> **Resolution:** Cache-check optimization dropped from the spike's recommended approach. Portability concern documented. If revisited, resolve via `composer config cache-repo-dir` rather than hardcoding. Constraint added to spike constraints table.

**Finding 11 — Spike declared Complete but leaves an open evaluation task** — RESOLVED
Section 11 lists `GitVersionParser.php` as "evaluate for reuse in Story 2.3." A completed spike should resolve open questions, not defer them. Fix: evaluate the existing parser against the Tier 2 tag-parsing rules (Section 4.2) and record the result — reuse, modify, or replace.
> **Resolution:** Evaluation completed in spike Section 11. Verdict: replace `GitVersionParser` with new logic in `GenericGitResolver`; reuse `ComposerConstraintCheckerInterface` for TYPO3 compatibility checking. The existing parser lacks tag-name-to-version-string parsing and has an oversimplified compatibility check.

**Finding 5 — HTTP cost of Tier 2 `composer.json` fetch is unquantified** — RESOLVED
The architecture says Tier 2 does "one additional HTTP call" per VCS-only package to fetch `composer.json` from the most recent stable tag. This call was never benchmarked. For 40 private extensions on a slow internal GitLab instance, 40 raw-file HTTP fetches are not equivalent to the ls-remote calls already measured. Fix: add as an explicitly unverified assumption in the spike's constraints table.
> **Resolution:** Unverified assumption caveat added to both the architecture (Tier 2 limitation note) and spike constraints table.

**Finding 9 — Parallelism never considered** — RESOLVED
Both tiers are only evaluated as serial workloads. 40 × 315ms = ~12.6s and 40 × 562ms = ~22.5s are within NFR, but serial is not the only option. Parallel subprocess execution is not mentioned anywhere. Fix: add a note to the architecture acknowledging this as a future optimization path.
> **Resolution:** Future optimization note added to architecture resolution chain (step 6) acknowledging parallel subprocess execution as a viable path if serial timings become a bottleneck.

**Finding 8 — Benchmarks are macOS-only; Xdebug-off timings are extrapolated not measured** — RESOLVED
All benchmarks were taken on macOS with Homebrew Composer. "~315ms without Xdebug" is derived by subtracting measured overhead — not verified by a separate run. Linux CI environments and cold-cache conditions are not represented. Fix: add a caveat to the spike's environment section.
> **Resolution:** Benchmark environment caveat added to spike Section 2. Absolute timings are order-of-magnitude estimates; only relative comparisons between command variants are reliable.
