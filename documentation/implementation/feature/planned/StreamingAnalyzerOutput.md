# Streaming Analyzer Output Feature Specification

## Feature Overview

- **Feature**: StreamingAnalyzerOutput
- **Status**: Specification Complete - Ready for Implementation
- **Priority**: High - Critical Performance Fix
- **Estimated Effort**: 16-24 hours
- **Date**: October 19, 2025

## Overview

This feature implements a streaming file-based approach to handle large analyzer output, eliminating memory issues during report generation by removing large content from in-memory objects and storing it in separate files.

## Problem Statement

Current analyzer implementations store large content (code_before, code_after, diff fields, error messages) directly in finding objects and result data structures. This causes:

- **Memory exhaustion** when processing extensions with thousands of findings
- **Segmentation faults** during HTML template rendering with large datasets
- **Poor scalability** - system fails with enterprise-scale TYPO3 installations
- **Data duplication** - same content stored in objects, arrays, JSON, and templates

### Root Cause Analysis

The segfault investigation revealed:
- News extension generated 650KB of fractor results (reduced to 98KB after truncation)
- Large XML FlexForm content in `code_before`, `code_after`, and `diff` fields
- Memory accumulation during template rendering when processing all findings simultaneously
- No size limits or streaming capabilities in current architecture

## Solution Architecture

### Phase 1: File-Based Large Content Storage

Replace in-memory large content storage with file-based streaming approach.

#### Core Components

1. **StreamingOutputManager**: Manages file storage for large analyzer content
2. **ContentStreamWriter**: Handles streaming content to individual files
3. **FileReference**: Lightweight objects containing file paths instead of content
4. **ConfigurableContentPolicy**: User-configurable rules for what gets streamed

#### Implementation Details

##### StreamingOutputManager
```php
class StreamingOutputManager
{
    public function streamDiffContent(string $extensionKey, string $analyzer, string $fileId, string $content): FileReference;
    public function streamErrorOutput(string $extensionKey, string $analyzer, string $content): FileReference;
    public function getStreamingPolicy(): ContentStreamingPolicy;
    public function cleanupOldFiles(int $maxAge = 86400): int;
}
```

##### ContentStreamWriter
```php
class ContentStreamWriter
{
    public function writeToFile(string $basePath, string $filename, string $content): string;
    public function generateUniqueFilename(string $prefix, string $extension): string;
    public function createDirectoryStructure(string $path): bool;
}
```

##### FileReference
```php
class FileReference implements \JsonSerializable
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $relativePath,
        public readonly int $size,
        public readonly string $mimeType = 'text/plain'
    ) {}

    public function exists(): bool;
    public function getContent(): string;
    public function getUrl(): string;
}
```

### Configuration

Add configuration section for streaming behavior:

```yaml
# typo3-analyzer.yaml
analyzer:
  output:
    streaming:
      enabled: true
      base_directory: 'var/analyzer-output'

    content_policy:
      # Stream content larger than this size (bytes)
      size_threshold: 1024

      # What content to stream to files
      stream_diff_content: true
      stream_code_samples: true
      stream_error_output: true

      # File organization
      organize_by_extension: true
      organize_by_analyzer: true

    file_management:
      # Auto-cleanup files older than this (seconds)
      max_file_age: 86400
      # Maximum total size for streamed files (MB)
      max_total_size: 500

    links:
      # Generate links to diff files in reports
      enable_diff_links: true
      # Include error output links
      enable_error_links: true
      # Link generation strategy: 'relative'|'absolute'|'none'
      link_strategy: 'relative'
```

### File Organization Structure

```
var/analyzer-output/
├── fractor/
│   ├── news/
│   │   ├── findings/
│   │   │   ├── flexform_category_list_xml_L8.diff
│   │   │   ├── flexform_news_xml_L8.diff
│   │   │   └── ...
│   │   └── error_output.txt
│   └── fal_securedownload/
│       └── findings/
│           └── FileTree_xml_L6.diff
├── rector/
│   ├── news/
│   │   ├── findings/
│   │   │   ├── NewsController_php_L45.diff
│   │   │   └── ...
│   │   └── error_output.txt
│   └── ...
└── metadata.json
```

### Template Integration

Update templates to show file links instead of inline content:

#### HTML Templates
```html
<!-- Replace large code blocks with file links -->
{% if finding.diffFile %}
    <div class="diff-file-link">
        <a href="{{ finding.diffFile.url }}" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-file-code"></i> View Diff ({{ finding.diffFile.size|human_readable }})
        </a>
    </div>
{% endif %}

{% if finding.errorOutputFile %}
    <div class="error-output-link">
        <a href="{{ finding.errorOutputFile.url }}" target="_blank" class="btn btn-sm btn-outline-warning">
            <i class="fas fa-exclamation-triangle"></i> View Error Output
        </a>
    </div>
{% endif %}
```

#### Markdown Templates
```markdown
{% if finding.diffFile %}
**Diff**: [View changes]({{ finding.diffFile.relativePath }}) ({{ finding.diffFile.size|human_readable }})
{% endif %}

{% if finding.errorOutputFile %}
**Error Output**: [View details]({{ finding.errorOutputFile.relativePath }})
{% endif %}
```

### Migration Strategy

#### Analyzer Updates

1. **FractorFinding** - Replace content fields with FileReference objects:
```php
class FractorFinding implements AnalyzerFindingInterface, \JsonSerializable
{
    // Remove these large content fields:
    // public readonly string $codeBefore;
    // public readonly string $codeAfter;
    // public readonly string $diff;

    // Replace with file references:
    public readonly ?FileReference $codeBeforeFile;
    public readonly ?FileReference $codeAfterFile;
    public readonly ?FileReference $diffFile;

    // Keep essential data for display
    public readonly string $file;
    public readonly int $line;
    public readonly string $message;
    public readonly string $severity;
}
```

2. **RectorFinding** - Similar file-based approach for Rector findings

3. **AnalysisResult** - Stream error messages:
```php
class AnalysisResult
{
    // Replace: public array $metrics (contains large error_message)
    // With: public ?FileReference $errorOutputFile

    public function addErrorOutput(string $content): void;
    public function getErrorOutputFile(): ?FileReference;
}
```

## Implementation Steps

### Step 1: Core Infrastructure
- [ ] Implement `StreamingOutputManager`
- [ ] Implement `ContentStreamWriter`
- [ ] Implement `FileReference` class
- [ ] Add configuration parsing for streaming settings

### Step 2: Analyzer Integration
- [ ] Update `FractorAnalyzer` to use streaming for diff content
- [ ] Update `RectorAnalyzer` to use streaming for findings
- [ ] Modify `AnalysisResult` to stream error output
- [ ] Update finding classes to use `FileReference` objects

### Step 3: Template Updates
- [ ] Update HTML templates to show file links instead of inline content
- [ ] Update Markdown templates with file link formatting
- [ ] Add CSS styling for file link buttons
- [ ] Implement file download/view functionality

### Step 4: File Management
- [ ] Implement cleanup routines for old files
- [ ] Add file size monitoring and limits
- [ ] Implement directory organization strategies
- [ ] Add file integrity checks

## Benefits

### Performance Improvements
- **Constant memory usage** regardless of finding count or content size
- **Eliminates segmentation faults** during report rendering
- **Scales to unlimited** analyzer output sizes
- **Faster template rendering** with lightweight finding objects

### User Experience
- **Optional detailed content** - users can view diffs when needed
- **Faster report loading** - HTML pages load quickly without large embedded content
- **Better organization** - related files grouped by extension and analyzer
- **Configurable storage** - users control what gets streamed vs. kept inline

### Maintainability
- **Analyzer independence** - new analyzers automatically benefit from streaming
- **Template simplification** - no need to handle large content in templates
- **Memory debugging** - easier to track memory usage without large content objects
- **Future-proof** - architecture supports any analyzer output size

## Testing Strategy

### Unit Tests
- [ ] Test `StreamingOutputManager` file operations
- [ ] Test `FileReference` serialization and content access
- [ ] Test configuration parsing for streaming policies
- [ ] Test finding object creation with file references

### Integration Tests
- [ ] Test complete analyzer workflow with streaming enabled
- [ ] Test report generation with file-based content
- [ ] Test file cleanup and management operations
- [ ] Test template rendering with file references

### Performance Tests
- [ ] Memory usage tests with large analyzer results
- [ ] Template rendering performance with streaming vs. inline content
- [ ] File I/O performance benchmarks
- [ ] Cleanup operation performance tests

## Compatibility

### Backward Compatibility
- Configuration defaults maintain existing behavior when streaming disabled
- Existing finding objects work with null file references
- Templates gracefully handle both inline content and file references
- Analysis results maintain same structure for consumers

### Migration Path
- Phase 1: Introduce streaming infrastructure alongside existing content storage
- Phase 2: Add configuration options for gradual adoption
- Phase 3: Default to streaming for new installations
- Phase 4: Eventually deprecate inline large content storage

## Future Enhancements

### Advanced Features
- **Compressed file storage** for even better space efficiency
- **Cloud storage integration** for distributed deployments
- **Content preview** in reports without loading full files
- **Diff visualization** with syntax highlighting for file content
- **Search functionality** across streamed analyzer output

### Analytics
- **Storage usage monitoring** and reporting
- **File access patterns** for optimization
- **Performance metrics** for streaming vs. inline approaches
- **User adoption** of file links vs. inline content

## Risk Mitigation

### Potential Issues
- **File system permissions** - streaming requires write access to output directory
- **File cleanup complexity** - managing lifecycle of numerous small files
- **Link generation** - relative paths must work across different deployment scenarios
- **Content access** - file references may break if files are moved or deleted

### Mitigation Strategies
- **Robust error handling** for file operations with graceful fallback
- **Configurable cleanup policies** with safe defaults
- **Path validation** and URL generation with proper escaping
- **File integrity checks** and automatic regeneration of missing content
