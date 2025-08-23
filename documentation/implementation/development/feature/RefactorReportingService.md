# Feature Implementation Plan: Refactor ReportService

## Overview

Refactor the oversized ReportService class (558 LOC) to address critical architecture violations identified in the architecture review. The class currently violates Single Responsibility Principle by mixing context building, template rendering, and file management concerns.

## Problem Analysis

### Current Architecture Issues

The ReportService contains multiple distinct responsibilities:

1. **Context Building** (lines 122-187): Complex data aggregation and transformation logic
2. **Template Rendering** (lines 413-547): Format-specific rendering with multiple output types  
3. **File Management** (lines 417-425, 500-503): Directory creation and file I/O operations
4. **Result Analysis** (lines 192-331): Extraction and calculation logic for different analyzer types
5. **Statistics Calculation** (lines 336-411): Complex aggregation of risk and availability metrics

### Risks of Current Implementation

- Changes to file handling affect template rendering logic
- Complex testing due to intertwined responsibilities
- Difficult to isolate issues during debugging
- High cognitive load for maintenance

## Solution Design

### Lean Refactoring Approach

Break down ReportService into three focused services while maintaining simplicity:

```
ReportService (Coordinator)
├── ReportContextBuilder (Data aggregation)
├── TemplateRenderer (Content generation) 
└── ReportFileManager (File operations)
```

### Service Responsibilities

#### 1. ReportContextBuilder
- **Single Responsibility**: Data aggregation and context preparation
- **Extract Methods**: 
  - `buildReportContext()`
  - `calculateOverallStatistics()`
  - `calculateExtensionRiskSummary()`
  - All result extraction methods (`extractVersionAnalysis()`, etc.)
- **Dependencies**: None (pure data transformation)

#### 2. TemplateRenderer  
- **Single Responsibility**: Format-specific content generation
- **Extract Methods**:
  - `generateMainReport()`
  - `generateExtensionReports()`
  - Template rendering for all formats (markdown, html, json)
- **Dependencies**: Twig environment
- **Note**: Content generation only, no file I/O

#### 3. ReportFileManager
- **Single Responsibility**: File system operations
- **Extract Methods**:
  - Directory creation logic
  - File writing operations
  - Path management and metadata collection
- **Dependencies**: Filesystem utilities

#### 4. Refactored ReportService
- **Single Responsibility**: Orchestration
- **Maintains**: Exact same public interface for backward compatibility
- **Coordinates**: The three specialized services
- **Handles**: Error handling and logging

## Implementation Steps

### Phase 1: Create Service Infrastructure

#### Step 1.1: Create ReportContextBuilder
**File**: `src/Infrastructure/Reporting/ReportContextBuilder.php`

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Reporting;

use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;

final class ReportContextBuilder
{
    public function buildReportContext(AnalysisResult $analysisResult): array
    {
        // Extract context building logic from ReportService
    }

    public function calculateOverallStatistics(AnalysisResult $analysisResult): array
    {
        // Extract statistics calculation logic
    }

    public function calculateExtensionRiskSummary(AnalysisResult $analysisResult): array
    {
        // Extract risk summary logic
    }

    // Additional extraction methods...
}
```

#### Step 1.2: Create TemplateRenderer
**File**: `src/Infrastructure/Reporting/TemplateRenderer.php`

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Reporting;

use Twig\Environment;

final class TemplateRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public function renderMainReport(array $context, string $format): string
    {
        // Extract main report rendering logic
    }

    public function renderExtensionReports(array $context, string $format): array
    {
        // Extract extension report rendering logic
    }
}
```

#### Step 1.3: Create ReportFileManager
**File**: `src/Infrastructure/Reporting/ReportFileManager.php`

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Reporting;

final class ReportFileManager
{
    public function ensureOutputDirectory(string $outputPath): void
    {
        // Extract directory creation logic
    }

    public function writeReportFiles(array $renderedContent, string $outputPath): array
    {
        // Extract file writing operations
    }

    public function collectFileMetadata(string $outputPath): array
    {
        // Extract file metadata collection
    }
}
```

### Phase 2: Refactor ReportService

#### Step 2.1: Update ReportService Constructor
```php
public function __construct(
    private readonly ReportContextBuilder $contextBuilder,
    private readonly TemplateRenderer $templateRenderer,
    private readonly ReportFileManager $fileManager,
) {}
```

#### Step 2.2: Simplify generateReport Method
```php
public function generateReport(AnalysisResult $analysisResult, string $outputPath): array
{
    // 1. Build context
    $context = $this->contextBuilder->buildReportContext($analysisResult);
    
    // 2. Render content
    $mainContent = $this->templateRenderer->renderMainReport($context, 'html');
    $extensionContent = $this->templateRenderer->renderExtensionReports($context, 'html');
    
    // 3. Write files
    return $this->fileManager->writeReportFiles([
        'main' => $mainContent,
        'extensions' => $extensionContent,
    ], $outputPath);
}
```

### Phase 3: Service Configuration

#### Step 3.1: Update services.yaml
```yaml
services:
    CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportContextBuilder:
        public: false

    CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\TemplateRenderer:
        arguments:
            $twig: '@twig'
        public: false

    CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportFileManager:
        public: false

    CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportService:
        arguments:
            $contextBuilder: '@CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportContextBuilder'
            $templateRenderer: '@CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\TemplateRenderer'
            $fileManager: '@CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportFileManager'
        public: true
```

### Phase 4: Testing Strategy

#### Step 4.1: Unit Tests for New Services
- `tests/Unit/Infrastructure/Reporting/ReportContextBuilderTest.php`
- `tests/Unit/Infrastructure/Reporting/TemplateRendererTest.php`
- `tests/Unit/Infrastructure/Reporting/ReportFileManagerTest.php`

#### Step 4.2: Update Existing Tests
- Update `tests/Unit/Infrastructure/Reporting/ReportServiceTest.php` to use mocks for new dependencies

#### Step 4.3: Integration Tests
- Verify end-to-end functionality remains unchanged
- Test error handling across service boundaries

## Acceptance Criteria

### Functional Requirements
- [ ] All existing report generation functionality preserved
- [ ] Public API of ReportService unchanged
- [ ] All output formats (HTML, JSON, Markdown) work correctly
- [ ] Error handling maintains current behavior

### Non-Functional Requirements
- [ ] ReportService reduced from 558 to under 150 lines
- [ ] Each new service under 200 lines
- [ ] All services have single, clear responsibility
- [ ] PHPStan Level 8 compliance maintained
- [ ] Code coverage remains above 90%

### Quality Gates
- [ ] All existing tests pass
- [ ] New unit tests for each service
- [ ] PHP CS Fixer compliance
- [ ] No circular dependencies introduced

## Risk Assessment

### Low Risk
- Context building logic is pure data transformation
- Template rendering has clear input/output boundaries

### Medium Risk
- File management operations require careful error handling
- Service coordination in ReportService needs proper exception propagation

### Mitigation Strategies
- Extract services incrementally, testing each step
- Maintain comprehensive test coverage
- Use dependency injection for easier testing
- Keep public interfaces unchanged

## Timeline

- **Week 1**: Create new service classes and basic structure
- **Week 2**: Extract and test context building logic
- **Week 3**: Extract and test rendering logic
- **Week 4**: Extract file management and finalize refactoring

## Success Metrics

- ReportService LOC reduced by 70% (558 → ~150 lines)
- Each service maintains single responsibility
- All tests pass without modification
- No performance degradation
- Improved maintainability and testability

## Dependencies

- No external dependencies required
- Uses existing Symfony DI container
- Leverages current Twig integration
- Maintains existing filesystem utilities