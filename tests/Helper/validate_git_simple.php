<?php

declare(strict_types=1);

/**
 * Simple manual validation of Git repository support
 * Manually constructs services to test real-world functionality.
 */

require_once __DIR__ . '/vendor/autoload.php';

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\HttpClient\HttpClient;

echo "🔍 TYPO3 Upgrade Analyzer - Git Repository Support Validation\n";
echo "============================================================\n\n";

try {
    // Create logger
    $logger = new Logger('typo3-upgrade-analyzer');
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::WARNING)); // Reduce noise

    // Create HTTP client
    $httpClient = HttpClient::create([
        'timeout' => 30,
        'headers' => [
            'User-Agent' => 'TYPO3-Upgrade-Analyzer/1.0',
        ],
    ]);

    // Create Git infrastructure
    $gitHubClient = new GitHubClient($httpClient, $logger, $_ENV['GITHUB_TOKEN'] ?? '');
    $gitProviderFactory = new GitProviderFactory([$gitHubClient], $logger);
    $gitVersionParser = new GitVersionParser();
    $gitRepositoryAnalyzer = new GitRepositoryAnalyzer($gitProviderFactory, $gitVersionParser, $logger);

    // Create API clients
    $terApiClient = new TerApiClient($httpClient, $logger);
    $packagistClient = new PackagistClient($httpClient, $logger);

    // Create the analyzer with Git support
    $analyzer = new VersionAvailabilityAnalyzer(
        $terApiClient,
        $packagistClient,
        $gitRepositoryAnalyzer,
        $logger,
    );

    // Test with a well-known TYPO3 extension
    $extension = new Extension(
        'news',
        'News system',
        Version::fromString('11.0.0'),
        'local',
        'georgringer/news',
    );

    // Set repository URL and EM configuration
    $extension->setRepositoryUrl('https://github.com/georgringer/news');
    $extension->setEmConfiguration([
        'git_repository_url' => 'https://github.com/georgringer/news',
    ]);

    // Create analysis context
    $context = new AnalysisContext(
        Version::fromString('11.5.0'),
        Version::fromString('12.4.0'),
    );

    echo "📦 Testing Extension: {$extension->getKey()}\n";
    echo "🔗 Repository: {$extension->getRepositoryUrl()}\n";
    echo "🎯 Target TYPO3: {$context->getTargetVersion()->toString()}\n";

    // Test GitHub token availability
    $hasToken = !empty(getenv('GITHUB_TOKEN') ?? '');
    echo '🔑 GitHub Token: ' . ($hasToken ? '✅ Available' : '⚠️  Not set (rate limited)') . "\n\n";

    echo "⏳ Running analysis...\n";

    // Run the analysis
    $startTime = microtime(true);
    $result = $analyzer->analyze($extension, $context);
    $endTime = microtime(true);

    $analysisTime = round(($endTime - $startTime) * 1000, 2);

    // Display results
    echo "✅ Analysis completed in {$analysisTime}ms\n\n";

    echo "📊 Results:\n";
    echo '  Status: ' . ($result->isSuccessful() ? '✅ Success' : '❌ Failed') . "\n";
    echo "  Risk Score: {$result->getRiskScore()}\n";
    echo "  Risk Level: {$result->getRiskLevel()}\n\n";

    echo "📈 Availability Metrics:\n";
    echo '  TER Available: ' . ($result->getMetric('ter_available') ? '✅ Yes' : '❌ No') . "\n";
    echo '  Packagist Available: ' . ($result->getMetric('packagist_available') ? '✅ Yes' : '❌ No') . "\n";
    echo '  Git Available: ' . ($result->getMetric('git_available') ? '✅ Yes' : '❌ No') . "\n";

    // Git-specific metrics
    if ($result->getMetric('git_available')) {
        echo "\n🔍 Git Repository Analysis:\n";
        $health = $result->getMetric('git_repository_health');
        echo '  Repository Health: ' . (null !== $health ? \sprintf('%.2f', $health) : 'N/A') . "\n";
        echo '  Repository URL: ' . ($result->getMetric('git_repository_url') ?? 'N/A') . "\n";
        echo '  Latest Version: ' . ($result->getMetric('git_latest_version') ?? 'N/A') . "\n";
    }

    // Test Git components individually for more details
    echo "\n🔧 Component Testing:\n";

    try {
        $gitInfo = $gitRepositoryAnalyzer->analyzeExtension($extension, $context->getTargetVersion());
        echo "  Git Analysis: ✅ Success\n";
        echo '    - Health Score: ' . \sprintf('%.2f', $gitInfo->getHealthScore()) . "\n";
        echo '    - Compatible Versions: ' . \count($gitInfo->getCompatibleVersions()) . "\n";
        echo '    - Has Compatible Version: ' . ($gitInfo->hasCompatibleVersion() ? 'Yes' : 'No') . "\n";
    } catch (Throwable $e) {
        echo "  Git Analysis: ❌ Failed ({$e->getMessage()})\n";
    }

    // Recommendations
    $recommendations = $result->getRecommendations();
    if (!empty($recommendations)) {
        echo "\n💡 Recommendations:\n";
        foreach ($recommendations as $i => $recommendation) {
            echo '  ' . ($i + 1) . ". {$recommendation}\n";
        }
    }

    echo "\n🎉 Git repository support validation completed!\n";

    // Summary
    $gitWorking = $result->getMetric('git_available');
    $terWorking = $result->getMetric('ter_available');
    $packagistWorking = $result->getMetric('packagist_available');

    echo "\n📋 Summary:\n";
    echo '  TER Integration: ' . ($terWorking ? '✅ Working' : '❌ Failed') . "\n";
    echo '  Packagist Integration: ' . ($packagistWorking ? '✅ Working' : '❌ Failed') . "\n";
    echo '  Git Integration: ' . ($gitWorking ? '✅ Working' : '❌ Failed') . "\n";
    echo '  Overall: ' . ($result->isSuccessful() ? '✅ Success' : '❌ Failed') . "\n";

    if ($gitWorking && $terWorking && $packagistWorking) {
        echo "\n🚀 All systems operational! Ready for production use.\n";
    } else {
        echo "\n⚠️  Some components failed. Check network connectivity and API availability.\n";
    }
} catch (Throwable $e) {
    echo "❌ Critical Error: {$e->getMessage()}\n";
    echo "🔍 File: {$e->getFile()}:{$e->getLine()}\n\n";

    // More user-friendly error messages
    if (str_contains($e->getMessage(), 'cURL error')) {
        echo "💡 This appears to be a network connectivity issue.\n";
        echo "   Please check your internet connection and try again.\n";
    } elseif (str_contains($e->getMessage(), 'Class') && str_contains($e->getMessage(), 'not found')) {
        echo "💡 This appears to be a missing class issue.\n";
        echo "   Please run 'composer install' to ensure all dependencies are available.\n";
    }

    exit(1);
}

echo "\n✨ Validation completed!\n";
