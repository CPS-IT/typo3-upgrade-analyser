# Story 2.1: Composer Source Parser

Status: done

## Story

As a developer,
I want the tool to extract VCS source URLs from the analyzed installation's `composer.lock`,
so that downstream resolution can check version availability for all VCS-sourced extensions.

## Acceptance Criteria

1. **Given** a `composer.lock` with packages carrying `source.url` entries, **when** `ComposerSourceParser::parse(string $composerLockPath)` is called, **then** it returns a `DeclaredRepository[]` array where each entry carries `url: string` and `packages: array<string>`.
2. Packages with `source.type === 'path'` are skipped silently (local packages).
3. Packages without a `source` key are skipped silently (dist-only installs — not a warning condition).
4. SSH URLs (`git@host:path/repo.git`) are handled correctly: `source.url` host is extracted to identify the canonical URL.
5. Both `packages` and `packages-dev` arrays are scanned; packages from both arrays are merged before processing.
6. Multiple packages from the same `source.url` are grouped into a single `DeclaredRepository` (deduplication by URL).
7. If `composer.lock` is missing or unreadable, the parser falls back to `composer.json` `repositories[].url` for `type: vcs` entries; if both are absent, returns an empty array (no exception).
8. Malformed JSON in either file is handled gracefully: log a WARNING via PSR-3 logger, return empty array (no exception propagated to caller).
9. `DeclaredRepository` is a `final readonly` class with exactly two public fields: `url: string` and `packages: array<string>`. **No `type` field.** Lives at `src/Infrastructure/ExternalTool/DeclaredRepository.php`.
10. `ComposerSourceParser` lives at `src/Infrastructure/Discovery/ComposerSourceParser.php`.
11. Unit test coverage is 100% on `ComposerSourceParser` and `DeclaredRepository`, using fixture-based test cases.
12. PHPStan Level 8 reports zero errors on all new files.

## Tasks / Subtasks

- [x] Task 1: Create `DeclaredRepository` value object (AC: 1, 9)
  - [x] Create `src/Infrastructure/ExternalTool/DeclaredRepository.php` — `final readonly` class with `url: string` and `packages: array<string>`. No `type` field.
  - [x] Write `tests/Unit/Infrastructure/ExternalTool/DeclaredRepositoryTest.php` — constructor, immutability.

- [x] Task 2: Add missing test fixtures (AC: 2, 3, 4, 5, 6, 7, 8)
  - [x] Create `tests/Fixtures/ComposerSources/SshUrl/composer.lock` — package with `source.url = git@github.com:vendor/ext.git`
  - [x] Create `tests/Fixtures/ComposerSources/PathType/composer.lock` — package with `source.type = path` (must be skipped)
  - [x] Create `tests/Fixtures/ComposerSources/DistOnly/composer.lock` — package with no `source` key (must be skipped)
  - [x] Create `tests/Fixtures/ComposerSources/MultiPackageSameUrl/composer.lock` — two packages with identical `source.url` (should produce one `DeclaredRepository` with two entries in `packages`)
  - [x] Create `tests/Fixtures/ComposerSources/PackagesDevOnly/composer.lock` — one package in `packages: []`, one in `packages-dev`
  - [x] Create `tests/Fixtures/ComposerSources/MalformedJson/composer.lock` — invalid JSON content
  - [x] Create `tests/Fixtures/ComposerSources/MissingLock/composer.json` — only `composer.json` with `repositories` VCS entries (no `composer.lock`)
  - [x] Create `tests/Fixtures/ComposerSources/EmptyPackages/composer.lock` — valid JSON, both arrays empty

- [x] Task 3: Implement `ComposerSourceParser` (AC: 1–8, 10)
  - [x] Create `src/Infrastructure/Discovery/ComposerSourceParser.php` with constructor accepting `Psr\Log\LoggerInterface`
  - [x] Implement `parse(string $composerLockPath): DeclaredRepository[]`
  - [x] Scan `array_merge($data['packages'], $data['packages-dev'] ?? [])` per design note section 5
  - [x] Skip entries without `source` key (section 4.5 of design note)
  - [x] Skip entries where `source.type === 'path'` (section 4.1 of design note)
  - [x] Extract hostname from `source.url` using `parse_url()` with SSH URL fallback (`/^[^@]+@([^:]+):/` regex) per section 4.2 of design note
  - [x] Group packages by `source.url`: same URL → single `DeclaredRepository`, accumulate package names in `packages` array
  - [x] Implement `composer.lock` missing → fallback to `composer.json` `repositories[].url` for entries with `type: vcs`
  - [x] Wrap JSON decode in try/catch: on `JsonException` log WARNING with file path and return `[]`

- [x] Task 4: Write unit tests for `ComposerSourceParser` (AC: 11)
  - [x] Test: nominal case — GitLab SaaS fixture → one `DeclaredRepository` with correct url and package
  - [x] Test: Bitbucket fixture → one `DeclaredRepository`
  - [x] Test: GitLab self-hosted fixture → one `DeclaredRepository` (domain preserved as-is, no type classification)
  - [x] Test: SSH URL fixture → host extracted correctly, `DeclaredRepository` returned
  - [x] Test: `path` type → skipped, result is empty
  - [x] Test: no `source` key → skipped, result is empty
  - [x] Test: `packages-dev` only → package from dev array is included
  - [x] Test: two packages same URL → single `DeclaredRepository`, both package names in `packages`
  - [x] Test: malformed JSON → logger called once with WARNING level, returns `[]`
  - [x] Test: missing `composer.lock`, present `composer.json` with `repositories` → fallback used, `DeclaredRepository[]` returned
  - [x] Test: both `composer.lock` and `composer.json` absent → returns `[]`, no exception
  - [x] Test: empty `packages` and `packages-dev` arrays → returns `[]`

- [x] Task 5: PHPStan and code style verification (AC: 12)
  - [x] Run `composer sca:php` — zero errors
  - [x] Run `composer lint:php` — zero violations
  - [x] Run `composer test` — all tests green

## Dev Notes

### Critical: `DeclaredRepository` has NO `type` field

The sprint change proposal (ARCH-4) explicitly removed the provider-type field that appeared in the original design note. The VO carries only `url` and `packages`. Provider-type classification was dropped because the downstream resolvers (`PackagistVersionResolver`, `GenericGitResolver`) are provider-agnostic — they work with any URL regardless of host.

**Correct VO shape:**
```php
final class DeclaredRepository
{
    /** @param array<string> $packages */
    public function __construct(
        public readonly string $url,
        public readonly array $packages,
    ) {}
}
```

**Wrong VO shape (do NOT implement):**
```php
// WRONG — type field was removed in sprint change proposal ARCH-4
public function __construct(
    public readonly string $url,
    public readonly string $type,   // ← DO NOT ADD
    public readonly array $packages,
) {}
```

[Source: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-03-26.md` — Section 4B, ARCH-4]

### File Locations

- `DeclaredRepository` VO → `src/Infrastructure/ExternalTool/DeclaredRepository.php`
- `ComposerSourceParser` → `src/Infrastructure/Discovery/ComposerSourceParser.php`
- Unit tests → `tests/Unit/Infrastructure/Discovery/ComposerSourceParserTest.php`
- VO tests → `tests/Unit/Infrastructure/ExternalTool/DeclaredRepositoryTest.php`
- New fixtures → `tests/Fixtures/ComposerSources/<DirectoryName>/`

[Source: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-03-26.md` — ARCH-3 project structure update]

### Parser Logic: Exact Implementation Rules

**Source URL extraction order (provider resolution has been removed — just extract the URL as-is):**

```php
public function parse(string $composerLockPath): array
{
    // 1. Try composer.lock
    // 2. If missing, try composer.json fallback (repositories[].url for type:vcs)
    // 3. If both missing, return []

    // For each package in merged packages + packages-dev:
    // Rule 1: Skip if no 'source' key → dist-only, not a warning
    // Rule 2: Skip if source.type === 'path' → local package, not a warning
    // Rule 3: Extract source.url as-is (URL is the key, no provider classification)
    // Rule 4: Group by url → accumulate package names

    // SSH URL host extraction (for grouping/dedup only, if needed):
    // parse_url returns null host for git@host:path — use regex fallback
}
```

**SSH URL handling** (design note section 4.2 — for URL normalisation only):

```php
$host = parse_url($sourceUrl, PHP_URL_HOST);
if ($host === null && preg_match('/^[^@]+@([^:]+):/', $sourceUrl, $m)) {
    $host = $m[1];
}
```

Note: In the simplified architecture (no type classification), SSH URL handling is needed only to ensure grouping by canonical URL works. The `source.url` value is stored directly in `DeclaredRepository::$url` without transformation.

**`packages-dev` scan** (design note section 5):

```php
$allPackages = array_merge(
    $data['packages'] ?? [],
    $data['packages-dev'] ?? []
);
```

**Deduplication by URL:**

Use `source.url` as the grouping key. Build `array<string, string[]>` indexed by URL, then convert to `DeclaredRepository[]`.

[Source: `_bmad-output/planning-artifacts/composer-source-parser-design-note.md` — Sections 1–5, 7, 8]

### Fallback Logic: `composer.lock` Missing

If `composer.lock` does not exist at the given path:

1. Derive `composer.json` path: same directory, filename `composer.json`
2. Decode `composer.json`
3. Extract `repositories[]` entries where `type === 'vcs'`
4. Use `url` field of each entry as the VCS URL
5. Package names are unknown at this stage → `packages: []` in each `DeclaredRepository`

This is an edge case (unusual for TYPO3 Composer installations). It is not the primary path.

[Source: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-03-26.md` — Story 2.1 AC]

### Existing Fixtures Already Present

These fixtures exist in `tests/Fixtures/ComposerSources/` and cover the primary HTTP VCS cases:

| Directory | `source.url` | Use in tests |
|-----------|-------------|--------------|
| `GitLabSaasPublic/` | `https://gitlab.com/myvendor/typo3-ext-news.git` | Nominal GitLab case |
| `GitLabSaasPrivate/` | `https://gitlab.com/myvendor/...` | Structurally same as public |
| `GitLabSelfHosted/` | `https://git.example.com/...` | Custom domain case |
| `BitbucketPublic/` | `https://bitbucket.org/myvendor/typo3-form-ext.git` | Nominal Bitbucket case |
| `BitbucketPrivate/` | `https://bitbucket.org/myvendor/...` | Structurally same as public |

No `type` classification is needed in tests — just verify URL and package name are extracted.

### New Fixtures Required (not yet created)

Per the gap analysis (`_bmad-output/planning-artifacts/fixture-coverage-gap-analysis.md`):

| Fixture dir | What to test | `source` content |
|-------------|-------------|------------------|
| `SshUrl/` | SSH URL extraction | `"url": "git@github.com:vendor/ext.git"` |
| `PathType/` | `path` type skip | `"source": {"type": "path", "url": "/local/path"}` |
| `DistOnly/` | Missing source key | package entry with no `source` field |
| `MultiPackageSameUrl/` | URL deduplication | two packages, same `source.url` |
| `PackagesDevOnly/` | `packages-dev` scan | `packages: []`, one package in `packages-dev` |
| `MalformedJson/` | JSON error handling | `not valid json {{{` |
| `MissingLock/` | Lock fallback | only `composer.json` with `repositories` VCS entry |
| `EmptyPackages/` | Empty arrays | `"packages": [], "packages-dev": []` |

### Testing Pattern

Follow the existing unit test style in this project. Use `PHPUnit\Framework\TestCase`. Read fixtures with `file_get_contents()` or pass the fixture path directly to the parser. Mock the logger interface for the malformed JSON test.

Look at `tests/Unit/Infrastructure/Parser/YamlConfigurationParserTest.php` and `tests/Unit/Infrastructure/Discovery/ComposerVersionStrategyTest.php` for examples of how file-based parsing is tested.

### PHPStan Level 8 Requirements

- All `array` types must be documented with shapes or generics. Use `array<string>` for `$packages` in `DeclaredRepository`.
- `json_decode()` return must be typed explicitly. Use `JsonException` via `JSON_THROW_ON_ERROR`.
- No `mixed` escapes without explicit cast/check.

### PSR-3 Logger Injection

`ComposerSourceParser` accepts `Psr\Log\LoggerInterface` in the constructor. Use `$this->logger->warning(...)` for malformed JSON. The logger is auto-wired by Symfony DI. No manual registration in `config/services.yaml` needed (auto-wiring covers it).

### Project Structure Notes

- Alignment: `ComposerSourceParser` in `Discovery/` namespace follows the established pattern of discovery-related services (see `InstallationDiscoveryService`, `ComposerVersionStrategy`, `VersionExtractor`).
- `DeclaredRepository` in `ExternalTool/` follows the pattern of data transfer objects used by external integration code (see `GitTag`, `GitRepositoryInfo`).
- Do not place `DeclaredRepository` in `Domain/` — it is an infrastructure VO, not a domain entity.
- Do not extend any existing parser base class (`AbstractConfigurationParser`) — `ComposerSourceParser` is standalone, not a configuration parser.

### References

- [Source: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-03-26.md` — Section 4B (ARCH-3, ARCH-4), Section 4C (Story 2.1 ACs)]
- [Source: `_bmad-output/planning-artifacts/composer-source-parser-design-note.md` — Sections 1–5 (valid), Section 6 (VO shape, type field REMOVED), Section 7 (provider resolution order, REMOVED)]
- [Source: `_bmad-output/planning-artifacts/fixture-coverage-gap-analysis.md` — Section 2.2 fixture gaps for Story 2.1]
- [Source: `documentation/implementation/development/feature/VcsResolutionSpike.md` — Section 8 (no `--working-dir` per-package), Section 10 (constraints table)]
- [Source: `tests/Fixtures/ComposerSources/README.md` — existing fixture inventory]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6 (2026-03-28)

### Debug Log References

None.

### Completion Notes List

- Implemented `DeclaredRepository` as `final readonly` class with exactly `url` and `packages` fields — no `type` field per ARCH-4.
- Implemented `ComposerSourceParser::parse()` with: packages+packages-dev merge, path-type skip, dist-only skip, URL-keyed grouping (dedup), composer.json fallback, JsonException → WARNING + empty array.
- SSH URLs stored as-is in `DeclaredRepository::$url`; no hostname normalisation applied (URL is the grouping key directly).
- 8 new fixtures created covering all AC edge cases; existing GitLab/Bitbucket fixtures reused for nominal tests.
- 12 unit tests for parser, 5 for VO. All pass. PHPStan Level 8 clean. CS-Fixer clean.

### File List

- `src/Infrastructure/ExternalTool/DeclaredRepository.php` (new)
- `src/Infrastructure/Discovery/ComposerSourceParser.php` (new)
- `tests/Unit/Infrastructure/ExternalTool/DeclaredRepositoryTest.php` (new)
- `tests/Unit/Infrastructure/Discovery/ComposerSourceParserTest.php` (new)
- `tests/Fixtures/ComposerSources/SshUrl/composer.lock` (new)
- `tests/Fixtures/ComposerSources/PathType/composer.lock` (new)
- `tests/Fixtures/ComposerSources/DistOnly/composer.lock` (new)
- `tests/Fixtures/ComposerSources/MultiPackageSameUrl/composer.lock` (new)
- `tests/Fixtures/ComposerSources/PackagesDevOnly/composer.lock` (new)
- `tests/Fixtures/ComposerSources/MalformedJson/composer.lock` (new)
- `tests/Fixtures/ComposerSources/MissingLock/composer.json` (new)
- `tests/Fixtures/ComposerSources/EmptyPackages/composer.lock` (new)

## Change Log

- 2026-03-28: Implemented DeclaredRepository VO, ComposerSourceParser, 8 new fixtures, 17 unit tests. All ACs satisfied. PHPStan Level 8 clean.
