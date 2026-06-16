# Story pre-epic-3: Fix Hardcoded TCA/Overrides Skip in RectorConfigGenerator (Bug #291)

Status: review

## Story

As a developer running the upgrade analyzer on a TYPO3 extension,
I want the generated Rector configuration to include `Configuration/TCA/Overrides/` files in analysis,
so that TCA-related deprecations (inputDateTime, inputLink, indexed item arrays, required flag) are reported rather than silently suppressed.

## Acceptance Criteria

1. `RectorConfigGenerator::getSkipPatterns()` does not include `'*/Configuration/TCA/Overrides/*'` for any extension type.
2. A Rector config generated for a regular extension does not contain `TCA/Overrides` in skip patterns.
3. A Rector config generated for a system extension does not contain `TCA/Overrides` in skip patterns.
4. A Rector config generated for the test extension does not contain `TCA/Overrides` in skip patterns.
5. All other skip patterns (`*/vendor/*`, `*/node_modules/*`, `*/var/*`, `*/public/*`, `*/.Build/*`, `*/Documentation/*`, `*/doc/*`) remain unchanged.
6. Extension-specific skip patterns (Tests for non-test extensions, Migrations for system extensions) remain unchanged.
7. All existing tests pass; updated tests assert absence of `TCA/Overrides` skip pattern.
8. `composer test` green, `composer sca:php` zero errors, `composer lint:php` zero violations.

## Tasks / Subtasks

- [x] Task 1: Remove hardcoded TCA/Overrides skip pattern (AC: 1)
  - [x] In `src/Infrastructure/Analyzer/Rector/RectorConfigGenerator.php`, method `getSkipPatterns()`, remove the line `'*/Configuration/TCA/Overrides/*',` and its preceding comment
  - [x] Do NOT remove any other skip patterns

- [x] Task 2: Update `RectorConfigGeneratorTest` (AC: 2–6, 7)
  - [x] `testGetSkipPatternsForRegularExtension()`: add `assertStringNotContainsString('*/Configuration/TCA/Overrides/*', $content)`
  - [x] `testGetSkipPatternsForSystemExtension()`: add `assertStringNotContainsString('*/Configuration/TCA/Overrides/*', $content)`
  - [x] `testGetSkipPatternsForTestExtension()`: add `assertStringNotContainsString('*/Configuration/TCA/Overrides/*', $content)`
  - [x] Add `testTcaOverridesNotSkipped()`: assert that for a generic extension, the generated config does NOT contain `TCA/Overrides` as a skip pattern

- [x] Task 3: Quality gate (AC: 8)
  - [x] `composer test` — all tests green
  - [x] `composer sca:php` — zero PHPStan errors
  - [x] `composer lint:php` — zero violations

## Dev Notes

### Root Cause

`RectorConfigGenerator::getSkipPatterns()` (line ~264–295 of `src/Infrastructure/Analyzer/Rector/RectorConfigGenerator.php`) unconditionally includes `'*/Configuration/TCA/Overrides/*'` in every generated Rector config:

```php
// Configuration files that might contain legacy patterns intentionally
'*/Configuration/TCA/Overrides/*',
```

`Configuration/TCA/Overrides/` files are first-class PHP code. They contain deprecated TCA options that are exactly what Rector should detect:
- `MigrateInputDateTimeRector` — `renderType => inputDateTime`
- `MigrateRenderTypeInputLinkToTypeLinkRector` — `renderType => inputLink`
- `MigrateItemsIndexedKeysToAssociativeRector` — indexed item arrays
- `MigrateRequiredFlagRector` — `required` flag migration

The comment "might contain legacy patterns intentionally" is wrong — legacy patterns in TCA/Overrides are upgrade targets, not intentional legacy code.

### Exact Change

In `getSkipPatterns()`, remove these two lines:

```php
            // Configuration files that might contain legacy patterns intentionally
            '*/Configuration/TCA/Overrides/*',
```

The resulting `$skipPatterns` array should only contain:
```php
$skipPatterns = [
    '*/vendor/*',
    '*/node_modules/*',
    '*/var/*',
    '*/public/*',
    '*/.Build/*',
    '*/Documentation/*',
    '*/doc/*',
];
```

Plus the conditional additions (Tests for non-test extensions, Migrations for system extensions) which are unchanged.

### Test to Add

```php
public function testTcaOverridesNotSkipped(): void
{
    $extension = new Extension('my_extension', 'My Extension', new Version('1.0.0'));
    $context = new AnalysisContext(
        new Version('12.4.0'),
        new Version('13.0.0'),
    );

    $this->ruleRegistry
        ->method('getSetsForVersionUpgrade')
        ->willReturn(['Rule1']);

    $configPath = $this->generator->generateConfig($extension, $context, '/path/to/extension');
    $content = file_get_contents($configPath);

    if (!$content) {
        $this->fail('Config file not found');
    }

    $this->assertStringNotContainsString('TCA/Overrides', $content);
    $this->assertStringNotContainsString('Configuration/TCA/Overrides', $content);
}
```

### Existing Tests to Update

`testGetSkipPatternsForRegularExtension()` currently only asserts presence of `*/vendor/*`, `*/Tests/*`, `*/Documentation/*` and absence of `*/Migrations/*`. Add:
```php
$this->assertStringNotContainsString('*/Configuration/TCA/Overrides/*', $content);
```

`testGetSkipPatternsForTestExtension()` — add:
```php
$this->assertStringNotContainsString('*/Configuration/TCA/Overrides/*', $content);
```

`testGetSkipPatternsForSystemExtension()` currently only asserts `*/Migrations/*` is present. Add:
```php
$this->assertStringNotContainsString('*/Configuration/TCA/Overrides/*', $content);
```

### What NOT to Change

- Do not touch any other skip pattern in `getSkipPatterns()`
- Do not add any configuration option or flag to re-enable TCA/Overrides skipping — the sprint change proposal explicitly states "never force it"
- Do not modify any other method in `RectorConfigGenerator`
- Do not touch `Typo3RectorAnalyzer.php` — this story is confined to `RectorConfigGenerator.php`

### Project Structure Notes

- Source: `src/Infrastructure/Analyzer/Rector/RectorConfigGenerator.php`
- Test: `tests/Unit/Infrastructure/Analyzer/Rector/RectorConfigGeneratorTest.php`
- No new files needed; no other classes touched

### Test Conventions (from pre-epic-3-fix-rector-rule-sets story)

- PHPUnit 12 with `#[DataProvider]` attribute (not `@dataProvider`)
- `declare(strict_types=1)` in every PHP file
- GPL-2.0-or-later license header
- `#[AllowMockObjectsWithoutExpectations]` attribute on the test class (already present)
- Constructor: `new RectorConfigGenerator($this->ruleRegistry, $this->tempDirectory, $this->filesystem)` — no other dependencies

### References

- Sprint change proposal: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-06-16.md` § "New Story: pre-epic-3-fix-rector-tca-skip"
- Source file: `src/Infrastructure/Analyzer/Rector/RectorConfigGenerator.php`, method `getSkipPatterns()` (~line 263)
- Existing tests: `tests/Unit/Infrastructure/Analyzer/Rector/RectorConfigGeneratorTest.php`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

None.

### Completion Notes List

- Removed `'*/Configuration/TCA/Overrides/*'` and its preceding comment from `getSkipPatterns()` in `RectorConfigGenerator.php`. No other skip patterns touched.
- Added `assertStringNotContainsString('*/Configuration/TCA/Overrides/*', $content)` to three existing test methods: regular, test, and system extension variants.
- Added `testTcaOverridesNotSkipped()` asserting both `'TCA/Overrides'` and `'Configuration/TCA/Overrides'` are absent from generated config.
- 1673 tests pass, PHPStan level 8 zero errors, PHP-CS-Fixer zero violations.

### File List

- src/Infrastructure/Analyzer/Rector/RectorConfigGenerator.php
- tests/Unit/Infrastructure/Analyzer/Rector/RectorConfigGeneratorTest.php

## Change Log

- 2026-06-16: Story created — fix Bug #291: remove hardcoded `*/Configuration/TCA/Overrides/*` skip pattern from `RectorConfigGenerator::getSkipPatterns()`.
- 2026-06-16: Implementation complete — removed TCA/Overrides skip pattern; updated and extended RectorConfigGeneratorTest; all quality gates green.
