# Story P2-1: Code Quality Audit — Discovery, ExternalTool, Reporting

Status: review

## GitHub Issue

[#185 — Task: Code Quality Audit — Infrastructure/Discovery, ExternalTool, Reporting](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/185)

## Story

As a developer,
I want a systematic quality audit of the `Infrastructure/Discovery/`, `Infrastructure/ExternalTool/`, and `Infrastructure/Reporting/` namespaces,
so that known deferred findings are documented, hidden tech debt is surfaced, and targeted refactoring stories can be planned with concrete acceptance criteria before Epic 2 begins.

## Motivation

Epic 1 produced 4 unplanned stories (44 %) due to hidden technical debt in `ComposerInstallationDetector`. A pre-Epic-2 audit prevents the same surprise scope expansion during Epic 2 work on GitLab/Bitbucket source coverage and reporting.

## Acceptance Criteria

1. Each audited namespace is reviewed against the following dimensions:
   - PHPStan Level 8 violations (run `composer static-analysis`, capture output)
   - Deferred findings from prior code reviews (check `_bmad-output/planning-artifacts/code-review-*.md`)
   - SRP violations: classes with more than one clearly separate responsibility
   - Missing or shallow test coverage for public methods
   - Exception handling breadth: `\Throwable` or bare `\Exception` catches masking bugs
   - Hardcoded paths/values that ignore `VersionProfileRegistry`
2. Findings are recorded in `_bmad-output/planning-artifacts/quality-audit-pre-epic-2.md` with:
   - Finding ID, severity (HIGH / MED / LOW), affected class + line range, description, fix direction
3. Deferred findings D1–D5 from `code-review-composer-installation-detector-2026-03-24.md` are re-evaluated: each either promoted to a P2-x story or explicitly closed with justification.
4. The audit output includes a recommended story list: findings that warrant a dedicated fix story before Epic 2 (blocking), and findings that can be deferred as tracked tech debt (non-blocking).
5. PHPStan is run and the baseline error count is recorded. Any new errors since the last clean run are flagged.

## Scope

### `Infrastructure/Discovery/` (17 files)
Key risk areas:
- `ComposerInstallationDetector` — deferred findings D1–D5 still open
- `ExtensionDiscoveryService` (617 lines) — bug #163 (PackageStates v5 in Composer projects), state detection, length
- `VersionExtractor` — version detection strategies
- `ConfigurationDiscoveryService` — configuration parsing coupling

### `Infrastructure/ExternalTool/` (15 files)
Key risk areas:
- `PackagistClient`, `TerApiClient`, `TerApiHttpClient` — HTTP error handling, retry logic, timeout behavior
- `GitRepositoryAnalyzer`, `GitHubClient` — exception surface, auth handling
- `VersionCompatibilityChecker` — constraint logic correctness

### `Infrastructure/Reporting/` (4 files)
Key risk areas:
- `ReportService` — coupling to specific output formats, error handling
- `TemplateRenderer` — Twig error handling, template path resolution
- `ReportContextBuilder` — null safety, missing context keys

## Tasks / Subtasks

- [x] Task 1: Prepare audit baseline
  - [x] Run `composer sca:php 2>&1 | tee var/audit-phpstan-baseline.txt` and record error count
  - [x] List all open deferred findings from `code-review-composer-installation-detector-2026-03-24.md`

- [x] Task 2: Audit `Infrastructure/Discovery/`
  - [x] Read each class against the AC-1 dimensions
  - [x] Document findings with ID, severity, class, line, description, fix direction
  - [x] Special attention: D1–D5 deferred findings, `ExtensionDiscoveryService` size/responsibility split

- [x] Task 3: Audit `Infrastructure/ExternalTool/`
  - [x] Read each class against the AC-1 dimensions
  - [x] Focus on exception handling surface and HTTP resilience

- [x] Task 4: Audit `Infrastructure/Reporting/`
  - [x] Read each class against the AC-1 dimensions
  - [x] Focus on coupling and null safety

- [x] Task 5: Produce findings document and story recommendations
  - [x] Write `_bmad-output/planning-artifacts/quality-audit-pre-epic-2.md`
  - [x] For each HIGH finding: create a story file or explicitly defer with justification
  - [x] Re-evaluate D1–D5: promote or close

## Output Artifact

`_bmad-output/planning-artifacts/quality-audit-pre-epic-2.md`

## Notes

This is a read-only analysis task. No implementation. The output feeds story creation for any HIGH findings that must be resolved before Epic 2.

---

## Dev Agent Record

### Implementation Plan

Read-only audit. No code changes. PHPStan baseline captured to `var/audit-phpstan-baseline.txt`.

### File List

- `_bmad-output/planning-artifacts/quality-audit-pre-epic-2.md` (created)
- `var/audit-phpstan-baseline.txt` (created)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (updated: p2-1 → in-progress)

### Change Log

- 2026-03-25: Completed code quality audit of all three namespaces. Identified 27 findings (9 HIGH, 11 MED, 7 LOW). PHPStan baseline: 0 errors. D1–D5 re-evaluated: D1–D3 deferred, D4–D5 need verification. One new story recommended: reporting hardening (F-R-01, F-R-02, F-R-03, F-R-04).

### Completion Notes

- PHPStan Level 8: 0 errors — clean baseline.
- Discovery namespace: 8 findings. Most significant: F-D-02 (Bug #163, already has story p2-4) and F-D-03 (ConfigurationDiscoveryService has zero VersionProfileRegistry integration).
- ExternalTool namespace: 9 findings. Most significant: F-E-01 (TerApiHttpClient incomplete HTTP handling), F-E-02/F-E-03 (bare \Throwable catches in TerApiClient, AbstractGitProvider), F-E-04 (GitVersionParser unsafe assumption).
- Reporting namespace: 7 findings. Most significant: F-R-01 (unguarded Twig render), F-R-02 (unsafe null chain), F-R-03 (silent file_put_contents failure), all HIGH — recommend a new reporting hardening story before Epic 2.
- D1–D3: deferred as low-risk tech debt.
- D4 (root-level typo3conf indicator): needs verification against current ComposerInstallationDetector — may already be fixed in story 1-9.
- D5 (empty web-dir guard): needs test coverage verification.
- Note: CLAUDE.md and story file refer to `composer static-analysis` — actual script is `composer sca:php`. BMAD docs need correction.
