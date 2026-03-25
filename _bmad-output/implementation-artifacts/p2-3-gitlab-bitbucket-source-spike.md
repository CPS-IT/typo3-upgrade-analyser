# Story P2-3: Discovery Spike — GitLab/Bitbucket Composer Source Entries

Status: backlog

## GitHub Issue

[#187 — Spike: GitLab/Bitbucket Composer Source Entry Fixtures and Design Note](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/187)

## Story

As a developer,
I want validated test fixtures and a design note for how `ComposerSourceParser` (Story 2.1) will identify GitLab and Bitbucket source entries from `composer.lock` and `vendor/composer/installed.json`,
so that Story 2.1 starts from a proven data model and no CI surprises arise from untested fixture formats.

## Prerequisite

P2-2 (Fixture Coverage Gap Analysis) must be done. This spike addresses only the gaps marked "needs spike" in the gap analysis document.

## Acceptance Criteria

1. At least one valid fixture exists for each of the following source types:
   - GitLab SaaS public repository
   - GitLab SaaS private repository
   - GitLab self-hosted instance
   - Bitbucket public repository
   - Bitbucket private repository
2. Each fixture includes:
   - A minimal `composer.json` with a `repositories` entry referencing the source
   - A `composer.lock` excerpt showing the resolved `source` key (type, url, reference)
   - A `vendor/composer/installed.json` excerpt showing the same package
3. A design note (`_bmad-output/planning-artifacts/composer-source-parser-design-note.md`) documents:
   - The field(s) in `composer.lock` / `installed.json` that uniquely identify GitLab vs Bitbucket vs GitHub sources
   - Edge cases: self-hosted GitLab on non-standard ports, Bitbucket with SSH URL, packages with `"type": "path"`
   - Which fields `ComposerSourceParser` should use as primary vs fallback identification
4. All fixture files are placed under `tests/Fixtures/ComposerSources/` with a `README.md` explaining each.
5. A simple smoke test (no parser logic yet) asserts that each fixture file is valid JSON and contains the expected source fields. This test fails if fixtures are malformed.

## Tasks / Subtasks

- [ ] Task 1: Create fixture directory structure
  - [ ] `tests/Fixtures/ComposerSources/GitLabSaasPublic/`
  - [ ] `tests/Fixtures/ComposerSources/GitLabSaasPrivate/`
  - [ ] `tests/Fixtures/ComposerSources/GitLabSelfHosted/`
  - [ ] `tests/Fixtures/ComposerSources/BitbucketPublic/`
  - [ ] `tests/Fixtures/ComposerSources/BitbucketPrivate/`
  - [ ] `tests/Fixtures/ComposerSources/README.md`

- [ ] Task 2: Populate fixture files
  - [ ] `composer.json` with `repositories` entry for each type
  - [ ] `composer.lock` excerpt (packages array entry with `source` key)
  - [ ] `installed.json` excerpt

- [ ] Task 3: Write design note
  - [ ] Document identification fields and edge cases
  - [ ] Include decision: URL pattern match vs `type` field vs `dist.url` fallback

- [ ] Task 4: Write fixture smoke tests
  - [ ] `tests/Unit/Fixtures/ComposerSourceFixtureIntegrityTest.php`
  - [ ] Assert JSON validity and presence of required fields for each fixture

- [ ] Task 5: Quality gate
  - [ ] `composer test` — all tests green
  - [ ] `composer static-analysis` — zero errors
  - [ ] `composer cs:check` — zero violations

## Output Artifacts

- `tests/Fixtures/ComposerSources/` — fixture files
- `_bmad-output/planning-artifacts/composer-source-parser-design-note.md` — design note

## Notes

This spike produces no parser implementation. It exists solely to de-risk Story 2.1 by validating the data model before any parser code is written.
