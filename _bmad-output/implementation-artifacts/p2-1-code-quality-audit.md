# Story P2-1: Code Quality Audit — Discovery, ExternalTool, Reporting

Status: ready-for-dev

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

- [ ] Task 1: Prepare audit baseline
  - [ ] Run `composer static-analysis 2>&1 | tee var/audit-phpstan-baseline.txt` and record error count
  - [ ] List all open deferred findings from `code-review-composer-installation-detector-2026-03-24.md`

- [ ] Task 2: Audit `Infrastructure/Discovery/`
  - [ ] Read each class against the AC-1 dimensions
  - [ ] Document findings with ID, severity, class, line, description, fix direction
  - [ ] Special attention: D1–D5 deferred findings, `ExtensionDiscoveryService` size/responsibility split

- [ ] Task 3: Audit `Infrastructure/ExternalTool/`
  - [ ] Read each class against the AC-1 dimensions
  - [ ] Focus on exception handling surface and HTTP resilience

- [ ] Task 4: Audit `Infrastructure/Reporting/`
  - [ ] Read each class against the AC-1 dimensions
  - [ ] Focus on coupling and null safety

- [ ] Task 5: Produce findings document and story recommendations
  - [ ] Write `_bmad-output/planning-artifacts/quality-audit-pre-epic-2.md`
  - [ ] For each HIGH finding: create a story file or explicitly defer with justification
  - [ ] Re-evaluate D1–D5: promote or close

## Output Artifact

`_bmad-output/planning-artifacts/quality-audit-pre-epic-2.md`

## Notes

This is a read-only analysis task. No implementation. The output feeds story creation for any HIGH findings that must be resolved before Epic 2.
