# Story Pre-Epic-3: Reporting Hardening — Silent Failure Prevention

Status: backlog

## GitHub Issue

To be created — derived from quality audit findings F-R-01, F-R-02, F-R-03, F-R-04 in `_bmad-output/planning-artifacts/quality-audit-pre-epic-2.md`.

## Why Before Epic 3

Epic 3 stories 3-2 and 3-3 directly refactor `ReportFileManager` (pre-flight output directory check) and `TemplateRenderer` (streaming integration). Doing this hardening first avoids applying fixes to code that will be restructured, and avoids the streaming refactor having to re-implement or re-apply error handling patterns.

## Story

As a developer,
I want the reporting pipeline to surface failures explicitly rather than silently discarding output,
so that a failed Twig render, a disk-full condition, or a null-pointer crash in the context builder produces a visible error rather than silent report truncation.

## Acceptance Criteria

1. All `$this->twig->render()` calls in `TemplateRenderer` are wrapped in try-catch for `\Twig\Error\Error`; failed renders throw `\RuntimeException` with message `"Twig render failed for template '{template}': {original message}"`. Other formats continue unaffected.
2. `file_put_contents()` return values in `ReportFileManager` are checked; a `false` return throws `\RuntimeException("Failed to write report file '{path}': " . error_get_last()['message'])`.
3. `ReportContextBuilder::buildReportContext()`: the `reset()` false/null ambiguity is resolved by replacing `reset($array) ?: null` with an explicit empty-check. The chained `$r->getExtension()->getKey()` is guarded against `getExtension()` returning null.
4. `ReportService::generateReport()` catch block narrowed from `catch (\Throwable $e)` to `catch (\RuntimeException | \Twig\Error\Error $e)`. Other `Error` subclasses propagate.
5. New tests cover: `TemplateRenderer` Twig render throws `\Twig\Error\RuntimeError` → `\RuntimeException` is thrown; `ReportFileManager` `file_put_contents` returns `false` → `\RuntimeException` is thrown; `ReportService` catch block captures `\RuntimeException` for one format while other formats succeed.
6. All existing reporting tests pass unchanged.
7. `composer test` green, `composer sca:php` zero errors, `composer lint:php` zero violations.

## Tasks / Subtasks

- [ ] Task 1: Harden `TemplateRenderer` (F-R-01)
  - [ ] Extract private helper `renderTemplate(string $template, array $context): string`
  - [ ] Helper catches `\Twig\Error\Error`, rethrows as `\RuntimeException` with template name
  - [ ] Replace all 6 `$this->twig->render()` calls with `$this->renderTemplate()`
  - [ ] Affected methods: `renderMainReport()`, `renderSingleExtensionReport()`, `renderSingleRectorFindingsDetailPage()`

- [ ] Task 2: Fix `ReportFileManager` silent write failures (F-R-03)
  - [ ] Check `file_put_contents()` return value in `writeMainReportFile()` (line 79)
  - [ ] Check `file_put_contents()` return value in `writeExtensionReportFiles()` (line 103)
  - [ ] Check `file_put_contents()` return value in `writeRectorDetailPages()` (line 154)
  - [ ] Apply same `mkdir()` guard pattern from `ensureOutputDirectory()` to `ensureExtensionsDirectory()` (line 60) and `ensureRectorFindingsDirectory()` (line 129)

- [ ] Task 3: Fix `ReportContextBuilder` null/false confusion (F-R-02)
  - [ ] Replace `reset($installationDiscovery) ?: null` with explicit empty-check (line 87)
  - [ ] Replace `reset($extensionDiscovery) ?: null` with explicit empty-check (line 88)
  - [ ] Guard `$r->getExtension()->getKey()` chain (line 64)

- [ ] Task 4: Narrow catch in `ReportService` (F-R-04)
  - [ ] Change `catch (\Throwable $e)` to `catch (\RuntimeException | \Twig\Error\Error $e)` (line 86)

- [ ] Task 5: Write new tests
  - [ ] `TemplateRendererTest`: Twig render failure → `\RuntimeException` thrown
  - [ ] `ReportFileManagerTest`: `file_put_contents` failure → `\RuntimeException` thrown
  - [ ] `ReportServiceTest`: one format fails, other formats succeed

- [ ] Task 6: Quality gate
  - [ ] `composer test` — all tests green
  - [ ] `composer sca:php` — zero PHPStan errors
  - [ ] `composer lint:php` — zero violations

## Dev Notes

### Task 1 — TemplateRenderer: Exact Change Locations

File: `src/Infrastructure/Reporting/TemplateRenderer.php`

Extract a private helper method (note: class is `readonly`, private methods are allowed):

```php
private function renderTemplate(string $template, array $context): string
{
    try {
        return $this->twig->render($template, $context);
    } catch (\Twig\Error\Error $e) {
        throw new \RuntimeException(
            "Twig render failed for template '{$template}': " . $e->getMessage(),
            0,
            $e
        );
    }
}
```

Replace all 6 `$this->twig->render()` calls with `$this->renderTemplate()`. Locations:
- `renderMainReport()`: lines 42 (markdown), 46 (html)
- `renderSingleExtensionReport()`: lines 103 (markdown), 108 (html)
- `renderSingleRectorFindingsDetailPage()`: lines 177 (markdown), 182 (html)

JSON paths use `json_encode` with `JSON_THROW_ON_ERROR` — already safe, leave them.

### Task 2 — ReportFileManager: Exact Change Locations

File: `src/Infrastructure/Reporting/ReportFileManager.php`

Pattern for all three `file_put_contents()` calls:

```php
$written = file_put_contents($filename, $renderedReport['content']);
if (false === $written) {
    $error = error_get_last();
    throw new \RuntimeException(
        \sprintf('Failed to write report file "%s": %s', $filename, $error['message'] ?? 'unknown error')
    );
}
```

For `mkdir()` in `ensureExtensionsDirectory()` (line 60) and `ensureRectorFindingsDirectory()` (line 129), apply the same pattern already present in `ensureOutputDirectory()` (lines 39–42):
```php
if (!mkdir($path, 0o755, true) && !is_dir($path)) {
    throw new \RuntimeException(\sprintf('Directory "%s" was not created', $path));
}
```

### Task 3 — ReportContextBuilder: Exact Change Locations

File: `src/Infrastructure/Reporting/ReportContextBuilder.php`

**Line 64 — null chain guard:**
```php
// Before:
static fn (ResultInterface $r): bool => $r instanceof AnalysisResult && $r->getExtension()->getKey() === $extension->getKey(),

// After:
static fn (ResultInterface $r): bool => $r instanceof AnalysisResult
    && null !== $r->getExtension()
    && $r->getExtension()->getKey() === $extension->getKey(),
```

**Lines 87–88 — reset() ambiguity:**
```php
// Before:
'installation' => reset($installationDiscovery) ?: null,
'extensions' => reset($extensionDiscovery) ?: null,

// After:
'installation' => !empty($installationDiscovery) ? reset($installationDiscovery) : null,
'extensions' => !empty($extensionDiscovery) ? reset($extensionDiscovery) : null,
```

Leave the `$targetVersion ?? '13.4'` fallback (line 85) — F-R-05 is out of scope here.

### Task 4 — ReportService: Exact Change Location

File: `src/Infrastructure/Reporting/ReportService.php`

```php
// Before (line 86):
} catch (\Throwable $e) {

// After:
} catch (\RuntimeException | \Twig\Error\Error $e) {
```

### Task 5 — New Test Guidance

**TemplateRendererTest.php:**
```php
public function testRenderMainReportThrowsRuntimeExceptionOnTwigError(): void
{
    $this->twig->method('render')
        ->willThrowException(new \Twig\Error\RuntimeError('Template not found'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/Twig render failed for template/');

    $this->subject->renderMainReport([], 'markdown');
}
```

**ReportFileManagerTest.php:**
Testing `file_put_contents` failure requires filesystem control. Check whether `mikey179/vfsstream` is in `composer.json` before using it. If absent, document in completion notes that write-failure coverage requires either adding vfsStream or a filesystem abstraction — do not add the dependency without confirming with the user.

**ReportServiceTest.php:**
The existing mock setup supports this:
```php
$this->templateRenderer->method('renderMainReport')
    ->willReturnCallback(function (array $context, string $format): array {
        if ($format === 'markdown') {
            throw new \RuntimeException('Twig render failed');
        }
        return ['content' => '{}', 'filename' => 'report.json'];
    });
// assert: json result is successful, markdown result has error set
```

### PHPStan Level 8 Notes

- `error_get_last()` returns `array{message: string, ...}|null` — use `$error['message'] ?? 'unknown error'`, not `$error['message']` directly
- The narrowed catch `\RuntimeException | \Twig\Error\Error` must cover everything that `generateFormatReport()` can throw after Tasks 1–3. After the changes, `TemplateRenderer` only throws `\RuntimeException` and `ReportFileManager` only throws `\RuntimeException`. Verify `buildReportContext()` cannot throw anything uncovered.

### Existing Test Conventions

- PHPUnit 10.5+ with `#[DataProvider]` attribute (not `@dataProvider`)
- `declare(strict_types=1)` in every PHP file
- GPL-2.0-or-later license header
- Namespace: `CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Reporting`
- All four test files already exist at `tests/Unit/Infrastructure/Reporting/`

### Project Structure Notes

- No new files needed — all changes are in-place modifications
- Source: `src/Infrastructure/Reporting/{TemplateRenderer,ReportFileManager,ReportContextBuilder,ReportService}.php`
- Tests: `tests/Unit/Infrastructure/Reporting/{TemplateRenderer,ReportFileManager,ReportContextBuilder,ReportService}Test.php`
- `ReportingResult::setError(string $error)` confirmed available at line 61 of `src/Domain/Entity/ReportingResult.php`

### References

- Quality audit: `_bmad-output/planning-artifacts/quality-audit-pre-epic-2.md` — F-R-01 (~line 206), F-R-02 (~line 211), F-R-03 (~line 222), F-R-04 (~line 231)
- Epic 3 stories: `_bmad-output/planning-artifacts/epics.md` — 3-2 (pre-flight output directory check), 3-3 (streaming integration in template rendering)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
