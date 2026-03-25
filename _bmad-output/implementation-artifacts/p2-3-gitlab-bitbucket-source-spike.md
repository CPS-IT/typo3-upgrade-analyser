# Story P2-3: Discovery Spike — GitLab/Bitbucket Composer Source Entries

Status: ready-for-dev

## GitHub Issue

[#187 — Spike: GitLab/Bitbucket Composer Source Entry Fixtures and Design Note](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/187)

## Story

As a developer,
I want validated test fixtures and a design note for how `ComposerSourceParser` (Story 2.1) will identify GitLab and Bitbucket source entries from `composer.lock` and `vendor/composer/installed.json`,
so that Story 2.1 starts from a proven data model and no CI surprises arise from untested fixture formats.

## Prerequisite

P2-2 (Fixture Coverage Gap Analysis) must be `done` and `_bmad-output/planning-artifacts/fixture-coverage-gap-analysis.md` must exist. This spike addresses only the gaps marked "needs spike" in that document.

If P2-2 is not yet done, do not start this story.

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

- [ ] Task 1: Read gap analysis document
  - [ ] Load `_bmad-output/planning-artifacts/fixture-coverage-gap-analysis.md`
  - [ ] Confirm which fixture types are marked "needs spike" — implement only those

- [ ] Task 2: Create fixture directory structure
  - [ ] `tests/Fixtures/ComposerSources/GitLabSaasPublic/`
  - [ ] `tests/Fixtures/ComposerSources/GitLabSaasPrivate/`
  - [ ] `tests/Fixtures/ComposerSources/GitLabSelfHosted/`
  - [ ] `tests/Fixtures/ComposerSources/BitbucketPublic/`
  - [ ] `tests/Fixtures/ComposerSources/BitbucketPrivate/`
  - [ ] `tests/Fixtures/ComposerSources/README.md`

- [ ] Task 3: Populate fixture files (per directory)
  - [ ] `composer.json` with `repositories` entry for each type
  - [ ] `composer.lock` excerpt (packages array entry with `source` key)
  - [ ] `installed.json` (vendor/composer/installed.json format)

- [ ] Task 4: Write design note
  - [ ] Document primary identification field: `source.url` domain in composer.lock
  - [ ] Document fallback: `repositories[].url` in composer.json (for unresolved packages)
  - [ ] Document edge cases (non-standard ports, SSH URLs, path type)
  - [ ] Document decision: URL pattern match (domain-based) vs `type` field vs `dist.url`

- [ ] Task 5: Write fixture smoke tests
  - [ ] `tests/Unit/Fixtures/ComposerSourceFixtureIntegrityTest.php`
  - [ ] Assert JSON validity for each composer.lock and installed.json fixture
  - [ ] Assert presence of `source.type`, `source.url`, `source.reference` fields
  - [ ] Assert `repositories` entry present in each composer.json fixture

- [ ] Task 6: Quality gate
  - [ ] `composer test` — all tests green
  - [ ] `composer sca:php` — zero PHPStan errors
  - [ ] `composer lint:php` — zero CS violations

## Output Artifacts

- `tests/Fixtures/ComposerSources/` — fixture files
- `_bmad-output/planning-artifacts/composer-source-parser-design-note.md` — design note

## Dev Notes

### This Is a Research Spike — No Parser Code

No `ComposerSourceParser` or `DeclaredRepository` implementation in this story. The sole outputs are fixture files, a design note, and a smoke test that validates fixture integrity.

### Fixture File Formats

**`composer.json` — `repositories` section format:**

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://gitlab.com/vendor/extension-name"
    }
  ]
}
```

For private instances: same structure, URL uses the custom domain.
For SSH: `"url": "git@gitlab.com:vendor/extension-name.git"`.

**`composer.lock` — `packages[].source` format:**

```json
{
  "packages": [
    {
      "name": "vendor/extension-name",
      "version": "1.2.3",
      "source": {
        "type": "git",
        "url": "https://gitlab.com/vendor/extension-name.git",
        "reference": "abc123def456"
      }
    }
  ]
}
```

`source.type` is always `"git"` for VCS repositories — it is NOT `"gitlab"` or `"bitbucket"`.
Provider identification must use `source.url` domain, not `source.type`.

**`vendor/composer/installed.json` format:**

```json
{
  "packages": [
    {
      "name": "vendor/extension-name",
      "version": "1.2.3",
      "source": {
        "type": "git",
        "url": "https://gitlab.com/vendor/extension-name.git",
        "reference": "abc123def456"
      }
    }
  ],
  "dev": true,
  "dev-package-names": []
}
```

### GitLab vs Bitbucket URL Patterns

| Type | Domain | Example URL |
|------|--------|-------------|
| GitLab SaaS public | `gitlab.com` | `https://gitlab.com/owner/repo` |
| GitLab SaaS private | `gitlab.com` | `https://gitlab.com/owner/private-repo` (no structural difference from public — auth at API level) |
| GitLab self-hosted | custom domain | `https://git.example.com/owner/repo` |
| Bitbucket public | `bitbucket.org` | `https://bitbucket.org/owner/repo` |
| Bitbucket private | `bitbucket.org` | `https://bitbucket.org/owner/private-repo` (same URL pattern) |

Primary identification strategy: domain matching on `source.url` in composer.lock.
- `gitlab.com` → GitLab SaaS
- `bitbucket.org` → Bitbucket
- Other domains → potential self-hosted (requires explicit provider config in `.typo3-analyzer.yaml`)

### Architecture Constraints for Story 2.1 (Document in Design Note)

From `_bmad-output/planning-artifacts/architecture.md`:

- `ComposerSourceParser` lives in `Infrastructure/Discovery/` — no domain-layer imports
- `DeclaredRepository` value object lives in `Infrastructure/ExternalTool/`
- `DeclaredRepository` fields: `url: string`, `type: string`, `packages: array<string>`
- Provider resolution order: known public hosts → configured private providers → HTTPS fallback
- Known public hosts: `github.com`, `gitlab.com`, `bitbucket.org` — exact domain match, no heuristics
- Unmatched sources: Console WARNING, not silent skip

### Smoke Test Location and Pattern

File: `tests/Unit/Fixtures/ComposerSourceFixtureIntegrityTest.php`
Namespace: `CPSIT\UpgradeAnalyzer\Tests\Unit\Fixtures`

The test is a data-integrity check, not a unit test of business logic:

```php
public static function composerLockFixtureProvider(): array
{
    return [
        'gitlab-saas-public' => ['tests/Fixtures/ComposerSources/GitLabSaasPublic/composer.lock'],
        // ...
    ];
}

#[DataProvider('composerLockFixtureProvider')]
public function testComposerLockIsValidJsonWithSourceFields(string $fixturePath): void
{
    $content = file_get_contents($fixturePath);
    $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    $package = $data['packages'][0];
    self::assertArrayHasKey('source', $package);
    self::assertArrayHasKey('type', $package['source']);
    self::assertArrayHasKey('url', $package['source']);
    self::assertArrayHasKey('reference', $package['source']);
}
```

Use `json_decode(..., JSON_THROW_ON_ERROR)` — never `json_decode` without error handling.
PHPStan Level 8: annotate all array shapes or use `@var` casts where needed.

### Existing Test Conventions

- PHPUnit 10.5+ with `#[DataProvider]` attribute (not `@dataProvider` annotation)
- `declare(strict_types=1)` in every PHP file
- GPL-2.0-or-later license header
- Namespace: `CPSIT\UpgradeAnalyzer\Tests\Unit\...`
- No mocking required for this smoke test (file I/O only)

### Project Structure Notes

- New fixture directory: `tests/Fixtures/ComposerSources/` — no existing `ComposerSources/` directory
- Existing fixture directories for reference: `tests/Fixtures/Configuration/`, `tests/Fixtures/public/`
- New test file: `tests/Unit/Fixtures/` — check if this directory exists before creating; if not, create it
- Design note: `_bmad-output/planning-artifacts/composer-source-parser-design-note.md` — new file, not in codebase yet

### References

- Architecture: `_bmad-output/planning-artifacts/architecture.md` — "Git Source Detection Pattern" section (line ~457)
- Architecture: `_bmad-output/planning-artifacts/architecture.md` — `DeclaredRepository` VO definition (line ~459)
- Architecture: `_bmad-output/planning-artifacts/architecture.md` — file tree showing `ComposerSourceParser.php` location (line ~577)
- Epic 2 Story 2.1 AC: `_bmad-output/planning-artifacts/epics.md` — lines 368–387
- Gap analysis (prerequisite): `_bmad-output/planning-artifacts/fixture-coverage-gap-analysis.md`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
