# Handle Fractor Parsing Errors Feature

## Feature Overview

**Feature**: HandleFractorParsingErrors
**Status**: Specification Complete - Ready for Implementation
**Priority**: Medium - Stability Enhancement
**Estimated Effort**: 8-12 hours
**Date**: October 18, 2025

## Problem Statement

The Fractor analyzer fails when encountering poor code quality in target TYPO3 installations, causing analysis failures that prevent comprehensive upgrade assessment. Current issues include:

### Identified Error Types

1. **YAML Parsing Errors**:
   - Multiple document separators (`---`) in ExtensionBuilder settings.yaml
   - Invalid YAML syntax in configuration files
   - Error: `Multiple documents are not supported in "settings.yaml" at line 2`

2. **XML Namespace Errors**:
   - Undefined namespace prefixes in Fluid templates and XML configurations
   - Error: `Namespace prefix f on for is not defined in Entity, line: 10`
   - Malformed XML in extension templates

3. **Analysis Interruption**:
   - Fractor stops processing when encountering malformed files
   - Results in empty findings arrays and failed analysis status
   - Prevents generation of comprehensive upgrade reports

### Current Impact

- **Analysis Failure**: Extensions with code quality issues cause complete Fractor analysis failure
- **Missing Reports**: HTML reports not generated when Fractor encounters errors
- **Incomplete Assessment**: Users don't get upgrade guidance for analyzable parts of problematic extensions
- **Poor User Experience**: Cryptic error messages instead of actionable recommendations

## Technical Requirements

### Core Functionality

#### 1. Error Detection and Classification
- **YAML Error Handling**: Detect and categorize YAML parsing failures
- **XML Error Handling**: Identify namespace and syntax issues in XML files
- **General Parsing Errors**: Catch and classify other Fractor parsing failures
- **Error Pattern Recognition**: Build regex patterns for common error types

#### 2. Graceful Degradation Strategy
- **Partial Analysis**: Continue analysis when possible, skip problematic files
- **Error Isolation**: Isolate errors to specific files without failing entire analysis
- **Recovery Mechanisms**: Implement fallback strategies for common error patterns
- **Progress Preservation**: Maintain analysis results for successfully processed files

#### 3. Enhanced Error Reporting
- **User-Friendly Messages**: Convert technical errors to actionable recommendations
- **Error Context**: Provide file paths and line numbers for manual fixes
- **Severity Classification**: Categorize errors by impact on upgrade process
- **Remediation Guidance**: Suggest specific fixes for common issues

#### 4. Pre-processing Validation
- **File Validation**: Check file syntax before passing to Fractor
- **Auto-correction**: Apply safe fixes for common syntax issues
- **Skip Lists**: Maintain configurable lists of problematic file patterns
- **Validation Reports**: Generate reports of files requiring manual attention

## Solution Design

### Architecture Components

#### 1. Error Detection Layer
```php
// New service: FractorErrorHandler
class FractorErrorHandler
{
    public function handleFractorErrors(string $output, string $errorOutput): ErrorHandlingResult
    {
        // Parse error output and classify issues
        // Return structured error information
    }

    public function extractRecoverableErrors(array $errors): array
    {
        // Identify errors that don't require analysis failure
    }
}
```

#### 2. File Pre-processor
```php
// New service: FilePreProcessor
class FilePreProcessor
{
    public function validateAndPrepareFiles(array $filePaths): ValidationResult
    {
        // Check file syntax before Fractor processing
        // Apply safe auto-corrections where possible
    }

    public function shouldSkipFile(string $filePath, array $errors): bool
    {
        // Determine if file should be excluded from analysis
    }
}
```

#### 3. Enhanced Result Parser
```php
// Enhancement to existing FractorResultParser
class FractorResultParser
{
    public function parseWithErrorHandling(
        string $output,
        string $errorOutput,
        FractorErrorHandler $errorHandler
    ): FractorAnalysisSummary {
        // Parse results with graceful error handling
        // Continue processing despite individual file failures
    }
}
```

### Error Handling Strategies

#### 1. YAML Document Separation
- **Detection**: Identify multiple `---` separators in YAML files
- **Auto-fix**: Split multi-document YAML into single documents
- **Fallback**: Skip problematic YAML sections, process valid parts
- **Reporting**: Warn about YAML structure issues

#### 2. XML Namespace Resolution
- **Detection**: Parse XML namespace errors from Fractor output
- **Validation**: Pre-validate XML files before Fractor processing
- **Skipping**: Exclude malformed XML from Fractor analysis
- **Reporting**: List XML files requiring manual namespace fixes

#### 3. Progressive Analysis
- **File-by-File Processing**: Process files individually to isolate errors
- **Batch Recovery**: Continue with remaining files when individual files fail
- **Partial Results**: Return results for successfully analyzed files
- **Error Aggregation**: Collect and report all encountered issues

## Implementation Plan

### Phase 1: Error Detection Infrastructure

#### Step 1.1: Create FractorErrorHandler
**File**: `src/Infrastructure/Analyzer/Fractor/FractorErrorHandler.php`

```php
<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor;

class FractorErrorHandler
{
    private const YAML_MULTI_DOCUMENT_PATTERN = '/Multiple documents are not supported.*at line (\d+)/';
    private const XML_NAMESPACE_PATTERN = '/Namespace prefix .* is not defined.*line: (\d+)/';

    public function parseErrorOutput(string $errorOutput): array
    {
        $errors = [];

        // Parse YAML errors
        if (preg_match_all(self::YAML_MULTI_DOCUMENT_PATTERN, $errorOutput, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $errors[] = new FractorError(
                    type: FractorErrorType::YAML_MULTI_DOCUMENT,
                    message: $match[0],
                    line: (int)$match[1],
                    severity: FractorErrorSeverity::WARNING,
                    recoverable: true
                );
            }
        }

        // Parse XML namespace errors
        if (preg_match_all(self::XML_NAMESPACE_PATTERN, $errorOutput, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $errors[] = new FractorError(
                    type: FractorErrorType::XML_NAMESPACE,
                    message: $match[0],
                    line: (int)$match[1],
                    severity: FractorErrorSeverity::WARNING,
                    recoverable: false
                );
            }
        }

        return $errors;
    }

    public function shouldContinueAnalysis(array $errors): bool
    {
        // Determine if analysis can continue despite errors
        $criticalErrors = array_filter($errors, fn($error) =>
            $error->severity === FractorErrorSeverity::CRITICAL
        );

        return count($criticalErrors) === 0;
    }
}
```

#### Step 1.2: Create Error Value Objects
**Files**:
- `src/Infrastructure/Analyzer/Fractor/FractorError.php`
- `src/Infrastructure/Analyzer/Fractor/FractorErrorType.php`
- `src/Infrastructure/Analyzer/Fractor/FractorErrorSeverity.php`

```php
enum FractorErrorType: string
{
    case YAML_MULTI_DOCUMENT = 'yaml_multi_document';
    case XML_NAMESPACE = 'xml_namespace';
    case PARSE_ERROR = 'parse_error';
    case FILE_ACCESS = 'file_access';
    case UNKNOWN = 'unknown';
}

enum FractorErrorSeverity: string
{
    case CRITICAL = 'critical';    // Stops all analysis
    case WARNING = 'warning';      // Continue with warnings
    case INFO = 'info';           // Informational only
}
```

### Phase 2: Enhanced Result Parsing

#### Step 2.1: Update FractorResultParser
**File**: `src/Infrastructure/Analyzer/Fractor/FractorResultParser.php`

```php
public function parseWithErrorHandling(
    string $output,
    string $errorOutput,
    FractorErrorHandler $errorHandler
): FractorAnalysisSummary {
    $errors = $errorHandler->parseErrorOutput($errorOutput);
    $canContinue = $errorHandler->shouldContinueAnalysis($errors);

    if (!$canContinue) {
        return new FractorAnalysisSummary(
            filesScanned: 0,
            rulesApplied: 0,
            findings: [],
            successful: false,
            errorMessage: $this->formatCriticalErrors($errors)
        );
    }

    // Continue with enhanced parsing that handles partial failures
    return $this->parseWithPartialFailures($output, $errors);
}

private function parseWithPartialFailures(string $output, array $knownErrors): FractorAnalysisSummary
{
    // Parse output but mark analysis as partially successful
    // Include error information in the summary
    // Generate findings for successfully processed files
}
```

#### Step 2.2: Update FractorAnalysisSummary
**File**: `src/Infrastructure/Analyzer/Fractor/FractorAnalysisSummary.php`

```php
public function __construct(
    public int $filesScanned,
    public int $rulesApplied,
    public array $findings,
    public bool $successful,
    public int $changeBlocks = 0,
    public int $changedLines = 0,
    public array $filePaths = [],
    public array $appliedRules = [],
    public ?string $errorMessage = null,
    public array $processingErrors = [], // New field
    public bool $partialSuccess = false,  // New field
) {}

public function hasProcessingErrors(): bool
{
    return !empty($this->processingErrors);
}

public function getProcessingErrorCount(): int
{
    return count($this->processingErrors);
}
```

### Phase 3: File Pre-processing

#### Step 3.1: Create FilePreProcessor
**File**: `src/Infrastructure/Analyzer/Fractor/FilePreProcessor.php`

```php
class FilePreProcessor
{
    public function preprocessFiles(array $filePaths): PreProcessingResult
    {
        $validFiles = [];
        $skippedFiles = [];
        $fixedFiles = [];

        foreach ($filePaths as $filePath) {
            $result = $this->processFile($filePath);

            match($result->status) {
                FileStatus::VALID => $validFiles[] = $filePath,
                FileStatus::FIXED => $fixedFiles[] = $filePath,
                FileStatus::SKIP => $skippedFiles[] = $filePath,
            };
        }

        return new PreProcessingResult(
            validFiles: $validFiles,
            fixedFiles: $fixedFiles,
            skippedFiles: $skippedFiles
        );
    }

    private function processFile(string $filePath): FileProcessingResult
    {
        // Validate file syntax
        // Apply safe auto-fixes
        // Determine if file should be processed
    }
}
```

### Phase 4: Integration and Testing

#### Step 4.1: Update FractorAnalyzer
**File**: `src/Infrastructure/Analyzer/Fractor/FractorAnalyzer.php`

```php
public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult
{
    try {
        // Pre-process files to identify issues
        $preprocessingResult = $this->filePreProcessor->preprocessFiles($extension->getFilePaths());

        if ($preprocessingResult->hasSkippedFiles()) {
            $this->logger->warning('Skipped problematic files during Fractor analysis', [
                'extension' => $extension->getKey(),
                'skipped_count' => count($preprocessingResult->getSkippedFiles())
            ]);
        }

        // Run Fractor with error handling
        $executionResult = $this->fractorExecutor->execute($preprocessingResult->getValidFiles());

        // Parse results with enhanced error handling
        $summary = $this->resultParser->parseWithErrorHandling(
            $executionResult->getOutput(),
            $executionResult->getErrorOutput(),
            $this->errorHandler
        );

        // Generate recommendations based on results and errors
        $recommendations = $this->generateRecommendationsWithErrors($summary);

        return $this->createAnalysisResult($summary, $recommendations);

    } catch (AnalysisException $e) {
        return $this->handleAnalysisFailure($extension, $e);
    }
}
```

#### Step 4.2: Comprehensive Testing
**Test Files**:
- `tests/Unit/Infrastructure/Analyzer/Fractor/FractorErrorHandlerTest.php`
- `tests/Unit/Infrastructure/Analyzer/Fractor/FilePreProcessorTest.php`
- `tests/Integration/Infrastructure/Analyzer/Fractor/ErrorHandlingIntegrationTest.php`

### Phase 5: Configuration and Documentation

#### Step 5.1: Add Configuration Options
**File**: `config/services.yaml`

```yaml
services:
    CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorErrorHandler:
        public: false

    CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FilePreProcessor:
        public: false
        arguments:
            $skipPatterns:
                - '*/Tests/*'
                - '*/Documentation/*'
                - '*/.Build/*'
```

#### Step 5.2: Update Configuration Schema
**File**: `documentation/configuration.example.yaml`

```yaml
analyzers:
  fractor:
    enabled: true
    error_handling:
      continue_on_errors: true
      skip_malformed_files: true
      auto_fix_yaml: true
      max_errors_per_extension: 10
```

## Acceptance Criteria

### Functional Requirements
- [ ] Fractor analysis continues when encountering malformed YAML/XML files
- [ ] Processing errors are captured and reported without stopping analysis
- [ ] Partial results are returned for extensions with some problematic files
- [ ] User-friendly error messages replace technical parsing errors
- [ ] HTML reports are generated even when some files cannot be processed

### Non-Functional Requirements
- [ ] Analysis performance impact less than 10% overhead
- [ ] Error handling adds less than 50 LOC per class
- [ ] All error scenarios covered by unit tests
- [ ] PHPStan Level 8 compliance maintained
- [ ] Error patterns configurable via YAML

### Quality Gates
- [ ] All existing Fractor tests pass
- [ ] New error handling tests achieve 100% coverage
- [ ] Integration tests validate error scenarios with real problematic files
- [ ] No false positives in error detection
- [ ] Graceful degradation preserves as much analysis data as possible

## Risk Assessment

### Low Risk
- **Error detection patterns**: Well-defined regex patterns for known error types
- **Graceful degradation**: Existing partial success patterns in other analyzers

### Medium Risk
- **File pre-processing**: Auto-fixing files could introduce subtle issues
- **Performance impact**: Additional validation steps may slow analysis

### High Risk
- **Analysis completeness**: Risk of missing issues in skipped problematic files

### Mitigation Strategies
- **Conservative auto-fixing**: Only apply safe, well-tested fixes
- **Comprehensive logging**: Record all skipped files and reasons
- **Validation reports**: Generate separate reports for files requiring manual attention
- **Configurable thresholds**: Allow users to tune error tolerance levels

## Success Metrics

- **Analysis Success Rate**: Increase from current ~60% to >90% for problematic extensions
- **Report Generation**: HTML reports generated even with partial Fractor failures
- **Error Transparency**: Users receive actionable error information instead of cryptic failures
- **Processing Coverage**: At least 80% of files processed even in problematic extensions
- **User Experience**: Clear guidance on manual fixes needed for problematic files

## Dependencies

- **Enhanced FractorAnalysisSummary**: Update summary to include error information
- **Logging Infrastructure**: Leverage existing logging for error tracking
- **Configuration System**: Use existing YAML configuration for error handling settings
- **Testing Framework**: Utilize current testing infrastructure for error scenarios

## Timeline

- **Week 1**: Implement error detection and classification infrastructure
- **Week 2**: Create file pre-processing and validation logic
- **Week 3**: Integrate error handling into existing Fractor analyzer
- **Week 4**: Testing, configuration, and documentation

This feature ensures robust Fractor analysis that gracefully handles poor code quality in target TYPO3 installations while providing users with comprehensive upgrade guidance and clear remediation steps for problematic files.
