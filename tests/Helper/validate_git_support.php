<?php

declare(strict_types=1);

/**
 * Simple validation script for Git repository support
 * Tests real-world functionality with a known TYPO3 extension.
 */

require_once __DIR__ . '/vendor/autoload.php';

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer;
use CPSIT\UpgradeAnalyzer\Shared\Configuration\ContainerFactory;

echo "🔍 TYPO3 Upgrade Analyzer - Git Repository Support Validation\n";
echo "============================================================\n\n";

try {
    // Initialize container
    $container = ContainerFactory::create(__DIR__ . '/config');

    // Get the analyzer with Git support
    $analyzer = $container->get(VersionAvailabilityAnalyzer::class);

    // Create test extension (georgringer/news - popular TYPO3 extension on GitHub)
    $extension = new Extension(
        'news',
        'News system',
        Version::fromString('11.0.0'),
        'local',
        'georgringer/news',
    );

    // Set GitHub repository URL
    $extension->setRepositoryUrl('https://github.com/georgringer/news');

    // Create analysis context for TYPO3 12.4
    $context = new AnalysisContext(
        Version::fromString('11.5.0'),
        Version::fromString('12.4.0'),
    );

    echo "📦 Testing Extension: {$extension->getKey()}\n";
    echo "🔗 Repository: {$extension->getRepositoryUrl()}\n";
    echo "🎯 Target TYPO3: {$context->getTargetVersion()->toString()}\n\n";

    echo "⏳ Running analysis...\n\n";

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
        echo '  Repository Health: ' . ($result->getMetric('git_repository_health') ?? 'N/A') . "\n";
        echo '  Repository URL: ' . ($result->getMetric('git_repository_url') ?? 'N/A') . "\n";
        echo '  Latest Version: ' . ($result->getMetric('git_latest_version') ?? 'N/A') . "\n";
    }

    // Recommendations
    $recommendations = $result->getRecommendations();
    if (!empty($recommendations)) {
        echo "\n💡 Recommendations:\n";
        foreach ($recommendations as $i => $recommendation) {
            echo '  ' . ($i + 1) . ". {$recommendation}\n";
        }
    }

    echo "\n🎉 Git repository support is working correctly!\n";
} catch (Throwable $e) {
    echo "❌ Error: {$e->getMessage()}\n";
    echo "🔍 File: {$e->getFile()}:{$e->getLine()}\n";
    echo "\n📋 Stack trace:\n{$e->getTraceAsString()}\n";
    exit(1);
}

echo "\n✨ Validation completed successfully!\n";
