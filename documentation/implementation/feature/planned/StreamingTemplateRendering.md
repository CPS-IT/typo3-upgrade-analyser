# Streaming Template Rendering Feature Specification

## Feature Overview

- **Feature**: StreamingTemplateRendering
- **Status**: Specification Complete - Ready for Implementation
- **Priority**: Medium - Performance Enhancement
- **Estimated Effort**: 20-30 hours
- **Date**: October 19, 2025

## Overview

This feature implements chunked, streaming template rendering to handle large datasets without memory exhaustion. It processes findings data in chunks rather than loading everything into memory simultaneously, enabling the system to handle unlimited dataset sizes.

## Problem Statement

Current template rendering loads entire datasets into memory before processing:

- **Memory accumulation** during template rendering when processing thousands of findings
- **Monolithic template processing** - all data rendered in single operation
- **No pagination or chunking** for large finding sets
- **Segmentation faults** when memory limits exceeded during HTML generation

### Current Architecture Issues

From our analysis of the segfault issue:
- `TemplateRenderer::renderAllAnalyzerFindingsDetailPages()` processes all extensions simultaneously
- Templates receive complete context with all findings data
- No memory management or progressive rendering capabilities
- Template engines (Twig) hold entire result sets in memory during rendering

## Solution Architecture

### Core Concept: Chunked Template Rendering

Replace monolithic template rendering with a streaming, chunk-based approach that processes data in manageable sizes.

### Components

#### 1. StreamingTemplateRenderer
```php
class StreamingTemplateRenderer implements TemplateRendererInterface
{
    public function renderStreamingReport(ReportContext $context, string $format): StreamingReportResult;
    public function renderChunkedFindings(array $findings, int $chunkSize = 100): Generator;
    public function shouldUseStreaming(ReportContext $context): bool;
    public function getMemoryUsage(): int;
}
```

#### 2. ChunkedDataProvider
```php
class ChunkedDataProvider
{
    public function getExtensionChunks(array $extensions, int $chunkSize): Generator;
    public function getFindingsChunks(array $findings, int $chunkSize): Generator;
    public function estimateChunkMemoryUsage(array $chunk): int;
    public function shouldTriggerStreaming(int $dataSize, int $threshold): bool;
}
```

#### 3. ProgressiveHTMLBuilder
```php
class ProgressiveHTMLBuilder
{
    public function startDocument(string $title): string;
    public function addSection(string $content): void;
    public function addChunk(string $templateName, array $context): string;
    public function finalizeDocument(): string;
    public function getBufferSize(): int;
}
```

#### 4. MemoryAwareRenderer
```php
class MemoryAwareRenderer
{
    public function renderWithMemoryCheck(string $template, array $context): string;
    public function getMemoryLimit(): int;
    public function getCurrentMemoryUsage(): int;
    public function shouldSwitchToStreaming(): bool;
}
```

## Implementation Details

### Configuration

```yaml
# typo3-analyzer.yaml
template_rendering:
  streaming:
    # Enable streaming template rendering
    enabled: true

    # Chunking configuration
    chunk_size: 100                    # Findings per chunk
    extension_chunk_size: 10           # Extensions per chunk

    # Memory management
    memory_threshold: 128              # MB - switch to streaming
    memory_check_interval: 50          # Check every N findings
    max_template_context_size: 64      # MB per template context

    # Progressive rendering
    progressive_html: true             # Build HTML progressively
    buffer_size: 1024                  # KB - HTML buffer size
    flush_threshold: 512               # KB - when to flush buffer

    # Fallback behavior
    fallback_to_standard: true         # Fall back if streaming fails
    max_retries: 3                     # Retry attempts for failed chunks

  # Lazy loading configuration
  lazy_loading:
    enabled: true
    threshold: 500                     # Findings count to trigger lazy loading
    page_size: 50                      # Items per lazy-loaded page
    preload_count: 2                   # Pages to preload

  # Performance monitoring
  performance:
    enable_metrics: true               # Collect rendering metrics
    log_memory_usage: true             # Log memory consumption
    track_render_times: true           # Track chunk render times
```

### Streaming Template Architecture

#### 1. Chunked Extension Processing
```php
class StreamingTemplateRenderer extends TemplateRenderer
{
    public function renderAnalyzerFindingsDetailPages(array $context, string $analyzerType, string $format): array
    {
        $config = $this->configurationService->getStreamingConfig();

        if (!$this->shouldUseStreaming($context, $config)) {
            return parent::renderAnalyzerFindingsDetailPages($context, $analyzerType, $format);
        }

        return $this->renderStreamingDetailPages($context, $analyzerType, $format, $config);
    }

    private function renderStreamingDetailPages(array $context, string $analyzerType, string $format, array $config): array
    {
        $detailPages = [];
        $extensionChunks = $this->dataProvider->getExtensionChunks(
            $context['extension_data'],
            $config['extension_chunk_size']
        );

        foreach ($extensionChunks as $chunk) {
            $chunkPages = $this->renderExtensionChunk($chunk, $analyzerType, $format, $config);
            $detailPages = array_merge($detailPages, $chunkPages);

            // Memory management
            if ($this->memoryRenderer->shouldSwitchToStreaming()) {
                $this->logger->info('Switching to aggressive streaming mode due to memory pressure');
                $config['chunk_size'] = max(10, $config['chunk_size'] / 2);
            }

            // Cleanup processed data
            unset($chunk);
        }

        return $detailPages;
    }
}
```

#### 2. Progressive HTML Generation
```php
class ProgressiveHTMLBuilder
{
    private array $buffer = [];
    private int $bufferSize = 0;
    private int $maxBufferSize;

    public function addChunk(string $templateName, array $context): string
    {
        $chunkHtml = $this->twig->render($templateName, $context);

        $this->buffer[] = $chunkHtml;
        $this->bufferSize += strlen($chunkHtml);

        if ($this->bufferSize > $this->maxBufferSize) {
            return $this->flushBuffer();
        }

        return '';
    }

    public function flushBuffer(): string
    {
        $html = implode('', $this->buffer);
        $this->buffer = [];
        $this->bufferSize = 0;
        return $html;
    }

    public function renderStreamingDocument(ReportContext $context, string $format): Generator
    {
        yield $this->startDocument($context->getTitle());

        foreach ($this->getExtensionChunks($context) as $chunk) {
            $chunkHtml = $this->renderChunk($chunk, $format);
            yield $chunkHtml;

            // Force garbage collection after each chunk
            gc_collect_cycles();
        }

        yield $this->finalizeDocument();
    }
}
```

#### 3. Memory-Aware Template Context
```php
class MemoryAwareRenderer
{
    public function createSafeContext(array $rawContext, int $maxSize): array
    {
        $context = [];
        $estimatedSize = 0;

        foreach ($rawContext as $key => $value) {
            $valueSize = $this->estimateMemoryUsage($value);

            if ($estimatedSize + $valueSize > $maxSize) {
                // Replace large data with placeholders or skip
                if ($this->isEssentialKey($key)) {
                    $context[$key] = $this->createPlaceholder($value);
                }
                continue;
            }

            $context[$key] = $value;
            $estimatedSize += $valueSize;
        }

        return $context;
    }

    private function estimateMemoryUsage($value): int
    {
        if (is_string($value)) {
            return strlen($value) * 1.5; // Account for PHP string overhead
        }
        if (is_array($value)) {
            return count($value) * 1000; // Rough estimate for arrays
        }
        return 1000; // Default estimate
    }
}
```

### Template Updates for Streaming

#### Streaming-Compatible HTML Templates
```html
<!-- findings-table-chunk.html.twig -->
{% for finding in chunk_findings %}
<div class="finding-item" data-finding-id="{{ finding.id }}">
    <h5>{{ finding.message }}</h5>
    <p><strong>File:</strong> {{ finding.file }}:{{ finding.line }}</p>
    <p><strong>Severity:</strong> {{ finding.severity }}</p>

    <!-- No large content - use file references -->
    {% if finding.diffFile %}
        <a href="{{ finding.diffFile.url }}" class="btn btn-sm btn-outline-secondary" target="_blank">
            View Diff
        </a>
    {% endif %}
</div>
{% endfor %}

{% if has_more_chunks %}
<div class="chunk-loading" data-next-chunk="{{ next_chunk_url }}">
    <button class="btn btn-primary load-more-btn">Load More Findings...</button>
</div>
{% endif %}
```

#### Lazy Loading JavaScript
```javascript
// progressive-loading.js
class ProgressiveLoader {
    constructor(config) {
        this.chunkSize = config.chunkSize;
        this.loadedChunks = 0;
        this.totalChunks = config.totalChunks;
    }

    loadNextChunk(url) {
        return fetch(url)
            .then(response => response.text())
            .then(html => {
                this.appendChunk(html);
                this.loadedChunks++;
                this.updateProgress();
            });
    }

    appendChunk(html) {
        const container = document.getElementById('findings-container');
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;

        while (tempDiv.firstChild) {
            container.appendChild(tempDiv.firstChild);
        }
    }

    autoLoadNext() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && this.hasMoreChunks()) {
                    this.loadNextChunk(this.getNextChunkUrl());
                }
            });
        });

        document.querySelectorAll('.chunk-loading').forEach(el => {
            observer.observe(el);
        });
    }
}
```

## Benefits

### Performance Improvements
- **Constant memory usage** regardless of dataset size
- **Eliminates memory accumulation** during template rendering
- **Progressive loading** improves perceived performance
- **Graceful degradation** under memory pressure

### Scalability
- **Handles unlimited findings** through chunking
- **Memory-aware rendering** adapts to available resources
- **Configurable thresholds** for different deployment environments
- **Progressive enhancement** - works without JavaScript

### User Experience
- **Faster initial page loads** with progressive rendering
- **Responsive interface** during data processing
- **Visual progress indicators** for large datasets
- **Smooth scrolling** with lazy loading

## Implementation Strategy

### Phase 1: Core Infrastructure
- [ ] Implement `StreamingTemplateRenderer` with chunking support
- [ ] Create `ChunkedDataProvider` for data segmentation
- [ ] Add `MemoryAwareRenderer` with usage monitoring
- [ ] Implement configuration parsing for streaming settings

### Phase 2: Template Integration
- [ ] Update existing templates to support chunked rendering
- [ ] Create chunk-specific template variants
- [ ] Implement progressive HTML builder
- [ ] Add client-side lazy loading JavaScript

### Phase 3: Memory Management
- [ ] Implement memory usage monitoring and limits
- [ ] Add automatic fallback to streaming mode
- [ ] Create memory pressure detection
- [ ] Implement garbage collection optimization

### Phase 4: Advanced Features
- [ ] Add client-side pagination for large datasets
- [ ] Implement virtual scrolling for massive finding lists
- [ ] Add search and filtering within streamed content
- [ ] Create performance analytics dashboard

## Testing Strategy

### Performance Tests
- [ ] Memory usage tests with varying dataset sizes
- [ ] Template rendering performance benchmarks
- [ ] Client-side loading performance tests
- [ ] Memory leak detection during chunked rendering

### Integration Tests
- [ ] End-to-end streaming workflow tests
- [ ] Fallback behavior when streaming fails
- [ ] Configuration parsing and application tests
- [ ] Cross-browser lazy loading compatibility

### Load Tests
- [ ] Large dataset processing (10,000+ findings)
- [ ] Concurrent user access during streaming
- [ ] Memory pressure simulation tests
- [ ] Network latency impact on progressive loading

## Compatibility

### Backward Compatibility
- Streaming can be disabled via configuration
- Standard templates continue to work when streaming disabled
- Graceful fallback when JavaScript unavailable
- Progressive enhancement approach

### Browser Support
- Modern browsers: Full streaming and lazy loading support
- Legacy browsers: Graceful degradation to standard rendering
- No JavaScript: Basic chunked rendering without progressive loading
- Mobile devices: Optimized chunk sizes for mobile memory constraints

## Future Enhancements

### Advanced Streaming Features
- **Virtual scrolling** for ultra-large datasets
- **Compressed chunk transfer** for faster network performance
- **Client-side caching** of rendered chunks
- **Search integration** across streamed content

### Performance Optimizations
- **Precompiled template chunks** for faster rendering
- **Background chunk preloading** for smoother UX
- **WebWorker integration** for non-blocking processing
- **Service Worker caching** for offline access

## Risk Mitigation

### Potential Issues
- **JavaScript dependency** for optimal experience
- **Complex state management** with chunked data
- **SEO impact** with client-side loading
- **Network overhead** with many small requests

### Mitigation Strategies
- **Progressive enhancement** - works without JavaScript
- **Server-side state management** for critical functionality
- **SEO-friendly fallbacks** with complete server-rendered content
- **Request batching** and **chunk size optimization** for network efficiency
