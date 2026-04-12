# Sprint Change Proposal — GitHub Issue Triage 2026-04-12

**Date:** 2026-04-12
**Scope:** Moderate
**Trigger:** Review of all open GitHub issues against Epic 2 (winding down) and Epic 3+ backlog

---

## Section 1: Issue Summary

12 open GitHub issues evaluated. Epic 2 winding down (2-6 in review in separate branch, 2-7 backlog). Epic 3 not started. Pre-Epic-3 reporting hardening in backlog.

No issues invalidate the current epic structure. All can be absorbed as new stories, pre-epic tasks, maintenance PRs, or Phase 2 deferrals.

---

## Section 2: Impact Analysis

| Issue | Type | Verdict | Slot |
|---|---|---|---|
| #223 Packagist false negative | bug | Data accuracy, inflates risk scores | New Story 2.8 before 2-7 |
| #219 Report tool version | enhancement (Critical) | Simple, cheapest before streaming refactor | New Story 5.0, pulled before Epic 3 |
| #224 Zero-findings rows hidden | bug | Misleading tables, needs design decision | Pre-Epic-3 Task 7 |
| #202 Unused detailed templates | cleanup | Decision before streaming touches templates | Pre-Epic-3 Task 8 |
| #207 CI php-cs-fixer twice | bug | Single-line YAML fix, no story needed | Standalone maintenance PR |
| #199 Renovate major version PRs | maintenance | Configure packageRules | Standalone maintenance PR |
| #231 Installation name/description | enhancement | Config + report metadata, Medium priority | New Story 5.7 in Epic 5 |
| #230 Config schema validation | enhancement | config:validate command, Medium priority | New Story 5.8 in Epic 5 |
| #220 Scoring versioning | enhancement | Complex, requires stable scoring model first | Phase 2 Growth defer |
| #197 Package deprecation warnings | enhancement | Extends FR15, Phase 2 scope | Phase 2 Growth defer |
| #150 Direct/indirect extensions | enhancement | Already tracked as Story 2-7 | No change |
| #7 Renovate dashboard | automated | PRs for symfony/v8 and phpunit/v13 correctly blocked | No action |

**Sprint-status correction:** Story 2-6 is currently in review in a separate branch; sprint-status.yaml incorrectly shows `ready-for-dev`.

---

## Section 3: Recommended Approach — Direct Adjustment

All issues absorbed into existing epic structure. No rollbacks or MVP scope changes required.

**Proposed sprint sequence:**

```
2-6 (in review, other branch)
  ↓
2-8: Fix Packagist latest-compatible false negative  [NEW]
  ↓
2-7: Direct/indirect extension distinction
  ↓
Epic 2 retrospective
  ↓
Pre-Epic-3 reporting hardening (existing tasks + Task 7: zero-findings fix + Task 8: remove unused templates)
  ↓
Story 5.0: Include tool version in reports  [NEW, pulled forward]
  ↓
Epic 3 (streaming)
  ↓
Epic 4 (customer reports)
  ↓
Epic 5 (5-1 through 5-8, including new 5.7 + 5.8)

Maintenance PRs (independent, no sequencing constraint):
  - Fix CI php-cs-fixer duplicate (#207)
  - Fix Renovate config (#199)
```

**Rationale for 2.8 before 2-7:** Issue #223 is a live data-accuracy bug. Risk scores for `georgringer/news`, `friendsoftypo3/tt-address`, and similar well-maintained packages are inflated and recommendations are misleading. Fixing it before 2-7 ensures Epic 2 ships with correct output.

**Rationale for Story 5.0 before Epic 3:** Adding tool version touches `ReportContextBuilder` and templates. Epic 3 Story 3-3 restructures both. Implementing beforehand is cheap; doing it after the streaming refactor introduces merge risk.

---

## Section 4: Detailed Change Proposals

### A. New Story 2.8 — Fix Packagist Latest-Compatible False Negative

**Source:** Issue #223
**Epic 2, insert before 2-7**

Story: As a developer, I want `packagist_latest_compatible` to correctly reflect compatibility when the chronologically latest Packagist version supports the target TYPO3 version, so risk scores are not inflated for well-maintained community packages.

Root cause: `PackagistClient::getLatestVersionInfo` fetches the chronologically latest version, then checks only that single version against TYPO3 constraints. `ComposerConstraintChecker::isConstraintCompatible` fails on compound constraint formats (`||`), producing false negatives.

**Acceptance Criteria:**
- `packagist_latest_compatible: true` when latest version's TYPO3 require constraint covers the target version
- Covers confirmed cases: `georgringer/news` v14.0.1 and `friendsoftypo3/tt-address` v10.0.0 targeting TYPO3 13.4
- `ComposerConstraintChecker` handles compound constraints (`^`, `~`, `||`, ranges)
- Risk score for extensions with `packagist_available: true` and `packagist_latest_compatible: true` is ≤ 20
- Recommendation includes the compatible version string (e.g., "Version 14.0.1 is compatible with TYPO3 13.4")
- Unit tests: simple `^`, compound `||`, constraint mismatch, missing constraint key
- PHPStan Level 8: zero errors

---

### B. Pre-Epic-3 additions — Task 7 and Task 8

**Source:** Issues #224, #202

Add to `pre-epic-3-reporting-hardening.md`:

#### Task 7 — Fix zero-findings table visibility (#224)

Design decision: **Option B** — show a summary count for passing extensions above the table, keep table rows filtered to non-zero findings. Avoids table bloat on large installations while making "analyzed and passed" explicit rather than invisible.

Changes (all 4 template files):
- Add summary count line above table for extensions with zero findings
- Remove the `> 0` guard from the `{% if %}` condition that gates the entire table row
- Affected: `resources/templates/html/partials/main-report/rector-analysis-table.html.twig:18`, `fractor-analysis-table.html.twig:18`, `resources/templates/md/partials/main-report/rector-analysis-table.md.twig:8`, `fractor-analysis-table.md.twig:8`

#### Task 8 — Remove unused detailed report templates (#202)

Decision: Remove. Epic 3/4 will introduce customer templates via proper inheritance. The unused files create confusion about what is rendered.

- Delete `resources/templates/html/detailed-report.html.twig`
- Delete `resources/templates/md/detailed-report.md.twig`
- Verify no service or command references them before deletion
- Close GitHub issue #202 after merge

---

### C. New Story 5.0 — Include Tool Version in Reports

**Source:** Issue #219 (Priority: Critical per reporter)
**Pulled forward — implement before Epic 3 begins**

Story: As a developer, I want every generated report to include the typo3-upgrade-analyser version used for the analysis, so I can determine whether two runs are comparable and whether a report reflects the current tool.

**Acceptance Criteria:**
- Version read from `composer.json` `version` field or a generated `VERSION` constant
- Version included in HTML and Markdown report headers
- Version included in JSON output under top-level `"meta": { "analyzerVersion": "..." }`
- `ReportContextBuilder::buildReportContext()` receives and passes version through context
- Graceful fallback to `"unknown"` when version cannot be determined
- Unit tests: version present in context, fallback when source unavailable
- PHPStan Level 8: zero errors

---

### D. New Story 5.7 — Installation Name and Description in Configuration

**Source:** Issue #231
**Epic 5, append after 5-5**

Story: As a developer, I want to assign a human-readable name and description to an analyzed installation via configuration, so reports identify the project clearly in team communication and customer presentations.

**Acceptance Criteria:**
- Config schema adds: `installation.name` (string, optional), `installation.description` (string, optional), `installation.path` (alias for `installationPath`)
- Root-level `installationPath` still accepted for backwards compatibility (no breaking change)
- Name and description appear in HTML/Markdown report headers and JSON `installation` block
- Omitted: falls back to filesystem path as display name, no error
- PHPStan Level 8: zero errors

---

### E. New Story 5.8 — Configuration Schema and Validation Command

**Source:** Issue #230
**Epic 5, append after 5-7**

Story: As a developer, I want to validate my configuration file against a formal schema before running analysis, so I get clear error messages for invalid values rather than silent mis-configuration.

**Acceptance Criteria:**
- JSON Schema file at `resources/schema/typo3-analyzer-config.schema.json`
- `config:validate [--config=path]` command validates YAML config against schema
- Clear per-violation output: field path + expected type + found value
- Schema covers all fields from FR38-FR40 configuration surface
- Schema referenced in documentation
- PHPStan Level 8: zero errors

---

### F. Sprint-Status Correction: 2-6 → `review`

Story 2-6 (`remove-git-provider-subsystem`) is currently under review in a separate branch. Sprint-status entry corrected from `ready-for-dev` to `review`.

---

### G. Phase 2 deferrals (no sprint action)

- **#220 Scoring versioning:** Add as note to Epic 6 or a new Epic 7. Requires stable scoring model and production run history.
- **#197 Package deprecation/rename warnings:** Extends FR15 (abandoned extension detection). Phase 2 growth after reporting pipeline stabilizes.

---

## Section 5: Implementation Handoff

**Scope: Moderate**

| Role | Action |
|---|---|
| SM/PO | Update `sprint-status.yaml`: 2-6 → review; add 2-8, 5-0, 5-7, 5-8; update last_updated |
| SM/PO | Update `epics.md`: add Story 2.8 to Epic 2, Story 5.0 and sequence note, 5.7/5.8 to Epic 5 |
| SM/PO | Update `pre-epic-3-reporting-hardening.md`: add Task 7 and Task 8 |
| Dev | Story 2-8: fix `PackagistClient` + `ComposerConstraintChecker` compound constraint handling |
| Dev | Pre-Epic-3 Task 7: fix zero-findings table templates |
| Dev | Pre-Epic-3 Task 8: delete unused detailed-report templates |
| Dev | Story 5.0: add tool version to `ReportContextBuilder` and all report formats |
| Anyone | Maintenance PR: fix CI php-cs-fixer duplicate (#207) |
| Anyone | Maintenance PR: fix Renovate config for major version constraints (#199) |

**Success criteria:**
- Epic 2 closes with accurate Packagist risk scoring (2-8 merged before 2-7)
- Pre-Epic-3 hardening complete before streaming refactor begins
- Reports include tool version before Epic 3 restructures the rendering pipeline
