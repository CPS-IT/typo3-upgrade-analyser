# Git Repository Version Support - Feature Plan

## Feature Overview

This feature extends the existing VersionAvailabilityAnalyzer to support Git repository-based TYPO3 extensions. Composer can install extensions directly from Git repositories, and this enhancement enables the analyzer to detect, validate, and assess Git-sourced extensions for TYPO3 version compatibility.

### Business Value
- **Complete Extension Coverage**: Support all three major TYPO3 extension sources (TER, Packagist, Git)
- **Modern Development Workflow Support**: Analyze extensions using Git-based development practices
- **Enhanced Risk Assessment**: More accurate upgrade risk calculation including Git repository health
- **Developer Productivity**: Better guidance for teams using custom/private Git repositories

## Technical Requirements

### Core Functionality
1. **Git Repository Detection**
   - Identify extensions installed from Git repositories via composer.json
   - Extract Git repository URLs and metadata
   - Support various Git hosting providers (GitHub, GitLab, Bitbucket, self-hosted)

2. **Git Repository Analysis**
   - Query Git hosting APIs for repository information
   - Check for tags compatible with target TYPO3 version
   - Analyze branch activity and maintenance status
   - Detect repository accessibility issues

3. **Version Compatibility Assessment**
   - Parse Git tags for semantic versioning patterns
   - Check composer.json in Git repository for TYPO3 version constraints
   - Analyze commit history for TYPO3 compatibility indicators
   - Handle development branches and pre-release tags

4. **Integration with Existing Risk Assessment**
   - Include Git repository availability in overall risk scoring
   - Provide Git-specific recommendations
   - Maintain backward compatibility with existing TER/Packagist checks

## Implementation Strategy

### Phase 1: Foundation (Week 1)

#### Task 1.1: Git Repository Information Extraction
**Priority**: 🔥 **CRITICAL PATH**
- **File**: `src/Infrastructure/ExternalTool/GitRepositoryAnalyzer.php`
- **Dependencies**: Extension entity with Git metadata
- **Functionality**:
  - Parse composer.json to extract Git repository URLs
  - Normalize repository URLs across different Git providers
  - Extract repository metadata (owner, name, provider)

#### Task 1.2: Git Provider Abstraction
**Priority**: 🔥 **HIGH**
- **File**: `src/Infrastructure/ExternalTool/GitProvider/GitProviderInterface.php`
- **Dependencies**: Task 1.1
- **Functionality**:
  - Define common interface for Git provider clients
  - Support multiple authentication methods (token, SSH, public)
  - Handle provider-specific URL patterns and API endpoints

#### Task 1.3: GitHub API Client Implementation
**Priority**: 🔥 **HIGH**
- **File**: `src/Infrastructure/ExternalTool/GitProvider/GitHubClient.php`
- **Dependencies**: Task 1.2, Guzzle HTTP client
- **Functionality**:
  - Implement GitHub API v4 (GraphQL) for efficient queries
  - Query repository tags, branches, and metadata
  - Handle GitHub API rate limiting and authentication
  - Parse composer.json from repository for TYPO3 constraints

### Phase 2: Provider Support Expansion (Week 2)

#### Task 2.1: GitLab API Client Implementation
**Priority**: 🔥 **MEDIUM**
- **File**: `src/Infrastructure/ExternalTool/GitProvider/GitLabClient.php`
- **Dependencies**: Task 1.2
- **Functionality**:
  - Support both GitLab.com and self-hosted GitLab instances
  - Implement GitLab API v4 for repository analysis
  - Handle GitLab-specific authentication and rate limits

#### Task 2.2: Bitbucket API Client Implementation
**Priority**: 🔥 **MEDIUM**
- **File**: `src/Infrastructure/ExternalTool/GitProvider/BitbucketClient.php`
- **Dependencies**: Task 1.2
- **Functionality**:
  - Support Bitbucket Cloud API 2.0
  - Handle Bitbucket's different tag/branch structure
  - Implement OAuth2 authentication flow

#### Task 2.3: Generic Git Client (Fallback)
**Priority**: 🔥 **LOW**
- **File**: `src/Infrastructure/ExternalTool/GitProvider/GenericGitClient.php`
- **Dependencies**: Task 1.2
- **Functionality**:
  - Fallback for unknown or custom Git hosting providers
  - Use basic Git commands for repository analysis
  - Limited functionality but provides basic compatibility checking

### Phase 3: Version Compatibility Analysis (Week 3)

#### Task 3.1: Git Version Parser
**Priority**: 🔥 **HIGH**
- **File**: `src/Infrastructure/ExternalTool/GitVersionParser.php`
- **Dependencies**: Phase 1 completion
- **Functionality**:
  - Parse semantic version tags from Git repositories
  - Extract TYPO3 version constraints from composer.json in Git
  - Handle pre-release versions and development branches
  - Validate version compatibility with target TYPO3 version

#### Task 3.2: Repository Health Analyzer
**Priority**: 🔥 **MEDIUM**
- **File**: `src/Infrastructure/ExternalTool/GitRepositoryHealthAnalyzer.php`
- **Dependencies**: Task 3.1
- **Functionality**:
  - Analyze commit frequency and recent activity
  - Check for open issues and maintenance status
  - Assess repository popularity (stars, forks) as quality indicators
  - Detect abandoned or unmaintained repositories

### Phase 4: Integration with VersionAvailabilityAnalyzer (Week 4)

#### Task 4.1: Enhance VersionAvailabilityAnalyzer
**Priority**: 🔥 **CRITICAL PATH**
- **File**: `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php` (modify existing)
- **Dependencies**: All previous phases
- **Functionality**:
  - Add Git repository availability check method
  - Integrate Git analysis results into risk scoring
  - Update recommendations to include Git-specific guidance
  - Maintain backward compatibility with existing TER/Packagist checks

#### Task 4.2: Update Risk Scoring Algorithm
**Priority**: 🔥 **HIGH**
- **File**: `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php` (modify existing)
- **Dependencies**: Task 4.1
- **Functionality**:
  - Include Git repository health in risk calculation
  - Weight different sources appropriately (TER > Packagist > Git)
  - Consider repository maintenance status in scoring
  - Handle combinations of availability across all three sources

### Phase 5: Configuration and Error Handling (Week 5)

#### Task 5.1: Configuration Support
**Priority**: 🔥 **MEDIUM**
- **File**: `config/git-providers.yaml` (new)
- **Dependencies**: Phase 2 completion
- **Functionality**:
  - Configure Git provider credentials and endpoints
  - Set rate limiting and timeout parameters
  - Enable/disable specific Git providers
  - Configure caching strategies for Git API responses

#### Task 5.2: Enhanced Error Handling
**Priority**: 🔥 **HIGH**
- **File**: Multiple files (error handling enhancement)
- **Dependencies**: All analyzer components
- **Functionality**:
  - Handle Git provider API failures gracefully
  - Implement exponential backoff for rate-limited requests
  - Provide meaningful error messages for Git analysis failures
  - Cache negative results to avoid repeated failed requests

## Detailed Implementation

### Git Provider Architecture

```php
// src/Infrastructure/ExternalTool/GitProvider/GitProviderInterface.php
interface GitProviderInterface
{
    public function supports(string $repositoryUrl): bool;
    public function getRepositoryInfo(string $repositoryUrl): GitRepositoryInfo;
    public function getTags(string $repositoryUrl): array;
    public function getBranches(string $repositoryUrl): array;
    public function getComposerJson(string $repositoryUrl, string $ref = 'main'): ?array;
    public function getRepositoryHealth(string $repositoryUrl): GitRepositoryHealth;
}

// src/Infrastructure/ExternalTool/GitProvider/GitHubClient.php
class GitHubClient implements GitProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $accessToken = null
    ) {}

    public function supports(string $repositoryUrl): bool
    {
        return str_contains($repositoryUrl, 'github.com');
    }

    public function getRepositoryInfo(string $repositoryUrl): GitRepositoryInfo
    {
        $repoPath = $this->extractRepositoryPath($repositoryUrl);
        
        $query = '
            query($owner: String!, $name: String!) {
                repository(owner: $owner, name: $name) {
                    name
                    description
                    isArchived
                    isFork
                    stargazerCount
                    forkCount
                    updatedAt
                    defaultBranchRef {
                        name
                    }
                }
            }
        ';

        $response = $this->graphqlRequest($query, [
            'owner' => $repoPath['owner'],
            'name' => $repoPath['name']
        ]);

        return GitRepositoryInfo::fromGitHubResponse($response['data']['repository']);
    }

    public function getTags(string $repositoryUrl): array
    {
        $repoPath = $this->extractRepositoryPath($repositoryUrl);
        
        $query = '
            query($owner: String!, $name: String!, $first: Int!) {
                repository(owner: $owner, name: $name) {
                    refs(refPrefix: "refs/tags/", first: $first, orderBy: {field: TAG_COMMIT_DATE, direction: DESC}) {
                        nodes {
                            name
                            target {
                                ... on Tag {
                                    tagger {
                                        date
                                    }
                                }
                                ... on Commit {
                                    committedDate
                                }
                            }
                        }
                    }
                }
            }
        ';

        $response = $this->graphqlRequest($query, [
            'owner' => $repoPath['owner'],
            'name' => $repoPath['name'],
            'first' => 100
        ]);

        return array_map(
            fn($tag) => new GitTag($tag['name'], $tag['target']['committedDate'] ?? null),
            $response['data']['repository']['refs']['nodes']
        );
    }

    private function graphqlRequest(string $query, array $variables = []): array
    {
        $response = $this->httpClient->request('POST', 'https://api.github.com/graphql', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'query' => $query,
                'variables' => $variables
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new GitProviderException('GitHub API request failed: ' . $response->getContent(false));
        }

        $data = $response->toArray();
        
        if (isset($data['errors'])) {
            throw new GitProviderException('GitHub GraphQL errors: ' . json_encode($data['errors']));
        }

        return $data;
    }
}
```

### Enhanced VersionAvailabilityAnalyzer

```php
// src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php (enhanced)
class VersionAvailabilityAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly TerApiClient $terClient,
        private readonly PackagistClient $packagistClient,
        private readonly GitRepositoryAnalyzer $gitAnalyzer, // NEW
        private readonly LoggerInterface $logger
    ) {}

    public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $result = new AnalysisResult($this->getName(), $extension);
        
        try {
            // Existing TER and Packagist checks...
            $terAvailable = $this->checkTerAvailability($extension, $context);
            $result->addMetric('ter_available', $terAvailable);

            if ($extension->hasComposerName()) {
                $packagistAvailable = $this->checkPackagistAvailability($extension, $context);
                $result->addMetric('packagist_available', $packagistAvailable);
            } else {
                $result->addMetric('packagist_available', false);
            }

            // NEW: Git repository check
            $gitInfo = $this->checkGitAvailability($extension, $context);
            $result->addMetric('git_available', $gitInfo['available']);
            $result->addMetric('git_repository_health', $gitInfo['health']);
            $result->addMetric('git_repository_url', $gitInfo['url']);

            // Enhanced risk scoring including Git
            $riskScore = $this->calculateRiskScore($result->getMetrics(), $extension);
            $result->setRiskScore($riskScore);

            // Enhanced recommendations including Git-specific guidance
            $this->addRecommendations($result, $extension);

        } catch (\Throwable $e) {
            // Error handling...
        }

        return $result;
    }

    private function checkGitAvailability(Extension $extension, AnalysisContext $context): array
    {
        try {
            $gitInfo = $this->gitAnalyzer->analyzeExtension($extension, $context->getTargetVersion());
            
            return [
                'available' => $gitInfo->hasCompatibleVersion(),
                'health' => $gitInfo->getHealthScore(),
                'url' => $gitInfo->getRepositoryUrl(),
                'compatible_versions' => $gitInfo->getCompatibleVersions(),
                'latest_tag' => $gitInfo->getLatestTag()
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Git availability check failed', [
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);
            
            return [
                'available' => false,
                'health' => null,
                'url' => null
            ];
        }
    }

    private function calculateRiskScore(array $metrics, Extension $extension): float
    {
        $terAvailable = $metrics['ter_available'] ?? false;
        $packagistAvailable = $metrics['packagist_available'] ?? false;
        $gitAvailable = $metrics['git_available'] ?? false;
        $gitHealth = $metrics['git_repository_health'] ?? null;

        // System extensions are always low risk
        if ($extension->isSystemExtension()) {
            return 1.0;
        }

        // Calculate base availability score
        $availabilityScore = 0;
        if ($terAvailable) $availabilityScore += 4; // TER is most trusted
        if ($packagistAvailable) $availabilityScore += 3; // Packagist is second
        if ($gitAvailable) {
            // Git availability weighted by repository health
            $gitWeight = $gitHealth ? (2 * $gitHealth) : 1;
            $availabilityScore += $gitWeight;
        }

        // Convert to risk score (higher availability = lower risk)
        if ($availabilityScore >= 6) return 1.5; // Multiple high-quality sources
        if ($availabilityScore >= 4) return 2.5; // At least one high-quality source
        if ($availabilityScore >= 2) return 5.0; // Some availability
        return 9.0; // Very limited availability
    }

    private function addRecommendations(AnalysisResult $result, Extension $extension): void
    {
        $terAvailable = $result->getMetric('ter_available');
        $packagistAvailable = $result->getMetric('packagist_available');
        $gitAvailable = $result->getMetric('git_available');
        $gitHealth = $result->getMetric('git_repository_health');
        $gitUrl = $result->getMetric('git_repository_url');

        // No availability anywhere
        if (!$terAvailable && !$packagistAvailable && !$gitAvailable) {
            $result->addRecommendation('Extension not available in any known repository. Consider finding alternative or contacting author.');
            return;
        }

        // Git-specific recommendations
        if ($gitAvailable && !$terAvailable && !$packagistAvailable) {
            if ($gitHealth && $gitHealth > 0.7) {
                $result->addRecommendation('Extension only available via Git repository. Repository appears well-maintained.');
            } else {
                $result->addRecommendation('Extension only available via Git repository. Consider repository maintenance status before upgrade.');
            }
            
            if ($gitUrl) {
                $result->addRecommendation("Git repository: {$gitUrl}");
            }
        }

        // Mixed availability recommendations
        if ($gitAvailable && ($terAvailable || $packagistAvailable)) {
            $result->addRecommendation('Extension available in multiple sources. Consider using most stable source for production.');
        }

        // Git repository health warnings
        if ($gitAvailable && $gitHealth && $gitHealth < 0.3) {
            $result->addRecommendation('Git repository shows signs of poor maintenance. Consider alternative sources or extensions.');
        }
    }
}
```

## Testing Strategy

### Unit Testing

#### Task T1: Git Provider Testing
```php
// tests/Unit/Infrastructure/ExternalTool/GitProvider/GitHubClientTest.php
class GitHubClientTest extends TestCase
{
    private GitHubClient $client;
    private MockHandler $mockHandler;

    protected function setUp(): void
    {
        $this->mockHandler = new MockHandler();
        $httpClient = new HttpClient(['handler' => HandlerStack::create($this->mockHandler)]);
        $this->client = new GitHubClient($httpClient, $this->createMock(LoggerInterface::class), 'test-token');
    }

    public function testSupportsGitHubUrls(): void
    {
        $this->assertTrue($this->client->supports('https://github.com/user/repo.git'));
        $this->assertTrue($this->client->supports('git@github.com:user/repo.git'));
        $this->assertFalse($this->client->supports('https://gitlab.com/user/repo.git'));
    }

    public function testGetRepositoryInfo(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'data' => [
                'repository' => [
                    'name' => 'test-extension',
                    'description' => 'Test TYPO3 extension',
                    'isArchived' => false,
                    'stargazerCount' => 42,
                    'updatedAt' => '2024-01-15T10:00:00Z'
                ]
            ]
        ])));

        $info = $this->client->getRepositoryInfo('https://github.com/user/test-extension.git');

        $this->assertEquals('test-extension', $info->getName());
        $this->assertEquals('Test TYPO3 extension', $info->getDescription());
        $this->assertFalse($info->isArchived());
        $this->assertEquals(42, $info->getStarCount());
    }

    public function testGetTagsReturnsVersionSortedTags(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'data' => [
                'repository' => [
                    'refs' => [
                        'nodes' => [
                            ['name' => 'v2.1.0', 'target' => ['committedDate' => '2024-01-15T10:00:00Z']],
                            ['name' => 'v2.0.0', 'target' => ['committedDate' => '2023-12-01T10:00:00Z']],
                            ['name' => 'v1.5.0', 'target' => ['committedDate' => '2023-10-01T10:00:00Z']]
                        ]
                    ]
                ]
            ]
        ])));

        $tags = $this->client->getTags('https://github.com/user/test-extension.git');

        $this->assertCount(3, $tags);
        $this->assertEquals('v2.1.0', $tags[0]->getName());
        $this->assertEquals('v2.0.0', $tags[1]->getName());
    }

    public function testHandlesApiErrors(): void
    {
        $this->mockHandler->append(new Response(401, [], 'Unauthorized'));

        $this->expectException(GitProviderException::class);
        $this->expectExceptionMessage('GitHub API request failed');

        $this->client->getRepositoryInfo('https://github.com/user/test-extension.git');
    }

    public function testHandlesRateLimiting(): void
    {
        // Test rate limiting handling with appropriate delays and retries
    }
}

// tests/Unit/Infrastructure/ExternalTool/GitVersionParserTest.php
class GitVersionParserTest extends TestCase
{
    private GitVersionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GitVersionParser();
    }

    public function testParsesSemanticVersionTags(): void
    {
        $tags = [
            new GitTag('v12.4.0', '2024-01-15T10:00:00Z'),
            new GitTag('v11.5.10', '2023-12-01T10:00:00Z'),
            new GitTag('v11.5.0', '2023-10-01T10:00:00Z')
        ];

        $compatibleVersions = $this->parser->findCompatibleVersions($tags, new Version('12.4'));

        $this->assertCount(1, $compatibleVersions);
        $this->assertEquals('v12.4.0', $compatibleVersions[0]->getName());
    }

    public function testHandlesNonSemanticTags(): void
    {
        $tags = [
            new GitTag('release-20240115', '2024-01-15T10:00:00Z'),
            new GitTag('main', '2024-01-10T10:00:00Z')
        ];

        $compatibleVersions = $this->parser->findCompatibleVersions($tags, new Version('12.4'));

        // Should fall back to composer.json analysis
        $this->assertEmpty($compatibleVersions);
    }

    public function testAnalyzesComposerConstraints(): void
    {
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '^12.4'
            ]
        ];

        $isCompatible = $this->parser->isComposerCompatible($composerJson, new Version('12.4'));

        $this->assertTrue($isCompatible);
    }
}
```

#### Task T2: Enhanced VersionAvailabilityAnalyzer Testing
```php
// tests/Unit/Infrastructure/Analyzer/VersionAvailabilityAnalyzerTest.php (enhanced)
class VersionAvailabilityAnalyzerTest extends TestCase
{
    private VersionAvailabilityAnalyzer $analyzer;
    private MockObject $terClient;
    private MockObject $packagistClient;
    private MockObject $gitAnalyzer;

    protected function setUp(): void
    {
        $this->terClient = $this->createMock(TerApiClient::class);
        $this->packagistClient = $this->createMock(PackagistClient::class);
        $this->gitAnalyzer = $this->createMock(GitRepositoryAnalyzer::class);

        $this->analyzer = new VersionAvailabilityAnalyzer(
            $this->terClient,
            $this->packagistClient,
            $this->gitAnalyzer,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testAnalyzesGitOnlyExtension(): void
    {
        $extension = $this->createExtensionWithGitRepository();
        $context = new AnalysisContext(new Version('11.5'), new Version('12.4'));

        // Mock TER and Packagist as unavailable
        $this->terClient->method('hasVersionFor')->willReturn(false);
        $this->packagistClient->method('hasVersionFor')->willReturn(false);

        // Mock Git as available with good health
        $gitInfo = $this->createMock(GitRepositoryInfo::class);
        $gitInfo->method('hasCompatibleVersion')->willReturn(true);
        $gitInfo->method('getHealthScore')->willReturn(0.8);
        $gitInfo->method('getRepositoryUrl')->willReturn('https://github.com/user/extension.git');

        $this->gitAnalyzer->method('analyzeExtension')->willReturn($gitInfo);

        $result = $this->analyzer->analyze($extension, $context);

        $this->assertTrue($result->getMetric('git_available'));
        $this->assertEquals(0.8, $result->getMetric('git_repository_health'));
        $this->assertLessThan(6.0, $result->getRiskScore()); // Should be moderate risk
    }

    public function testCalculatesRiskWithMultipleSources(): void
    {
        $extension = $this->createExtensionWithAllSources();
        $context = new AnalysisContext(new Version('11.5'), new Version('12.4'));

        // All sources available
        $this->terClient->method('hasVersionFor')->willReturn(true);
        $this->packagistClient->method('hasVersionFor')->willReturn(true);

        $gitInfo = $this->createMock(GitRepositoryInfo::class);
        $gitInfo->method('hasCompatibleVersion')->willReturn(true);
        $gitInfo->method('getHealthScore')->willReturn(0.9);
        $this->gitAnalyzer->method('analyzeExtension')->willReturn($gitInfo);

        $result = $this->analyzer->analyze($extension, $context);

        $this->assertTrue($result->getMetric('ter_available'));
        $this->assertTrue($result->getMetric('packagist_available'));
        $this->assertTrue($result->getMetric('git_available'));
        $this->assertLessThan(2.0, $result->getRiskScore()); // Should be very low risk
    }

    public function testHandlesGitAnalysisFailure(): void
    {
        $extension = $this->createExtensionWithGitRepository();
        $context = new AnalysisContext(new Version('11.5'), new Version('12.4'));

        $this->gitAnalyzer->method('analyzeExtension')
            ->willThrowException(new GitProviderException('API rate limit exceeded'));

        $result = $this->analyzer->analyze($extension, $context);

        $this->assertFalse($result->getMetric('git_available'));
        $this->assertNull($result->getMetric('git_repository_health'));
        // Should still complete analysis without Git data
        $this->assertInstanceOf(AnalysisResult::class, $result);
    }

    private function createExtensionWithGitRepository(): Extension
    {
        // Create extension with Git repository metadata
    }
}
```

### Integration Testing

#### Task T3: End-to-End Git Analysis Testing
```php
// tests/Integration/GitRepositoryAnalysisIntegrationTest.php
class GitRepositoryAnalysisIntegrationTest extends TestCase
{
    public function testAnalyzesRealGitHubRepository(): void
    {
        if (!$this->hasGitHubToken()) {
            $this->markTestSkipped('GitHub token not available for integration testing');
        }

        $extension = $this->createTestExtensionFromGit('https://github.com/TYPO3-Console/TYPO3-Console.git');
        $analyzer = $this->getContainer()->get(VersionAvailabilityAnalyzer::class);
        $context = new AnalysisContext(new Version('11.5'), new Version('12.4'));

        $result = $analyzer->analyze($extension, $context);

        $this->assertInstanceOf(AnalysisResult::class, $result);
        $this->assertIsBool($result->getMetric('git_available'));
        
        if ($result->getMetric('git_available')) {
            $this->assertIsFloat($result->getMetric('git_repository_health'));
            $this->assertIsString($result->getMetric('git_repository_url'));
        }
    }

    public function testHandlesPrivateRepository(): void
    {
        // Test with private repository (should fail gracefully)
    }

    public function testHandlesNonExistentRepository(): void
    {
        // Test with non-existent repository URL
    }
}
```

## Configuration Integration

### Task C1: Git Provider Configuration
```yaml
# config/git-providers.yaml
git_providers:
  github:
    enabled: true
    api_url: 'https://api.github.com'
    access_token: '%env(GITHUB_ACCESS_TOKEN)%'
    rate_limit:
      requests_per_hour: 5000
      burst_limit: 100
    timeout: 30
    
  gitlab:
    enabled: true
    api_url: 'https://gitlab.com/api/v4'
    access_token: '%env(GITLAB_ACCESS_TOKEN)%'
    rate_limit:
      requests_per_hour: 2000
      burst_limit: 50
    timeout: 30
    
  bitbucket:
    enabled: false  # Optional provider
    
  generic:
    enabled: true
    timeout: 60
    fallback_tools: ['git']  # Use Git CLI as fallback

caching:
  git_repository_info:
    ttl: 3600  # 1 hour
    max_size: 1000
  git_tags:
    ttl: 1800  # 30 minutes
    max_size: 500
```

### Task C2: Service Container Integration
```yaml
# config/services.yaml (additions)
services:
  # Git Provider Factory
  CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory:
    arguments:
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient'
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitLabClient'
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GenericGitClient'

  # Git Provider Implementations
  CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient:
    arguments:
      - '@http_client'
      - '@logger'
      - '%env(GITHUB_ACCESS_TOKEN)%'

  CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitLabClient:
    arguments:
      - '@http_client'
      - '@logger'
      - '%env(GITLAB_ACCESS_TOKEN)%'

  # Git Repository Analyzer
  CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer:
    arguments:
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory'
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser'
      - '@cache.app'
      - '@logger'

  # Enhanced VersionAvailabilityAnalyzer (modify existing)
  CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer:
    arguments:
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient'
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient'
      - '@CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer'  # NEW
      - '@logger'
```

## Error Handling and Edge Cases

### Common Scenarios
1. **API Rate Limiting**: Implement exponential backoff and caching
2. **Network Failures**: Graceful degradation with cached data
3. **Authentication Issues**: Clear error messages and fallback options
4. **Repository Access**: Handle private/deleted repositories
5. **Invalid Git URLs**: Validation and normalization
6. **Mixed Version Formats**: Support both semantic and custom versioning

### Monitoring and Logging
```php
// Enhanced logging for Git operations
$this->logger->info('Git repository analysis started', [
    'extension' => $extension->getKey(),
    'repository_url' => $repositoryUrl,
    'provider' => $provider->getName()
]);

$this->logger->warning('Git API rate limit approaching', [
    'provider' => 'github',
    'remaining_requests' => $remainingRequests,
    'reset_time' => $resetTime
]);

$this->logger->error('Git repository analysis failed', [
    'extension' => $extension->getKey(),
    'repository_url' => $repositoryUrl,
    'error' => $exception->getMessage(),
    'provider_response' => $response->getContent(false)
]);
```

## Success Criteria

### Functional Requirements
- [ ] Detect extensions installed from Git repositories via composer.json
- [ ] Support GitHub, GitLab, and Bitbucket APIs
- [ ] Check Git repository tags for TYPO3 version compatibility
- [ ] Include Git availability in risk scoring algorithm
- [ ] Provide Git-specific recommendations
- [ ] Handle API failures gracefully
- [ ] Maintain backward compatibility

### Performance Requirements
- [ ] Git analysis completes within 5 seconds per extension (with caching)
- [ ] Handle API rate limits without blocking analysis
- [ ] Cache Git repository data to minimize API calls
- [ ] Support concurrent analysis of multiple extensions

### Quality Requirements
- [ ] 95%+ test coverage for all new Git components
- [ ] Integration tests with real Git repositories
- [ ] Comprehensive error handling for all failure scenarios
- [ ] Security review for Git URL handling and API authentication

## Implementation Timeline

**Week 1: Foundation**
- Git repository detection and provider abstraction
- GitHub API client implementation
- Basic integration tests

**Week 2: Provider Expansion**
- GitLab and Bitbucket API clients
- Generic Git fallback client
- Enhanced error handling

**Week 3: Analysis Logic**
- Version compatibility analysis
- Repository health assessment
- Caching implementation

**Week 4: Integration**
- VersionAvailabilityAnalyzer enhancement
- Risk scoring algorithm updates
- Configuration and documentation

**Week 5: Polish and Testing**
- Comprehensive test coverage
- Performance optimization
- Security review and deployment

This implementation plan provides a comprehensive approach to adding Git repository support while maintaining the high quality and architectural integrity of the TYPO3 Upgrade Analyzer.