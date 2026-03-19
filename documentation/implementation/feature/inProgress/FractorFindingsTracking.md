# Fractor Findings Tracking and Detail Pages Feature

## Implementation Status: PHASE 3 IN PROGRESS

**Status**: Template rendering pipeline reworked to fix segfaults; integration ongoing
**Priority**: High - Segfault fix and template integration
**Last Updated**: March 19, 2026

## Overview

Enhancement to save Fractor analyzer findings in machine-readable JSON format and create dedicated human-readable detail pages for developers. Initial assessment revealed critical foundation gaps and architectural issues that required complete plan revision.

## Critical Issues Discovered

### Foundation Class Problems
- FractorFinding class does not exist (assumed by original plan)
- FractorResultParser leaves findings array empty (line 206)
- FractorAnalysisSummary lacks toArray() method
- No structured finding objects created from Fractor output

### Architectural Problems
- Massive Code Duplication - Original plan duplicated entire Rector pattern instead of creating reusable abstractions
- Missing Generic Infrastructure - No shared interfaces for analyzer findings
- Template Explosion - Separate template hierarchies for each analyzer type
- Inconsistent Data Pipeline - Different serialization patterns between analyzers

### Segfault During Template Rendering (Discovered Phase 3)
- Passing Extension entities and AnalysisResult objects to Twig templates causes PHP segmentation faults during `file_put_contents` serialization
- Root cause: Extension entities contain circular references or deep object graphs that crash PHP's serializer
- Large code content fields (code_before, code_after, diff) in FractorFinding objects cause memory exhaustion
- Error messages from Fractor can be extremely large, compounding memory pressure

#### Solution: Pre-serialization of Template Context
- All entity data is extracted to scalar/array form **before** passing to templates
- `ReportContextBuilder` builds a fully sanitized context with no object references
- Templates use `extension_key` (string), `installation_data` (array), `extension_data` (array) instead of entity objects
- `ContentTruncator` utility (`src/Infrastructure/Shared/ContentTruncator.php`) limits code content and error messages
- Multiple sanitization layers in `FindingsDetailPageRenderer` and `TemplateRenderer` as defense-in-depth
- `generated_at` passed as pre-formatted string instead of DateTime object

## Current State Analysis

### What Actually Exists
- FractorAnalyzer: Fully functional analyzer with execution and basic metrics
- FractorAnalysisSummary: Readonly class with basic properties (filesScanned, rulesApplied, etc.)
- FractorResultParser: Parses Fractor output but doesn't create structured findings
- Template Integration: Basic HTML templates work with available data

### What's Missing
- FractorFinding class: No structured finding objects exist
- Detailed finding extraction: Parser extracts diff data but doesn't structure it
- Serialization methods: No toArray() methods for JSON export
- Generic infrastructure: No reusable abstractions for multiple analyzers

### Actual Fractor Data Available
From examining real Fractor output and cached results:
```json
{
  "metrics": {
    "files_scanned": 2,
    "files_changed": 2,
    "rules_applied": 3,
    "change_blocks": 2,
    "changed_lines": 257,
    "file_paths": ["../path/to/file1.xml:20", "../path/to/file2.xml:71"],
    "applied_rules": ["RemoveTceFormsDomElementFlexFormFractor", "..."],
    "findings": []  // Always empty - parser doesn't populate this
  }
}
```

## Revised Architectural Approach

### Generic Infrastructure First
Instead of duplicating Rector patterns, create reusable abstractions:

1. Generic Interfaces: AnalyzerFindingInterface, FindingsSummaryInterface
2. Abstract Base Classes: Common functionality shared across analyzers
3. Polymorphic Templates: Single template system that works for any analyzer
4. Unified Serialization: Consistent toArray() patterns across all analyzers

### Eliminate Code Duplication
- Single FindingsDetailPageRenderer works for any analyzer type
- Generic template system with analyzer-specific partials
- Shared file management and report integration logic
- Common interfaces ensure consistent behavior

## Revised Implementation Plan

### Phase 1: Foundation First
**Priority**: Critical - Nothing works without these foundations

#### 1.1 Create Missing Foundation Classes
**File**: `src/Infrastructure/Analyzer/Fractor/FractorFinding.php`
```php
readonly class FractorFinding implements AnalyzerFindingInterface
{
    public function __construct(
        public string $filePath,
        public int $lineNumber,
        public string $ruleClass,
        public string $message,
        public string $codeBefore,
        public string $codeAfter,
        public string $diff,
        public ?string $documentationUrl = null,
    ) {}

    public function toArray(): array
    {
        return [
            'file_path' => $this->filePath,
            'line_number' => $this->lineNumber,
            'rule_class' => $this->ruleClass,
            'message' => $this->message,
            'code_before' => $this->codeBefore,
            'code_after' => $this->codeAfter,
            'diff' => $this->diff,
            'documentation_url' => $this->documentationUrl,
        ];
    }
}
```

#### 1.2 Add toArray() Method to FractorAnalysisSummary
**File**: `src/Infrastructure/Analyzer/Fractor/FractorAnalysisSummary.php`
```php
public function toArray(): array
{
    return [
        'files_scanned' => $this->filesScanned,
        'rules_applied' => $this->rulesApplied,
        'findings' => array_map(fn($f) => $f instanceof FractorFinding ? $f->toArray() : $f, $this->findings),
        'successful' => $this->successful,
        'change_blocks' => $this->changeBlocks,
        'changed_lines' => $this->changedLines,
        'file_paths' => $this->filePaths,
        'applied_rules' => $this->appliedRules,
        'error_message' => $this->errorMessage,
    ];
}
```

#### 1.3 Enhance FractorResultParser to Create Actual Findings
**File**: `src/Infrastructure/Analyzer/Fractor/FractorResultParser.php`

Replace empty findings array (line 206) with:
```php
$findings = [];
foreach ($diffSections as $section) {
    $findings[] = new FractorFinding(
        filePath: $section['file'],
        lineNumber: $section['line'],
        ruleClass: $section['appliedRule'],
        message: $this->generateMessage($section),
        codeBefore: $section['beforeCode'],
        codeAfter: $section['afterCode'],
        diff: $section['diff'],
        documentationUrl: $this->extractDocumentationUrl($section),
    );
}
```

### Phase 2: Generic Infrastructure

#### 2.1 Create Generic Analyzer Interfaces
**File**: `src/Infrastructure/Analyzer/Shared/AnalyzerFindingInterface.php`
```php
interface AnalyzerFindingInterface
{
    public function getFile(): string;
    public function getLine(): int;
    public function getMessage(): string;
    public function getSeverity(): string;
    public function getPriorityScore(): float;
    public function toArray(): array;
}
```

**File**: `src/Infrastructure/Analyzer/Shared/DetailedAnalysisInterface.php`
```php
interface DetailedAnalysisInterface
{
    public function getFindings(): array;
    public function getSummary(): FindingsSummaryInterface;
    public function getAnalyzerType(): string;
}
```

#### 2.2 Create Abstract Base Classes
**File**: `src/Infrastructure/Analyzer/Shared/AbstractAnalyzerFinding.php`
```php
abstract class AbstractAnalyzerFinding implements AnalyzerFindingInterface
{
    public function getPriorityScore(): float
    {
        return match($this->getSeverity()) {
            'error' => 1.0,
            'warning' => 0.7,
            'info' => 0.3,
            default => 0.1,
        };
    }
}
```

#### 2.3 Update Existing Classes to Use Generic Infrastructure
**File**: `src/Infrastructure/Analyzer/Rector/RectorFinding.php`
```php
class RectorFinding extends AbstractAnalyzerFinding
{
    // Existing implementation remains the same
    // Just extend the abstract class for interface compliance
}
```

### Phase 3: Template System Integration and Segfault Fix

#### 3.1 Template Context Pre-serialization (DONE)
All templates updated to use scalar/array data instead of entity objects:
- `generated_at` rendered as pre-formatted string (no `.format()` calls)
- `extension.key` replaced with `extension_key` string
- `installation.path/version/type` replaced with `installation_data` array
- `data.extension.*` replaced with `data.extension_key`, `data.extension_data.*`
- Code samples and error messages removed from templates to prevent memory issues

#### 3.2 ReportContextBuilder Rework (DONE)
- Extension keys extracted upfront into lookup map, then Extension entities dropped
- `deepSanitizeExtensionData()` recursively converts all objects to scalar/array
- `sanitizeFractorFindings()` converts FractorFinding objects to safe arrays
- `calculateManualInterventionCount()` added for Fractor findings
- Context returned as fully sanitized array with no object references

#### 3.3 FindingsDetailPageRenderer Safety Layers (DONE)
- `createSafeTemplateContext()` strips Extension entities and converts DateTimes
- `stripLargeContentFromFindings()` removes code_before/code_after/diff fields
- Extensive debug logging with memory usage tracking

#### 3.4 TemplateRenderer Sanitization (DONE)
- All `$extensionData['extension']->getKey()` replaced with `$extensionData['extension_key']`
- `sanitizeExtensionData()` and `sanitizeArray()` recursively clean data
- Extension entity references removed from all findings contexts

#### 3.5 FractorResultParser Enhancements (DONE)
- ContentTruncator applied to error messages and code content
- Missing metrics parsing added: change_blocks, changed_lines, file_paths, applied_rules

### Phase 4: Integration and Testing

#### 4.1 Update Report Generation Services
**File**: `src/Infrastructure/Reporting/ReportContextBuilder.php`

Add generic findings extraction method:
```php
private function extractAnalyzerDetailedFindings(
    array $results,
    string $analyzerName
): ?array {
    // Generic extraction logic that works for any analyzer
    // implementing DetailedAnalysisInterface
}
```

#### 4.2 Update Template Rendering
**File**: `src/Infrastructure/Reporting/TemplateRenderer.php`

Add generic detail page support:
```php
public function renderAnalyzerDetailPages(
    array $context,
    string $format
): array {
    // Use FindingsDetailPageRenderer for all analyzers
    // Eliminates need for analyzer-specific methods
}
```

#### 4.3 Comprehensive Testing Strategy
**Unit Tests**:
- FractorFindingTest — Test creation and serialization
- FractorAnalysisSummaryTest — Test enhanced toArray() method
- FindingsDetailPageRendererTest - Test generic template rendering
- AbstractAnalyzerFindingTest — Test base class functionality

**Integration Tests**:
- FractorResultParserIntegrationTest — Test finding extraction from real output
- FindingsDetailIntegrationTest - End-to-end template rendering
- GenericInfrastructureIntegrationTest - Cross-analyzer compatibility

## Key Benefits of Revised Approach

1. Eliminates Code Duplication — Single template system, shared interfaces, common base classes
2. Extensible Architecture — New analyzers implement interfaces without duplicating code
3. Consistent Data Format — All analyzers provide standardized JSON serialization
4. Type Safety — Strong typing through interfaces ensures behavioral consistency
5. Backward Compatibility — Existing Rector functionality continues to work unchanged
6. Testable Design - Clear interfaces enable comprehensive unit and integration testing

## File Changes Summary

### New Files (Phase 1-2, prior commits)
- `src/Infrastructure/Analyzer/Fractor/FractorFinding.php`
- `src/Infrastructure/Analyzer/Shared/AnalyzerFindingInterface.php`
- `src/Infrastructure/Analyzer/Shared/DetailedAnalysisInterface.php`
- `src/Infrastructure/Analyzer/Shared/AbstractAnalyzerFinding.php`
- `src/Infrastructure/Reporting/FindingsDetailPageRenderer.php`
- `src/Infrastructure/Shared/ContentTruncator.php` — Utility for truncating large content fields
- `resources/templates/html/analyzer-findings-detail.html.twig`
- `resources/templates/html/partials/fractor-findings/*.html.twig`

### Modified Files (Phase 3, segfault fix)
- `src/Infrastructure/Reporting/ReportContextBuilder.php` — Pre-serialization, deep sanitization, no entity refs
- `src/Infrastructure/Reporting/TemplateRenderer.php` — Entity-free context building, array sanitization
- `src/Infrastructure/Reporting/FindingsDetailPageRenderer.php` — Safe context creation, content stripping
- `src/Infrastructure/Reporting/ReportService.php` — Debug logging with memory tracking
- `src/Infrastructure/Analyzer/Fractor/FractorResultParser.php` — ContentTruncator, missing metrics
- `src/Application/Command/AnalyzeCommand.php` — array_merge refactoring
- All HTML/Markdown templates — Entity method calls replaced with scalar access

## Risk Assessment

### High Priority Risks
- Breaking Rector functionality during interface migration
  - Mitigation: Extensive regression testing, backward compatibility design
- Parser enhancement complexity extracting findings from Fractor output
  - Mitigation: Incremental enhancement with real data validation

### Medium Priority Risks
- Performance impact from abstraction layers
  - Mitigation: Benchmarking and optimization during implementation
- Template system consistency across different analyzers
  - Mitigation: Generic interface design with analyzer-specific customization points

### Low Priority Risks
- Missing toArray() method implementation - straightforward addition
- Creating FractorFinding class - proven pattern from Rector implementation

## Implementation Dependencies

**Phase 1**: No dependencies — can start immediately
**Phase 2**: Phase 1 complete and tested
**Phase 3**: Phases 1–2 complete and interfaces stabilized
**Phase 4**: All foundation work complete, ready for integration testing

## Success Metrics

- FractorResultParser populates findings array with structured FractorFinding objects
- Generic template system renders consistently across Rector/Fractor analyzers
- Zero regression in existing Rector functionality
- Code duplication eliminated through shared abstractions and interfaces
- Maintainable generic infrastructure supports future analyzer additions
- JSON serialization works consistently across all analyzer types

## Conclusion

This revised plan addresses the critical foundation gaps and architectural issues identified during assessment. By building proper generic infrastructure first, we eliminate code duplication while creating a maintainable, extensible system that supports both current and future analyzer types.

The approach prioritizes building missing foundation classes, implementing generic interfaces, and creating reusable template components — ensuring that the Fractor findings tracking feature is built on solid architectural principles rather than duplicating existing patterns.
