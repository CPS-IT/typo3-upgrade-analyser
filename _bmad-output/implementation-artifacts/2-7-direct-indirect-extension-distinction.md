# Story 2-7: Distinguish Direct and Indirect Extension Dependencies (Issue #150)

Status: backlog

## GitHub Issue

[#150 — Feature: List direct and indirect extensions](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/150)

## Placement

Epic 2 (Complete Extension Source Coverage), Story 2-7. Placed after Story 2-6 (GitProvider cleanup). Natural companion to Story 2-1 (ComposerSourceParser) — the direct/indirect data is a side effect of reading `composer.json` require keys alongside `composer.lock`.

## Story

As a developer analyzing a TYPO3 Composer installation,
I want the report to distinguish between directly required extensions (in `composer.json`) and transitively required extensions (only in `composer.lock`),
so that I can focus upgrade effort on packages I own and understand that transitive packages will be handled by their direct parent.

## Problem

Currently, the tool checks all packages from `composer.lock` equally. This produces false urgency for transitive dependencies like `linawolf/list-type-migration`: if the declaring extension ships a compatible version, the transitive package requires no manual action. Surfacing this distinction prevents noise in the upgrade report.

## Acceptance Criteria

1. Each extension in the analysis result has an `isDirect` boolean property:
   - `true` if the package is listed in `composer.json` `require` or `require-dev`
   - `false` if it is only present in `composer.lock` (transitive dependency)
2. The HTML and JSON reports show a visual or data distinction between direct and indirect extensions.
3. Indirect extensions that have a compatible version available via their direct parent do not generate a HIGH risk warning in the report (they may still appear as informational).
4. A dependency tree is not required in this story — the direct/indirect flag is sufficient.
5. Legacy installations (without `composer.json`) treat all discovered extensions as "direct" (no distinction possible).
6. PHPStan Level 8 zero errors, all tests green.

## Tasks / Subtasks

- [ ] Task 1: Add `isDirect` property to `Extension` entity
  - [ ] Add `isDirect: bool` to the `Extension` class with appropriate getter/setter
  - [ ] Default: `true` for legacy installations

- [ ] Task 2: Populate `isDirect` during extension discovery
  - [ ] In `ExtensionDiscoveryService` or `ComposerSourceParser`, read `composer.json` `require` + `require-dev` keys
  - [ ] For each discovered extension, set `isDirect = true` if its Composer name appears in either require section

- [ ] Task 3: Update version availability analysis
  - [ ] In `VersionAvailabilityAnalyzer` (or report context builder), skip HIGH risk warning for indirect extensions that have an available parent-compatible version
  - [ ] Indirect extensions with no clear parent path still get full analysis

- [ ] Task 4: Update report templates
  - [ ] Add "Direct / Indirect" column or badge to HTML report extension table
  - [ ] Add `is_direct` field to JSON report output

- [ ] Task 5: Tests and quality gate
  - [ ] Unit tests for direct/indirect detection logic
  - [ ] Integration test: fixture with known direct and transitive packages
  - [ ] `composer test` green, `composer static-analysis` zero errors

## Notes

Out of scope: full dependency tree visualization. That can be a follow-on story.
Scope decision to be made at Epic 2 kickoff: include as 2.5 or defer.
