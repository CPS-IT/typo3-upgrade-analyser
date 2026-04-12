# Story 2.7: Direct/Indirect Extension Distinction

Status: done

## Story

As a developer analyzing a TYPO3 Composer installation,
I want the report to distinguish between directly required extensions (declared in `composer.json`) and transitive extensions (only resolved in `composer.lock`),
so that I can focus upgrade effort on packages I explicitly own and recognize that transitive packages will be handled by their direct parent's maintainer.

## Context

**Issue:** [#150 — Feature: List direct and indirect extensions](https://github.com/CPS-IT/typo3-upgrade-analyser/issues/150)

**Reported problem:** The tool currently checks all packages from `vendor/composer/installed.json` equally. This produces false urgency for transitive dependencies — e.g. `linawolf/list-type-migration` is not in the project's `composer.json` but is required by a third-party extension. If that third-party extension releases a compatible version, the project owner does not need to act on `linawolf/list-type-migration` at all.

**Scope:** Composer-managed installations only. Legacy (non-Composer) installations have no transitive mechanism — all extensions default to `isDirect = true`. A full dependency tree is **out of scope** for this story; the direct/indirect boolean flag is sufficient.

## Acceptance Criteria

### AC-1: `Extension` entity carries `isDirect` flag

1. `Extension` gains `private bool $isDirect = true`
2. `setDirect(bool $isDirect): void` setter added
3. `isDirect(): bool` getter added
4. `toArray()` includes `'is_direct' => $this->isDirect`
5. `jsonSerialize()` includes `'is_direct' => $this->isDirect`
6. Default is `true` — preserves correct behavior for all non-Composer discovery paths and legacy installs

### AC-2: Discovery marks transitive extensions

7. `ExtensionDiscoveryService::discoverFromComposerInstalled()` reads the root `composer.json` `require` AND `require-dev` keys once per call, before the package loop
8. Keys are normalized to lowercase; the result is a `array<string, true>` lookup set
9. For each extension: if the package's `name` is NOT present in the lookup set, `$extension->setDirect(false)` is called
10. The lookup set is built once and reused; no repeated file reads inside the loop
11. If `composer.json` is absent or its JSON is malformed, the lookup set is empty and all extensions keep the default `isDirect = true` — no exception thrown, no logged warning (absence is normal for edge cases)

### AC-3: Report context exposes the flag

12. `VersionAvailabilityDataProvider` includes `'is_direct' => $extension->isDirect()` in its data array (alongside `distribution_type` at line ~72)
13. HTML main-report version-availability table: transitive rows get a subtle `(transitive)` inline label after the extension key — no separate column, no layout change
14. HTML per-extension detail page: "Dependency type: Direct" or "Dependency type: Transitive" in the version-availability overview section
15. Markdown version-availability table: add a `Direct` column after the extension key (`✅` = direct, `↳` = transitive)
16. JSON report: `"is_direct": true|false` is included at the same level as `"distribution_type"` in the analyzer data

### AC-4: Recommendation note for transitive extensions

17. `VersionAvailabilityAnalyzer::addRecommendations()` appends a note when `!$extension->isDirect()`:
    `"This extension is a transitive dependency. Its upgrade is the responsibility of the declaring package's maintainer, not this project."`
18. The note is appended after existing availability-based recommendations — it does not suppress any existing recommendation

### AC-5: Risk scores unchanged

19. `calculateRiskScore()` is not modified — availability-based scoring applies equally to direct and transitive extensions. Risk score adjustments based on dependency depth are out of scope.

### AC-6: Test coverage

20. Unit test: `Extension` — `isDirect()` defaults `true`; `setDirect(false)` changes it; `toArray()` and `jsonSerialize()` include the correct value
21. Unit test: `ExtensionDiscoveryService` — given `composer.json` with `"require": {"vendor/ext-a": "^1.0"}` and `installed.json` containing both `vendor/ext-a` and `vendor/ext-b` (both `type: typo3-cms-extension`): `ext-a` gets `isDirect = true`, `ext-b` gets `isDirect = false`
22. Unit test: `ExtensionDiscoveryService` — package in `require-dev` only → still marked `isDirect = true`
23. Unit test: `ExtensionDiscoveryService` — absent `composer.json` → all extensions default `isDirect = true`, no exception
24. Unit test: `VersionAvailabilityAnalyzer::addRecommendations()` — transitive extension gets the note; direct extension does not
25. All existing tests pass (no regression)
26. PHPStan Level 8: 0 errors; `composer lint:php`: 0 issues

## Tasks / Subtasks

- [x] Task 1: Extend `Extension` entity (AC: 1)
  - [x] 1.1 Add `private bool $isDirect = true` with getter and setter
  - [x] 1.2 Update `toArray()` and `jsonSerialize()` to include `'is_direct'`

- [x] Task 2: Populate in discovery (AC: 2)
  - [x] 2.1 Add `loadDirectPackageNames(string $installationPath): array` private method — reads `composer.json` `require` + `require-dev`, returns lowercase-keyed set
  - [x] 2.2 Call it once at the top of `discoverFromComposerInstalled()`, before the package loop
  - [x] 2.3 After `createExtensionFromComposerData()`, call `$extension->setDirect(isset($directPackages[strtolower($packageData['name'] ?? '')]))`

- [x] Task 3: Thread through reporting (AC: 3)
  - [x] 3.1 Add `'is_direct'` to `VersionAvailabilityDataProvider` output
  - [x] 3.2 HTML: add `(transitive)` label in `version-availability-table.html.twig`
  - [x] 3.3 HTML: add dependency type line in `extension-detail/version-availability-analysis.html.twig`
  - [x] 3.4 Markdown: add `Direct` column to `md/partials/main-report/version-availability-table.md.twig`

- [x] Task 4: Add recommendation note (AC: 4)
  - [x] 4.1 Append transitive note in `VersionAvailabilityAnalyzer::addRecommendations()` when `!$extension->isDirect()`

- [x] Task 5: Tests and quality checks (AC: 6)
  - [x] 5.1 Unit tests for `Extension` entity changes
  - [x] 5.2 Unit tests for `ExtensionDiscoveryService` (direct, transitive, require-dev, absent composer.json)
  - [x] 5.3 Unit test for transitive recommendation note in `VersionAvailabilityAnalyzerTest`
  - [x] 5.4 `composer test` — all pass
  - [x] 5.5 `composer sca:php` — 0 errors; `composer lint:php` — 0 issues

## Dev Notes

### New private method: `loadDirectPackageNames`

Pattern follows the existing `loadDeclaredVcsUrls()` at `src/Infrastructure/Discovery/ExtensionDiscoveryService.php:460`. Keep them separate — different concerns.

```php
private function loadDirectPackageNames(string $installationPath): array
{
    $composerJsonPath = $installationPath . '/composer.json';
    if (!is_file($composerJsonPath)) {
        return [];
    }
    $content = @file_get_contents($composerJsonPath);
    if (false === $content) {
        return [];
    }
    try {
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        return [];
    }
    if (!\is_array($data)) {
        return [];
    }
    $names = [];
    foreach (array_merge(
        array_keys($data['require'] ?? []),
        array_keys($data['require-dev'] ?? [])
    ) as $name) {
        if (\is_string($name)) {
            $names[strtolower($name)] = true;
        }
    }
    return $names;
}
```

Usage in `discoverFromComposerInstalled()` — add before the package loop:

```php
$directPackages = $this->loadDirectPackageNames($installationPath);
```

Then after `createExtensionFromComposerData()`:

```php
$packageName = strtolower($packageData['name'] ?? '');
if ($packageName !== '' && [] !== $directPackages && !isset($directPackages[$packageName])) {
    $extension->setDirect(false);
}
```

The `[] !== $directPackages` guard preserves the "all direct" default when `composer.json` is absent.

### Discovery path: legacy installs

`discoverFromPackageStates()` creates extensions with no composer name. All remain `isDirect = true`. No changes needed.

### Template: HTML main-report table

Avoid adding a column — the table is already wide. Inline label approach:

```twig
{{ extension.key }}{% if not data.version_analysis.is_direct %} <small class="text-muted">(transitive)</small>{% endif %}
```

Exact template variable path: check what `data.version_analysis` keys look like in the Twig context — `is_direct` will be added to `VersionAvailabilityDataProvider`'s output array, which feeds into this partial.

### Template: Markdown table

The existing MD table header row in `version-availability-table.md.twig` currently starts with `|` then distribution icon. Add `Direct` column after extension key:

Current column order (roughly): icon | key | version | TER | Packagist | latest | newer | latest-compat | VCS | risk | VCS-latest
Add: after key → `Direct` (`✅` or `↳`)

### VersionAvailabilityDataProvider location

File: `src/Infrastructure/Reporting/Provider/VersionAvailabilityDataProvider.php`
The `distribution_type` is extracted at line ~72 from `$result->getExtension()->getDistribution()?->getType()`.
Add adjacent: `'is_direct' => $result->getExtension()->isDirect()`

### Testing patterns

- PHPUnit ^12.5 attributes: `#[CoversClass]`, `#[Test]`, `#[DataProvider]`
- No `test` prefix on method names; method names describe expected behavior
- `self::assertTrue()` / `self::assertFalse()` / `self::assertEquals()` — never `$this->`
- For `ExtensionDiscoveryService` tests: use a temporary directory with a small fixture `composer.json` and `installed.json`, or mock the file reads via a process factory pattern — check how existing `ExtensionDiscoveryServiceTest` is structured before choosing approach

### Files to touch

**Modify (PHP):**
- `src/Domain/Entity/Extension.php`
- `src/Infrastructure/Discovery/ExtensionDiscoveryService.php`
- `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php`
- `src/Infrastructure/Reporting/Provider/VersionAvailabilityDataProvider.php`

**Modify (Templates):**
- `resources/templates/html/partials/main-report/version-availability-table.html.twig`
- `resources/templates/html/partials/extension-detail/version-availability-analysis.html.twig`
- `resources/templates/md/partials/main-report/version-availability-table.md.twig`

**Modify (Tests):**
- `tests/Unit/Domain/Entity/ExtensionTest.php` (create if absent)
- `tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php`
- `tests/Unit/Infrastructure/Analyzer/VersionAvailabilityAnalyzerTest.php`

### Out of scope

- Full dependency tree (follow-on story if requested)
- Risk score reduction for transitive deps (separate decision, deferred)
- Filtering or grouping report sections by dependency type (deferred)
- `excludePattern`/`includePattern` config option (mentioned in issue comments as alternative — not chosen)

### References

- [Source: src/Domain/Entity/Extension.php] — entity to extend; no `isDirect` exists today
- [Source: src/Infrastructure/Discovery/ExtensionDiscoveryService.php:387–452] — `discoverFromComposerInstalled()` where marking logic goes
- [Source: src/Infrastructure/Discovery/ExtensionDiscoveryService.php:460–502] — `loadDeclaredVcsUrls()` pattern to follow for new helper
- [Source: src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php:226–258] — `addRecommendations()` to extend
- [Source: src/Infrastructure/Reporting/Provider/VersionAvailabilityDataProvider.php:72] — `distribution_type` extraction pattern
- [Issue: https://github.com/CPS-IT/typo3-upgrade-analyser/issues/150]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- Added `private bool $isDirect = true` to `Extension` entity with getter/setter; `toArray()` and `jsonSerialize()` both include `is_direct`.
- Added `loadComposerJsonData()` to `ExtensionDiscoveryService`; reads and decodes `composer.json` once per invocation, logs error and throws `\JsonException` on malformed JSON (caught at call site — discovery continues). Extracted `extractDeclaredVcsUrls()` and `extractDirectPackageNames()` from the old duplicate methods.
- `ExtensionDiscoveryResult::fromArray()` now restores `isDirect` flag from cached array (bug fix: cache deserialization previously reset all transitive extensions to direct).
- `VersionAvailabilityDataProvider` exposes `is_direct` adjacent to `distribution_type`.
- HTML main-report table: `(transitive)` inline label rendered via CSS class when `is_direct` is false; inline style replaced with `.extension-table-link` class; `.text-muted` added to stylesheet.
- HTML detail page: "Dependency type: Direct/Transitive" status card inserted after Distribution card.
- Markdown table: `Direct` column added (✅ / ↳); else-branch uses `data.extension.isDirect()` instead of hardcoded ✅.
- `VersionAvailabilityAnalyzer::addRecommendations()` appends transitive advisory note after existing recommendations.
- Test fixtures moved to `tests/Fixtures/DirectIndirect/` (four scenarios); `ExtensionDiscoveryServiceTest` uses static fixtures with `#[DataProvider]`.
- All 1713 tests pass; PHPStan Level 8: 0 errors; `composer lint:php`: 0 issues.

### File List

- `src/Domain/Entity/Extension.php`
- `src/Infrastructure/Discovery/ExtensionDiscoveryService.php`
- `src/Infrastructure/Discovery/ExtensionDiscoveryResult.php`
- `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php`
- `src/Infrastructure/Reporting/Provider/VersionAvailabilityDataProvider.php`
- `resources/templates/html/partials/main-report/version-availability-table.html.twig`
- `resources/templates/html/partials/extension-detail/version-availability-analysis.html.twig`
- `resources/templates/html/partials/shared/styles.html.twig`
- `resources/templates/md/partials/main-report/version-availability-table.md.twig`
- `tests/Fixtures/DirectIndirect/` (new fixture directory, 4 scenarios)
- `tests/Unit/Domain/Entity/ExtensionTest.php`
- `tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryServiceTest.php`
- `tests/Unit/Infrastructure/Discovery/ExtensionDiscoveryResultTest.php`
- `tests/Unit/Infrastructure/Analyzer/VersionAvailabilityAnalyzerTest.php`

### Change Log

- 2026-04-12: Implemented Story 2.7 — direct/indirect extension distinction.
- 2026-04-12: Code review fixes — cache deserialization bug, CSS cleanup, MD template, composer.json parse refactor.
