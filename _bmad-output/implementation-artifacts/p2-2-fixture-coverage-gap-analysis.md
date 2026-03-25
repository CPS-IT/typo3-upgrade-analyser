# Story P2-2: Fixture Coverage Gap Analysis for Epic 2

Status: ready-for-dev

## GitHub Issue

[#186 — Task: Pre-Epic-2 Fixture Coverage Gap Analysis](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/186)

## Story

As a developer,
I want to know which Composer source entry formats are not covered by existing test fixtures before Story 2.1 (ComposerSourceParser) begins,
so that the parser is designed and tested against realistic data from the start — avoiding the CI failure pattern that drove Stories 1.8 and 1.9.

## Motivation

In Epic 1, v14 integration tests failed in CI because fixtures only worked locally where runtime directories existed. The root cause was that fixtures were created without verifying what real installations actually look like. Story 2.1 will parse `composer.json` source entries for GitLab and Bitbucket repositories — these entries have non-obvious formats that differ across GitLab versions, hosting models (SaaS vs self-hosted), and repository types (public, private with token, SSH).

## Acceptance Criteria

1. A gap analysis document is produced at `_bmad-output/planning-artifacts/fixture-coverage-gap-analysis.md` that lists:
   - All `composer.json` `repositories` entry types currently covered by test fixtures (with fixture file paths)
   - Entry types required for Epic 2 stories 2.1–2.4 that have no corresponding fixture
2. The following source formats are specifically evaluated for fixture coverage:
   - GitLab SaaS (`gitlab.com`) public repository
   - GitLab SaaS private repository (token-based)
   - GitLab self-hosted instance (custom domain)
   - Bitbucket public repository
   - Bitbucket private repository
   - GitHub private repository (for completeness, as `GitHubClient` already exists)
   - `path` type source (local package — relevant for issue #149)
3. For each missing fixture, a minimal fixture structure is documented (which files are needed: `composer.json`, `composer.lock`, `vendor/composer/installed.json` entries).
4. The document includes a recommendation: which missing fixtures should be created as part of Story 2.1, and which require a dedicated spike (P2-3).
5. Existing fixtures (`tests/Fixtures/`) are enumerated to confirm v11/v12/v13/v14 Composer and legacy coverage is complete.

## Tasks / Subtasks

- [ ] Task 1: Enumerate existing fixtures
  - [ ] List all directories under `tests/Fixtures/` and document what each covers
  - [ ] Confirm integration tests reference them correctly

- [ ] Task 2: Research Composer source entry formats
  - [ ] Document the `repositories` key format for each GitLab/Bitbucket variant (from Composer docs)
  - [ ] Document how these entries appear in `composer.lock` resolved packages (the `source` key)
  - [ ] Document how they appear in `vendor/composer/installed.json`

- [ ] Task 3: Cross-reference with Epic 2 story requirements
  - [ ] Map each 2.1–2.4 story to the source entry formats it must handle
  - [ ] Identify gaps: required formats with no fixture

- [ ] Task 4: Produce gap analysis document and fixture templates
  - [ ] Write `_bmad-output/planning-artifacts/fixture-coverage-gap-analysis.md`
  - [ ] For each gap, provide minimal fixture file content (JSON snippets)
  - [ ] Mark fixtures that can be created inline in 2.1 vs those needing the spike (P2-3)

## Output Artifact

`_bmad-output/planning-artifacts/fixture-coverage-gap-analysis.md`

## Notes

This is a research and documentation task. Fixture files are documented here but created in P2-3 or Story 2.1 depending on complexity.
