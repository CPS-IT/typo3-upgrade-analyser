<?php

declare(strict_types=1);

/**
 * Simple TER Client Test - Helper Script.
 *
 * Quick test of TER API client functionality for environment validation.
 * Tests common extensions with different TYPO3 versions.
 */

require_once \dirname(__DIR__, 1) . '/vendor/autoload.php';

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientService;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;

echo "=== TER API Client Test ===\n\n";

$symfonyClient = HttpClient::create();
$logger = new NullLogger();
$httpClient = new HttpClientService($symfonyClient, $logger);
$terClient = new TerApiClient($httpClient, $logger);

// Test cases: extension => [TYPO3 versions to test]
$testCases = [
    'news' => ['11.5.0', '12.4.0', '13.0.0'],
    'bootstrap_package' => ['12.4.0'],
    'realurl' => ['8.7.0'], // Should work for legacy
];

foreach ($testCases as $extensionKey => $versions) {
    echo "Testing extension: $extensionKey\n";
    echo str_repeat('-', 30) . "\n";

    foreach ($versions as $versionString) {
        $typo3Version = new Version($versionString);

        echo "  TYPO3 $versionString:\n";

        $hasVersion = $terClient->hasVersionFor($extensionKey, $typo3Version);
        echo '    hasVersionFor: ' . ($hasVersion ? '✅ true' : '❌ false') . "\n";

        $latestVersion = $terClient->getLatestVersion($extensionKey, $typo3Version);
        echo '    getLatestVersion: ' . ($latestVersion ?? 'null') . "\n";

        echo "\n";
    }
    echo "\n";
}

echo 'Test completed at ' . date('Y-m-d H:i:s') . "\n";
