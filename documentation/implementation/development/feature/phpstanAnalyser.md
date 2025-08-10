# Feature Specification: PHPStan Analyzer

**Status**: Planning  
**Priority**: Medium  
**Estimated Effort**: 8-12 hours  
**Target Version**: 0.2  
**Dependencies**: Core Analyzer System, External Tool Integration

## Overview

The PHPStan Analyzer provides static code analysis capabilities for TYPO3 extensions using PHPStan. It identifies potential runtime errors, type mismatches, and code quality issues before they manifest in production environments.

### Business Value

- **Risk Reduction**: Identify potential runtime errors before upgrade
- **Code Quality Assessment**: Measure extension maintainability and reliability  
- **Upgrade Planning**: Understand technical debt and complexity
- **Developer Guidance**: Provide actionable recommendations for code improvements

## Feature Description

### Core Functionality

The PHPStan Analyzer integrates PHPStan static analysis into the TYPO3 Upgrade Analyzer workflow, providing:

1. **Extension-Specific Analysis**: Analyzes individual TYPO3 extensions with proper context
2. **Categorized Issue Reporting**: Groups issues by severity, type, and component
3. **Risk Scoring**: Calculates upgrade risk based on static analysis findings
4. **Actionable Recommendations**: Provides specific guidance for issue resolution
5. **Trend Analysis**: Tracks code quality metrics across analysis runs

### Key Features

#### 1. Intelligent Extension Analysis
- **Context-Aware Scanning**: Understands TYPO3 extension structure and conventions
- **Dependency Resolution**: Includes TYPO3 core and extension dependencies in analysis
- **Configuration Integration**: Uses extension-specific PHPStan configurations when present
- **Incremental Analysis**: Supports analyzing only changed files for performance

#### 2. Issue Classification System
```
Critical Issues (Risk Score: 8-10)
├── Runtime Errors (method.notFound, class.notFound)
├── Type Violations (argument.type, return.type)
└── Logic Errors (unreachable.code, impossible.type)

High Priority Issues (Risk Score: 6-7)
├── Compatibility Issues (deprecated calls, removed features)
├── Safety Issues (null pointer risks, array bounds)
└── Performance Issues (inefficient patterns)

Medium Priority Issues (Risk Score: 4-5)
├── Code Quality (redundant checks, unused code)
├── Type Annotations (missing types, generic types)
└── Best Practices (naming conventions, structure)

Low Priority Issues (Risk Score: 1-3)
├── Documentation (missing docs, outdated comments)
├── Style Issues (formatting, organization)
└── Optimization Opportunities (micro-optimizations)
```

#### 3. TYPO3-Specific Rule Sets
- **Core API Validation**: Checks against TYPO3 API compatibility
- **Extension Standards**: Validates extension development best practices
- **Version Compatibility**: Identifies version-specific compatibility issues
- **Security Patterns**: Detects common security anti-patterns

#### 4. Advanced Reporting
- **Executive Summary**: High-level code quality assessment
- **Detailed Issue Breakdown**: Categorized and prioritized issue lists
- **File-Level Analysis**: Per-file quality metrics and issues
- **Trend Visualization**: Quality metrics over time
- **Comparison Reports**: Before/after upgrade analysis

## Technical Architecture

### Component Structure

```
PhpstanAnalyzer
├── Core/
│   ├── PhpstanAnalyzer.php              # Main analyzer implementation
│   ├── PhpstanExecutor.php              # PHPStan execution wrapper
│   ├── PhpstanConfigGenerator.php       # Dynamic configuration generation
│   └── PhpstanResultParser.php          # Output parsing and normalization
├── Classification/
│   ├── IssueClassifier.php              # Issue categorization logic
│   ├── RiskScorer.php                   # Risk assessment algorithms
│   ├── SeverityCalculator.php           # Severity level determination
│   └── RecommendationGenerator.php      # Action item generation
├── Rules/
│   ├── Typo3CoreRuleSet.php             # TYPO3 core API rules
│   ├── ExtensionStandardRuleSet.php     # Extension development rules
│   ├── SecurityRuleSet.php              # Security-focused rules
│   └── CompatibilityRuleSet.php         # Version compatibility rules
└── Reporting/
    ├── PhpstanReport.php                # Report data structure
    ├── IssueSummaryGenerator.php        # Summary statistics
    ├── TrendAnalyzer.php                # Historical trend analysis
    └── ReportFormatter.php              # Output formatting
```

### Integration Points

#### 1. Analyzer Interface Implementation
```php
class PhpstanAnalyzer extends AbstractCachedAnalyzer
{
    public function getName(): string
    {
        return 'phpstan';
    }

    public function getDescription(): string
    {
        return 'Static code analysis using PHPStan';
    }

    public function supports(Extension $extension): bool
    {
        return $extension->hasPhpFiles();
    }

    public function getRequiredTools(): array
    {
        return ['phpstan'];
    }

    protected function doAnalyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        // Implementation details...
    }
}
```

#### 2. Configuration Generation
```php
class PhpstanConfigGenerator
{
    public function generateConfig(Extension $extension, AnalysisContext $context): PhpstanConfiguration
    {
        $config = new PhpstanConfiguration();
        
        // Set analysis level based on extension maturity
        $config->setLevel($this->determineAnalysisLevel($extension));
        
        // Include TYPO3 core stubs and extensions
        $config->addIncludePaths($this->getTypo3Includes($context));
        
        // Add extension-specific rules
        $config->addRuleSet($this->selectRuleSet($extension, $context));
        
        // Configure ignoring patterns for external dependencies
        $config->addIgnorePatterns($this->getIgnorePatterns($extension));
        
        return $config;
    }
}
```

#### 3. Result Processing
```php
class PhpstanResultParser
{
    public function parseResults(string $phpstanOutput): PhpstanAnalysisResult
    {
        $jsonData = json_decode($phpstanOutput, true);
        
        $issues = [];
        foreach ($jsonData['files'] as $file => $fileErrors) {
            foreach ($fileErrors['messages'] as $error) {
                $issues[] = new PhpstanIssue(
                    file: $file,
                    line: $error['line'],
                    message: $error['message'],
                    identifier: $error['identifier'] ?? null,
                    severity: $this->mapSeverity($error)
                );
            }
        }
        
        return new PhpstanAnalysisResult($issues);
    }
}
```

### Data Structures

#### PhpstanIssue Value Object
```php
readonly class PhpstanIssue
{
    public function __construct(
        public string $file,
        public int $line,
        public string $message,
        public ?string $identifier,
        public IssueSeverity $severity,
        public IssueCategory $category,
        public array $metadata = []
    ) {}
}
```

#### Analysis Result Enhancement
```php
class PhpstanAnalysisResult extends AnalysisResult
{
    private array $issuesByCategory = [];
    private array $issuesBySeverity = [];
    private CodeQualityMetrics $qualityMetrics;
    
    public function getIssuesByCategory(): array
    {
        return $this->issuesByCategory;
    }
    
    public function getQualityScore(): float
    {
        return $this->qualityMetrics->getOverallScore();
    }
}
```

## Implementation Plan

### Phase 1: Core Infrastructure (4-5 hours)
1. **Basic Analyzer Implementation**
   - Create PhpstanAnalyzer class extending AbstractCachedAnalyzer
   - Implement PHPStan execution wrapper
   - Add basic result parsing

2. **Configuration Management**
   - Implement dynamic PHPStan configuration generation
   - Add TYPO3-specific include paths and stubs
   - Create base rule set configuration

3. **Integration Testing**
   - Add unit tests for core components
   - Create integration tests with sample extensions
   - Verify PHPStan tool detection and execution

### Phase 2: Issue Classification (3-4 hours)
1. **Issue Categorization**
   - Implement issue classifier based on PHPStan error identifiers
   - Create severity mapping from PHPStan messages
   - Add risk scoring algorithms

2. **TYPO3-Specific Rules**
   - Develop extension-specific rule sets
   - Add TYPO3 core API compatibility checks
   - Implement version-specific validation rules

3. **Quality Metrics**
   - Create code quality scoring system
   - Implement trend analysis capabilities
   - Add comparative analysis features

### Phase 3: Advanced Features (2-3 hours)
1. **Enhanced Reporting**
   - Implement detailed issue breakdowns
   - Add file-level analysis summaries
   - Create actionable recommendation system

2. **Performance Optimization**
   - Add incremental analysis support
   - Implement intelligent caching strategies
   - Optimize for large extension analysis

3. **User Experience**
   - Add progress reporting for long-running analysis
   - Implement configurable analysis levels
   - Add interactive issue filtering

## Configuration Options

### Analysis Configuration
```yaml
analyzers:
  phpstan:
    enabled: true
    level: auto              # auto, 0-9, or max
    timeout: 300             # seconds
    memory_limit: '1G'       # PHPStan memory limit
    include_tests: false     # analyze test files
    custom_config: null      # path to custom phpstan.neon
    
    rules:
      typo3_core: true       # TYPO3 core API rules
      extension_standards: true  # Extension development standards
      security: true         # Security-focused rules
      compatibility: true    # Version compatibility rules
      
    reporting:
      include_context: true  # include code context in reports
      group_by_severity: true
      max_issues_per_file: 50
      
    cache:
      enabled: true
      ttl: 7200              # 2 hours cache
      invalidate_on_change: true
```

### Rule Set Configuration
```yaml
phpstan_rules:
  typo3_core:
    deprecated_api: error
    removed_api: error
    incompatible_types: error
    
  extension_standards:
    missing_docblocks: warning
    naming_conventions: warning
    file_structure: info
    
  security:
    sql_injection: error
    xss_vulnerability: error
    unsafe_file_access: error
```

## Expected Output

### Analysis Result Structure
```php
[
    'analyzer' => 'phpstan',
    'extension' => 'example_extension',
    'summary' => [
        'total_issues' => 47,
        'critical_issues' => 3,
        'high_priority_issues' => 12,
        'quality_score' => 7.2,
        'risk_score' => 4.8,
        'files_analyzed' => 23,
        'analysis_duration' => 15.2
    ],
    'issues_by_category' => [
        'runtime_errors' => [
            'count' => 3,
            'issues' => [...]
        ],
        'type_violations' => [
            'count' => 12,
            'issues' => [...]
        ]
    ],
    'file_analysis' => [
        'Classes/Controller/ExampleController.php' => [
            'issues' => 5,
            'quality_score' => 6.8,
            'recommendations' => [...]
        ]
    ],
    'recommendations' => [
        'high_priority' => [
            'Fix method not found errors in ExampleController',
            'Add type hints to improve type safety'
        ],
        'medium_priority' => [
            'Remove unused methods and properties',
            'Add missing PHPDoc blocks'
        ]
    ]
]
```

### Report Templates

#### Executive Summary
```
PHPStan Analysis: example_extension v2.1.0
═══════════════════════════════════════════

Quality Score: 7.2/10 (Good)
Risk Score: 4.8/10 (Medium Risk)

Issues Found: 47 total
├── Critical: 3 (requires immediate attention)
├── High: 12 (should be addressed before upgrade)
├── Medium: 20 (code quality improvements)
└── Low: 12 (optional enhancements)

Top Recommendations:
1. Fix undefined method calls in ExampleController (Critical)
2. Add type declarations for better compatibility (High)
3. Remove unused code to improve maintainability (Medium)
```

#### Detailed Issue Breakdown
```
Critical Issues (3)
═══════════════════

📍 Classes/Controller/ExampleController.php:42
   Error: Call to undefined method processData()
   Impact: Runtime fatal error
   Fix: Implement missing method or remove call

📍 Classes/Service/DataService.php:128  
   Error: Argument type mismatch (string|null → string)
   Impact: Potential type error
   Fix: Add null check or make parameter nullable
```

## Testing Strategy

### Unit Tests
- **Analyzer Core**: Test PHPStan execution and result parsing
- **Issue Classification**: Verify categorization and risk scoring
- **Configuration Generation**: Test TYPO3-specific config creation
- **Report Generation**: Validate output formatting and structure

### Integration Tests
- **Real Extension Analysis**: Test with actual TYPO3 extensions
- **TYPO3 Version Compatibility**: Verify across different TYPO3 versions
- **Large Extension Handling**: Performance testing with complex extensions
- **Error Handling**: Test behavior with malformed or problematic code

### Performance Tests
- **Execution Time**: Measure analysis duration for various extension sizes
- **Memory Usage**: Monitor memory consumption during analysis
- **Cache Effectiveness**: Verify caching improves performance
- **Incremental Analysis**: Test selective re-analysis efficiency

## Benefits and Use Cases

### For Extension Developers
- **Pre-Release Quality Assurance**: Identify issues before publication
- **Continuous Integration**: Automated code quality checking
- **Refactoring Guidance**: Understand technical debt and improvement opportunities
- **TYPO3 Best Practices**: Learn and apply framework conventions

### For System Administrators
- **Upgrade Risk Assessment**: Evaluate extension quality before upgrading TYPO3
- **Extension Selection**: Compare code quality when choosing between alternatives
- **Maintenance Planning**: Prioritize extensions needing attention
- **Security Assessment**: Identify potential security vulnerabilities

### For Agencies and Consultants
- **Project Planning**: Estimate effort required for extension updates
- **Client Communication**: Provide objective quality assessments
- **Code Review**: Systematic analysis of client extension code
- **Training Material**: Use analysis results to educate developers

## Future Enhancements

### Version 2.1 Enhancements
- **Custom Rule Development**: Allow users to define extension-specific rules
- **IDE Integration**: Export issues in formats compatible with popular IDEs
- **Automated Fixes**: Suggest and apply simple automated corrections
- **Baseline Support**: Track quality improvements over time

### Version 2.2 Enhancements
- **Machine Learning**: Use ML to improve issue prioritization
- **Cross-Extension Analysis**: Analyze extension interactions and dependencies
- **Performance Profiling**: Integrate static analysis with performance insights
- **Documentation Generation**: Generate quality documentation from analysis

## Success Metrics

### Technical Metrics
- **Analysis Accuracy**: >95% of critical issues are valid problems
- **Performance**: Analyze medium extensions (<100 files) in <30 seconds  
- **Coverage**: Successfully analyze >90% of common extension patterns
- **Cache Effectiveness**: >80% cache hit rate for repeated analysis

### User Experience Metrics
- **Actionability**: >80% of recommendations are considered useful
- **Integration Success**: <15 minutes to add to existing analysis workflows
- **Error Rate**: <5% of analyses fail due to tool issues
- **User Satisfaction**: >8/10 rating for usefulness and accuracy

This PHPStan Analyzer would provide significant value in assessing extension quality and upgrade risks, making it an essential component of comprehensive TYPO3 upgrade analysis.