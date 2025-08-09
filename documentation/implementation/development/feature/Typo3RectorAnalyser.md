# TYPO3 Rector Analyzer - Feature Plan

## 🎯 **IMPLEMENTATION STATUS: PLANNED** 📋

**Last Updated**: August 2025  
**Priority**: High  
**Dependencies**: AbstractCachedAnalyzer, Version compatibility system, Extension discovery

### Feature Overview

The TYPO3 Rector Analyzer integrates the powerful TYPO3 Rector tool to provide automated code quality analysis, deprecation detection, and upgrade path guidance. This analyzer leverages the existing `ssch/typo3-rector` package to perform static code analysis and identify code patterns that need updating for TYPO3 version compatibility.

### Business Value
- **Automated Code Analysis**: Detect deprecated code patterns without manual review
- **Upgrade Guidance**: Provide specific code changes needed for TYPO3 upgrades
- **Risk Assessment**: Quantify upgrade complexity based on required changes
- **Developer Productivity**: Reduce manual code review time and potential oversight
- **Quality Assurance**: Ensure code follows TYPO3 best practices and conventions

## Technical Requirements

### Core Analysis Capabilities

1. **Rector Rule Execution**
   - Execute TYPO3-specific Rector rules against extension code
   - Support for configurable rule sets based on target TYPO3 version
   - Parse and analyze Rector output for actionable insights
   - Handle different rule categories (deprecations, breaking changes, best practices)

2. **Code Quality Metrics**
   - Count of required changes per rule type
   - Severity classification of identified issues
   - Estimated effort/complexity scoring
   - File-level and extension-level aggregation

3. **Version-Specific Analysis**
   - Rule selection based on source and target TYPO3 versions
   - Progressive analysis for multi-version upgrade paths
   - Breaking change detection across version boundaries
   - Backward compatibility assessment

4. **Change Classification**
   - **Breaking Changes**: Must-fix issues preventing upgrade
   - **Deprecations**: Soon-to-be-removed features requiring attention
   - **Improvements**: Code quality enhancements and best practices
   - **Performance**: Optimizations and performance-related changes

5. **Integration with Existing System**
   - Extends AbstractCachedAnalyzer for performance optimization
   - Integrates with Extension entity for extension-specific analysis
   - Respects analyzer interface contracts and patterns
   - Provides consistent risk scoring methodology

## Implementation Architecture

### Service Layer

#### Core Analyzer
```php
// src/Infrastructure/Analyzer/Typo3RectorAnalyzer.php
class Typo3RectorAnalyzer extends AbstractCachedAnalyzer
{
    public function __construct(
        CacheService $cacheService,
        LoggerInterface $logger,
        private readonly RectorExecutor $rectorExecutor,
        private readonly RectorConfigGenerator $configGenerator,
        private readonly RectorResultParser $resultParser,
        private readonly RectorRuleRegistry $ruleRegistry
    );

    public function getName(): string; // 'typo3_rector'
    public function getDescription(): string;
    public function supports(Extension $extension): bool;
    public function getRequiredTools(): array; // ['php', 'rector']
    public function hasRequiredTools(): bool;
    
    protected function doAnalyze(Extension $extension, AnalysisContext $context): AnalysisResult;
    protected function getAnalyzerSpecificCacheKeyComponents(Extension $extension, AnalysisContext $context): array;

    private function generateRectorConfig(Extension $extension, AnalysisContext $context): string;
    private function executeRectorAnalysis(string $configPath, string $extensionPath): RectorExecutionResult;
    private function calculateRiskScore(array $findings): float;
    private function generateRecommendations(array $findings, AnalysisContext $context): array;
}
```

#### Rector Execution Engine
```php
// src/Infrastructure/Analyzer/Rector/RectorExecutor.php
class RectorExecutor
{
    public function __construct(
        private readonly string $rectorBinaryPath,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 300
    );

    public function execute(string $configPath, string $targetPath, array $options = []): RectorExecutionResult;
    public function executeWithRules(array $ruleNames, string $targetPath): RectorExecutionResult;
    public function isAvailable(): bool;
    public function getVersion(): ?string;

    private function buildCommand(string $configPath, string $targetPath, array $options): array;
    private function parseOutput(string $output): array;
    private function handleExecutionErrors(int $exitCode, string $stderr): void;
}

// src/Infrastructure/Analyzer/Rector/RectorExecutionResult.php
class RectorExecutionResult
{
    public function __construct(
        private readonly bool $successful,
        private readonly array $findings,
        private readonly array $errors,
        private readonly float $executionTime,
        private readonly int $exitCode,
        private readonly string $rawOutput
    );

    public function isSuccessful(): bool;
    public function getFindings(): array; // Array of RectorFinding objects
    public function hasErrors(): bool;
    public function getErrors(): array;
    public function getExecutionTime(): float;
    public function getProcessedFileCount(): int;
    public function getTotalIssueCount(): int;
}
```

#### Configuration Management
```php
// src/Infrastructure/Analyzer/Rector/RectorConfigGenerator.php
class RectorConfigGenerator
{
    public function __construct(
        private readonly RectorRuleRegistry $ruleRegistry,
        private readonly string $tempDirectory
    );

    public function generateConfig(Extension $extension, AnalysisContext $context): string;
    public function generateMinimalConfig(array $ruleNames, string $targetPath): string;

    private function selectRulesForVersion(Version $sourceVersion, Version $targetVersion): array;
    private function buildConfigArray(array $rules, string $targetPath, array $options): array;
    private function writeConfigFile(array $config): string;
    private function getPhpVersion(AnalysisContext $context): string;
}

// src/Infrastructure/Analyzer/Rector/RectorRuleRegistry.php
class RectorRuleRegistry
{
    private const TYPO3_VERSION_RULES = [
        '11.5' => [
            \Ssch\TYPO3Rector\Rector\v11\v5\SubstituteGeneralUtilityMakeInstanceCallsRector::class,
            // ... more rules
        ],
        '12.0' => [
            \Ssch\TYPO3Rector\Rector\v12\v0\RemoveTypoScriptParserInUseTraitRector::class,
            // ... more rules
        ],
        '12.4' => [
            \Ssch\TYPO3Rector\Rector\v12\v4\MigrateRequiredFlagForTcaColumnsRector::class,
            // ... more rules  
        ],
        '13.0' => [
            \Ssch\TYPO3Rector\Rector\v13\v0\RemoveInitTemplateMethodRector::class,
            // ... more rules
        ],
    ];

    public function getRulesForVersionUpgrade(Version $fromVersion, Version $toVersion): array;
    public function getRulesByCategory(string $category): array;
    public function getAllAvailableRules(): array;
    public function getRuleDescription(string $ruleClass): string;
    public function getRuleSeverity(string $ruleClass): RectorRuleSeverity;
    
    private function loadRulesFromReflection(): array;
    private function categorizeRules(array $rules): array;
    private function filterRulesByVersionRange(array $rules, Version $from, Version $to): array;
}
```

#### Result Processing
```php
// src/Infrastructure/Analyzer/Rector/RectorResultParser.php
class RectorResultParser
{
    public function parseRectorOutput(string $jsonOutput): array;
    public function aggregateFindings(array $findings): RectorAnalysisSummary;
    public function categorizeFindings(array $findings): array;
    public function calculateComplexityScore(array $findings): float;

    private function parseJsonReport(string $json): array;
    private function createFindingFromData(array $data): RectorFinding;
    private function extractMetricsFromFindings(array $findings): array;
    private function groupFindingsByRule(array $findings): array;
    private function groupFindingsByFile(array $findings): array;
}

// src/Infrastructure/Analyzer/Rector/RectorFinding.php
class RectorFinding
{
    public function __construct(
        private readonly string $file,
        private readonly int $line,
        private readonly string $ruleClass,
        private readonly string $message,
        private readonly RectorRuleSeverity $severity,
        private readonly RectorChangeType $changeType,
        private readonly ?string $suggestedFix = null,
        private readonly array $context = []
    );

    public function getFile(): string;
    public function getLine(): int;
    public function getRuleClass(): string;
    public function getMessage(): string;
    public function getSeverity(): RectorRuleSeverity;
    public function getChangeType(): RectorChangeType;
    public function getSuggestedFix(): ?string;
    public function getContext(): array;
    
    public function isBreakingChange(): bool;
    public function isDeprecation(): bool;
    public function isImprovement(): bool;
    public function getEstimatedEffort(): int; // Minutes to fix
}
```

### Value Objects and Enums

```php
// src/Infrastructure/Analyzer/Rector/RectorRuleSeverity.php
enum RectorRuleSeverity: string
{
    case CRITICAL = 'critical';    // Breaking changes
    case WARNING = 'warning';      // Deprecations
    case INFO = 'info';           // Improvements
    case SUGGESTION = 'suggestion'; // Optional optimizations
    
    public function getRiskWeight(): float;
    public function getDisplayName(): string;
    public function getDescription(): string;
}

// src/Infrastructure/Analyzer/Rector/RectorChangeType.php
enum RectorChangeType: string
{
    case BREAKING_CHANGE = 'breaking_change';
    case DEPRECATION = 'deprecation';
    case METHOD_SIGNATURE = 'method_signature';
    case CLASS_REMOVAL = 'class_removal';
    case CONFIGURATION_CHANGE = 'configuration_change';
    case BEST_PRACTICE = 'best_practice';
    case PERFORMANCE = 'performance';
    case SECURITY = 'security';

    public function getCategory(): string;
    public function getDisplayName(): string;
    public function getEstimatedEffort(): int;
    public function requiresManualIntervention(): bool;
}

// src/Infrastructure/Analyzer/Rector/RectorAnalysisSummary.php
class RectorAnalysisSummary
{
    public function __construct(
        private readonly int $totalFindings,
        private readonly int $criticalIssues,
        private readonly int $warnings,
        private readonly int $suggestions,
        private readonly int $affectedFiles,
        private readonly array $ruleBreakdown,
        private readonly array $fileBreakdown,
        private readonly float $complexityScore,
        private readonly int $estimatedFixTime
    );

    public function getTotalFindings(): int;
    public function getCriticalIssues(): int;
    public function getWarnings(): int;
    public function getSuggestions(): int;
    public function getAffectedFiles(): int;
    public function getRuleBreakdown(): array; // Rule -> count mapping
    public function getFileBreakdown(): array; // File -> count mapping
    public function getComplexityScore(): float;
    public function getEstimatedFixTime(): int; // In minutes
    
    public function hasBreakingChanges(): bool;
    public function hasDeprecations(): bool;
    public function getTopIssuesByFile(int $limit = 10): array;
    public function getTopIssuesByRule(int $limit = 10): array;
}
```

## Configuration and Extension Points

### Service Configuration
```yaml
# config/services.yaml
services:
  # Rector Analyzer
  CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Typo3RectorAnalyzer:
    public: true
    arguments:
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService'
      - '@logger'
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorExecutor'
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorConfigGenerator'
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorResultParser'
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleRegistry'
    tags: ['analyzer']

  # Rector Services
  CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorExecutor:
    arguments:
      - '%rector.binary_path%'
      - '@logger'
      - '%rector.timeout_seconds%'

  CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorConfigGenerator:
    arguments:
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleRegistry'
      - '%rector.temp_directory%'

  CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorResultParser: ~

  CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleRegistry: ~

parameters:
  rector.binary_path: '%kernel.project_dir%/vendor/bin/rector'
  rector.timeout_seconds: 300
  rector.temp_directory: '%kernel.project_dir%/var/rector-configs'
```

### Analyzer Configuration
```yaml
# Example analyzer configuration
analyzers:
  typo3_rector:
    enabled: true
    cache_ttl: 3600  # 1 hour cache
    options:
      php_version: '8.1'
      parallel_processes: 4
      memory_limit: '1G'
      excluded_paths:
        - 'vendor/'
        - 'var/'
        - 'public/'
      custom_rules: []
      rule_categories:
        - 'breaking_changes'
        - 'deprecations'
        - 'best_practices'
      severity_threshold: 'warning'  # Only report warnings and above
```

## Analysis Results Structure

### Metrics Output
The analyzer will provide comprehensive metrics in the AnalysisResult:

```php
// Example metrics structure
$metrics = [
    'rector_version' => '1.0.0',
    'execution_time' => 45.2, // seconds
    'total_files_analyzed' => 156,
    'total_findings' => 78,
    'findings_by_severity' => [
        'critical' => 12,   // Breaking changes
        'warning' => 34,    // Deprecations  
        'info' => 28,       // Improvements
        'suggestion' => 4   // Optional optimizations
    ],
    'findings_by_type' => [
        'breaking_change' => 12,
        'deprecation' => 34,
        'method_signature' => 8,
        'class_removal' => 4,
        'configuration_change' => 6,
        'best_practice' => 10,
        'performance' => 3,
        'security' => 1
    ],
    'top_affected_files' => [
        'Classes/Controller/MyController.php' => 15,
        'Classes/Domain/Model/MyModel.php' => 8,
        // ... up to 10 files
    ],
    'top_rules_triggered' => [
        'SubstituteGeneralUtilityMakeInstanceCallsRector' => 12,
        'RemoveTypoScriptParserInUseTraitRector' => 8,
        // ... up to 10 rules
    ],
    'estimated_fix_time' => 480, // minutes
    'complexity_score' => 6.7, // 1-10 scale
    'has_breaking_changes' => true,
    'has_deprecations' => true,
    'upgrade_readiness_score' => 3.2 // 1-10, higher is more ready
];
```

### Risk Scoring Algorithm
```php
private function calculateRiskScore(RectorAnalysisSummary $summary): float
{
    $baseRisk = 1.0;
    
    // Breaking changes contribute heavily to risk
    $baseRisk += $summary->getCriticalIssues() * 0.8;
    
    // Deprecations contribute moderately
    $baseRisk += $summary->getWarnings() * 0.3;
    
    // File coverage impact
    $fileImpactRatio = $summary->getAffectedFiles() / max($summary->getTotalFiles(), 1);
    $baseRisk += $fileImpactRatio * 2.0;
    
    // Complexity multiplier
    $baseRisk *= (1 + $summary->getComplexityScore() / 10);
    
    // Estimated effort factor
    $effortHours = $summary->getEstimatedFixTime() / 60;
    if ($effortHours > 8) {
        $baseRisk += 1.0;
    } elseif ($effortHours > 4) {
        $baseRisk += 0.5;
    }
    
    return min($baseRisk, 10.0);
}
```

### Recommendation Generation
```php
private function generateRecommendations(RectorAnalysisSummary $summary, AnalysisContext $context): array
{
    $recommendations = [];
    
    if ($summary->hasBreakingChanges()) {
        $recommendations[] = 'Critical: Extension contains breaking changes that must be fixed before upgrade to TYPO3 ' . $context->getTargetVersion()->toString();
        $recommendations[] = 'Review and fix ' . $summary->getCriticalIssues() . ' critical issues identified by Rector analysis';
    }
    
    if ($summary->hasDeprecations()) {
        $recommendations[] = 'Update deprecated code patterns (' . $summary->getWarnings() . ' found) to prevent issues in future TYPO3 versions';
    }
    
    if ($summary->getEstimatedFixTime() > 480) { // 8 hours
        $recommendations[] = 'Large refactoring effort required (~' . round($summary->getEstimatedFixTime() / 60, 1) . ' hours). Consider staged implementation';
    }
    
    if ($summary->getComplexityScore() > 7.0) {
        $recommendations[] = 'High complexity changes detected. Review Rector suggestions carefully and test thoroughly';
    }
    
    // Add specific rule-based recommendations
    $topRules = $summary->getTopIssuesByRule(3);
    foreach ($topRules as $rule => $count) {
        $ruleInfo = $this->ruleRegistry->getRuleDescription($rule);
        $recommendations[] = "Focus on {$ruleInfo} ({$count} occurrences)";
    }
    
    return $recommendations;
}
```

## Template Integration

### Report Templates Enhancement

The analyzer results will be integrated into the existing report templates:

#### HTML Template Addition
```twig
{# resources/templates/main-report.html.twig - Add to analyzer sections #}
<div class="section">
    <h2>TYPO3 Rector Analysis</h2>
    
    {% if analyzer_results.typo3_rector %}
        {% set rector_data = analyzer_results.typo3_rector %}
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number">{{ rector_data.total_findings }}</div>
                <div class="stat-label">Total Issues</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">{{ rector_data.findings_by_severity.critical }}</div>
                <div class="stat-label">Breaking Changes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">{{ rector_data.findings_by_severity.warning }}</div>
                <div class="stat-label">Deprecations</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">{{ (rector_data.estimated_fix_time / 60)|round(1) }}</div>
                <div class="stat-label">Est. Hours</div>
            </div>
        </div>

        <h3>Issue Breakdown</h3>
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Count</th>
                    <th>Severity</th>
                    <th>Impact</th>
                </tr>
            </thead>
            <tbody>
                {% for type, count in rector_data.findings_by_type %}
                    <tr>
                        <td>{{ type|replace('_', ' ')|title }}</td>
                        <td>{{ count }}</td>
                        <td><span class="badge badge-{{ type == 'breaking_change' ? 'critical' : (type == 'deprecation' ? 'warning' : 'info') }}">
                            {{ type == 'breaking_change' ? 'Critical' : (type == 'deprecation' ? 'Warning' : 'Info') }}
                        </span></td>
                        <td>{{ type == 'breaking_change' ? 'Must Fix' : (type == 'deprecation' ? 'Should Fix' : 'Optional') }}</td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    {% else %}
        <div class="error-message">
            ❌ TYPO3 Rector analysis was not performed for this extension.
        </div>
    {% endif %}
</div>
```

#### Markdown Template Addition
```markdown
## TYPO3 Rector Analysis

{% if analyzer_results.typo3_rector %}
{% set rector_data = analyzer_results.typo3_rector %}

### Analysis Summary
- **Total Issues Found:** {{ rector_data.total_findings }}
- **Breaking Changes:** {{ rector_data.findings_by_severity.critical }}
- **Deprecations:** {{ rector_data.findings_by_severity.warning }}
- **Files Affected:** {{ rector_data.total_files_analyzed }}
- **Estimated Fix Time:** {{ (rector_data.estimated_fix_time / 60)|round(1) }} hours
- **Complexity Score:** {{ rector_data.complexity_score }}/10.0

### Issue Categories
{% for type, count in rector_data.findings_by_type %}
- **{{ type|replace('_', ' ')|title }}:** {{ count }} issues
{% endfor %}

### Top Affected Files
{% for file, count in rector_data.top_affected_files %}
- `{{ file }}`: {{ count }} issues
{% endfor %}

### Recommendations
{% for recommendation in rector_data.recommendations %}
- {{ recommendation }}
{% endfor %}

{% else %}
❌ **TYPO3 Rector analysis was not performed for this extension.**
{% endif %}
```

## Testing Strategy

### Unit Tests
```php
// tests/Unit/Infrastructure/Analyzer/Typo3RectorAnalyzerTest.php
class Typo3RectorAnalyzerTest extends TestCase
{
    use MockeryTrait;

    private Typo3RectorAnalyzer $analyzer;
    private MockInterface $rectorExecutor;
    private MockInterface $configGenerator;
    private MockInterface $resultParser;

    protected function setUp(): void
    {
        $this->rectorExecutor = Mockery::mock(RectorExecutor::class);
        $this->configGenerator = Mockery::mock(RectorConfigGenerator::class);
        $this->resultParser = Mockery::mock(RectorResultParser::class);
        
        $this->analyzer = new Typo3RectorAnalyzer(
            Mockery::mock(CacheService::class),
            Mockery::mock(LoggerInterface::class),
            $this->rectorExecutor,
            $this->configGenerator,
            $this->resultParser,
            Mockery::mock(RectorRuleRegistry::class)
        );
    }

    public function testSupportsAllExtensionTypes(): void
    {
        $extension = $this->createMockExtension();
        
        $this->assertTrue($this->analyzer->supports($extension));
    }

    public function testRequiredToolsDetection(): void
    {
        $this->assertEquals(['php', 'rector'], $this->analyzer->getRequiredTools());
    }

    public function testAnalysisWithSuccessfulRectorExecution(): void
    {
        // Test successful rector execution and result parsing
        $extension = $this->createMockExtension();
        $context = $this->createMockAnalysisContext();
        
        $this->configGenerator->shouldReceive('generateConfig')
            ->with($extension, $context)
            ->andReturn('/tmp/rector.php');
            
        $this->rectorExecutor->shouldReceive('execute')
            ->andReturn(new RectorExecutionResult(true, [], [], 1.5, 0, ''));
            
        $this->resultParser->shouldReceive('aggregateFindings')
            ->andReturn(new RectorAnalysisSummary(0, 0, 0, 0, 0, [], [], 0.0, 0));

        $result = $this->analyzer->analyze($extension, $context);
        
        $this->assertInstanceOf(AnalysisResult::class, $result);
        $this->assertEquals('typo3_rector', $result->getAnalyzerName());
    }

    public function testAnalysisWithRectorFailure(): void
    {
        // Test handling of rector execution failures
        $extension = $this->createMockExtension();
        $context = $this->createMockAnalysisContext();
        
        $this->configGenerator->shouldReceive('generateConfig')
            ->with($extension, $context)
            ->andReturn('/tmp/rector.php');
            
        $this->rectorExecutor->shouldReceive('execute')
            ->andReturn(new RectorExecutionResult(false, [], ['Error'], 0.0, 1, ''));

        $this->expectException(AnalyzerException::class);
        $this->analyzer->analyze($extension, $context);
    }
}

// tests/Unit/Infrastructure/Analyzer/Rector/RectorExecutorTest.php
class RectorExecutorTest extends TestCase
{
    public function testSuccessfulExecution(): void
    {
        $executor = new RectorExecutor(
            '/usr/bin/rector',
            Mockery::mock(LoggerInterface::class)
        );

        // Mock successful execution
        $result = $this->mockProcessExecution($executor, 0, $this->getSampleRectorOutput());
        
        $this->assertTrue($result->isSuccessful());
        $this->assertCount(3, $result->getFindings());
        $this->assertEquals(0, $result->getExitCode());
    }

    public function testFailedExecution(): void
    {
        $executor = new RectorExecutor(
            '/nonexistent/rector',
            Mockery::mock(LoggerInterface::class)
        );

        $this->expectException(AnalyzerException::class);
        $executor->execute('/tmp/config.php', '/tmp/extension');
    }
}
```

### Integration Tests
```php
// tests/Integration/Analyzer/Typo3RectorAnalyzerIntegrationTest.php
class Typo3RectorAnalyzerIntegrationTest extends AbstractIntegrationTest
{
    private Typo3RectorAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->requiresTool('rector');
        $this->analyzer = $this->createAnalyzer();
    }

    public function testAnalyzeExtensionWithDeprecations(): void
    {
        $extension = $this->createTestExtensionWithDeprecatedCode();
        $context = $this->createTestAnalysisContext('12.4.0');

        $result = $this->analyzer->analyze($extension, $context);

        $this->assertInstanceOf(AnalysisResult::class, $result);
        $this->assertGreaterThan(0, $result->getMetric('total_findings'));
        $this->assertGreaterThan(0, $result->getMetric('findings_by_severity')['warning']);
        $this->assertNotEmpty($result->getRecommendations());
    }

    public function testAnalyzeCleanExtension(): void
    {
        $extension = $this->createTestExtensionWithCleanCode();
        $context = $this->createTestAnalysisContext('12.4.0');

        $result = $this->analyzer->analyze($extension, $context);

        $this->assertEquals(0, $result->getMetric('total_findings'));
        $this->assertLessThan(3.0, $result->getRiskScore());
    }

    private function createTestExtensionWithDeprecatedCode(): Extension
    {
        // Create test extension with known deprecated patterns
        $extensionPath = $this->createTemporaryExtension([
            'Classes/Controller/TestController.php' => '<?php
                namespace MyVendor\\MyExt\\Controller;
                
                class TestController {
                    public function action() {
                        // Deprecated GeneralUtility usage
                        $instance = \\TYPO3\\CMS\\Core\\Utility\\GeneralUtility::makeInstance(\\Some\\Class::class);
                    }
                }'
        ]);

        return $this->createTestExtension('test_deprecated', null, false, false, $extensionPath);
    }
}
```

### Test Fixtures
```php
// tests/Fixtures/RectorTestFixtures.php
class RectorTestFixtures
{
    public static function getDeprecatedCodeSamples(): array
    {
        return [
            'deprecated_makeinstance' => [
                'code' => 'GeneralUtility::makeInstance(MyClass::class)',
                'expected_rule' => 'SubstituteGeneralUtilityMakeInstanceCallsRector',
                'severity' => 'warning'
            ],
            'deprecated_localization' => [
                'code' => '$GLOBALS[\'TSFE\']->sL($key)',
                'expected_rule' => 'SubstituteLocalizationServiceRector', 
                'severity' => 'warning'
            ],
            'breaking_interface' => [
                'code' => 'class MyClass implements OldInterface',
                'expected_rule' => 'RemoveObsoleteInterfaceRector',
                'severity' => 'critical'
            ]
        ];
    }

    public static function getSampleRectorOutput(): string
    {
        return json_encode([
            'totals' => [
                'changed_files' => 3,
                'applied_rectors' => 8
            ],
            'changed_files' => [
                [
                    'file' => 'Classes/Controller/TestController.php',
                    'applied_rectors' => [
                        [
                            'class' => 'Ssch\\TYPO3Rector\\Rector\\v11\\v5\\SubstituteGeneralUtilityMakeInstanceCallsRector',
                            'line' => 15,
                            'message' => 'Substitute GeneralUtility::makeInstance() calls',
                            'old' => 'GeneralUtility::makeInstance(MyClass::class)',
                            'new' => 'new MyClass()'
                        ]
                    ]
                ]
            ]
        ]);
    }
}
```

## Performance and Scalability

### Optimization Strategies
1. **Rector Execution Optimization**
   - Parallel processing for multiple extensions
   - Selective rule execution based on version diff
   - Incremental analysis with change detection
   - Memory-optimized configuration generation

2. **Caching Strategy**
   - Cache Rector configurations between similar runs
   - Cache rule registry and metadata
   - Version-aware cache invalidation
   - Result caching with file modification tracking

3. **Resource Management**
   - Configurable timeout and memory limits
   - Process isolation for large extensions
   - Cleanup of temporary files and configurations
   - Progress reporting for long-running analysis

### Scalability Considerations
- Support for analyzing extensions with thousands of files
- Efficient handling of monorepo structures
- Batch analysis of multiple extensions
- Configurable analysis depth and scope
- Resource usage monitoring and limits

## Error Handling and Recovery

### Common Failure Scenarios
1. **Rector Binary Not Found**: Clear error message with installation instructions
2. **Out of Memory**: Automatic retry with increased memory limit
3. **Timeout**: Configurable timeout with progress indication
4. **Invalid PHP Code**: Graceful handling of parse errors
5. **Rule Configuration Errors**: Validation and fallback to default rules

### Recovery Strategies
- Fallback to subset of rules if full analysis fails
- Partial results reporting when some files fail
- Detailed error logging with context information
- User guidance for resolving common issues

## Integration with CI/CD

### GitHub Actions Integration
```yaml
# .github/workflows/typo3-rector-analysis.yml
name: TYPO3 Rector Analysis

on:
  pull_request:
    paths: ['**.php']

jobs:
  rector-analysis:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
      - name: Install dependencies
        run: composer install
      - name: Run TYPO3 Rector Analysis
        run: |
          ./bin/typo3-analyzer analyze ./extensions/my_extension \
            --analyzers=typo3_rector \
            --format=json \
            --output=rector-results.json
      - name: Comment PR with Results
        uses: actions/github-script@v6
        with:
          script: |
            const fs = require('fs');
            const results = JSON.parse(fs.readFileSync('rector-results.json'));
            // Process and comment results...
```

## Success Criteria

### Functional Requirements
- ✅ Detects deprecated code patterns in TYPO3 extensions
- ✅ Provides accurate upgrade guidance for version transitions
- ✅ Integrates seamlessly with existing analyzer framework
- ✅ Generates actionable recommendations for developers
- ✅ Handles extensions of varying sizes efficiently

### Performance Requirements  
- ✅ Analyzes typical extensions (50-200 files) in under 60 seconds
- ✅ Memory usage remains under 512MB for large extensions
- ✅ Supports parallel analysis of multiple extensions
- ✅ Cache hit rate of 80%+ for repeated analyses

### Quality Requirements
- ✅ 95%+ test coverage for all analyzer components
- ✅ Integration tests with real TYPO3 extensions
- ✅ Comprehensive error handling and recovery
- ✅ Clear documentation and usage examples

### Business Requirements
- ✅ Reduces manual code review time by 70%+
- ✅ Improves upgrade success rate through early issue detection  
- ✅ Provides quantifiable upgrade effort estimates
- ✅ Supports TYPO3 versions 11.5+ through 14.x+

## Future Enhancements

### Phase 2 Improvements
- **Custom Rule Development**: Framework for project-specific Rector rules
- **Interactive Fix Application**: Integration with IDE for automated fixes
- **Trend Analysis**: Historical tracking of code quality metrics
- **Team Collaboration**: Shared analysis results and assignment workflows

### Advanced Features
- **Machine Learning Integration**: Pattern recognition for custom deprecation detection
- **Performance Impact Analysis**: Estimate performance improvements from suggested changes
- **Security Vulnerability Detection**: Integration with security-focused Rector rules
- **Code Quality Scoring**: Comprehensive quality metrics beyond just upgrade compatibility
