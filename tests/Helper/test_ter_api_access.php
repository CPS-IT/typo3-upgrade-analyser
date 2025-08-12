<?php

declare(strict_types=1);

/**
 * Simple TER API test script without project dependencies
 * Tests the TYPO3 Extension Repository API endpoints.
 */
echo "=== TYPO3 TER API Connection Test ===\n\n";

// Load environment variables from .env.local file
function loadEnvFile(string $filePath): void
{
    if (!file_exists($filePath)) {
        echo "Warning: .env.local file not found at {$filePath}\n";

        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        echo "Error: Could not read file {$filePath}\n";
        return;
    }

    foreach ($lines as $line) {
        if (0 === strpos(trim($line), '#')) {
            continue; // Skip comments
        }

        if (false !== strpos($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (!empty($key) && !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Load environment variables
loadEnvFile(__DIR__ . '/.env.local');

// Get TER token from environment
$terToken = $_ENV['TER_TOKEN'] ?? getenv('TER_TOKEN') ?: '';

if (empty($terToken)) {
    echo "Warning: No TER_TOKEN found in environment variables\n";
    echo "Please set TER_TOKEN in .env.local file\n\n";
} else {
    echo "✓ TER_TOKEN loaded from environment\n";
    echo 'Token length: ' . \strlen($terToken) . " characters\n\n";
}

function testTerApiEndpoint(string $url, string $description, string $terToken = ''): array
{
    echo "Testing: {$description}\n";
    echo "URL: {$url}\n";

    $startTime = microtime(true);

    // Create HTTP context with user agent and authentication
    $headers = [
        'User-Agent: TYPO3-Upgrade-Analyzer-Test-Script',
        'Accept: application/json',
    ];

    // Add Bearer authentication if token is available
    if (!empty($terToken)) {
        $headers[] = 'Authorization: Bearer ' . $terToken;
        echo "  Using Bearer authentication\n";
    } else {
        echo "  No authentication (testing public endpoints)\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 10,
        ],
    ]);

    $result = [
        'url' => $url,
        'description' => $description,
        'success' => false,
        'status_code' => null,
        'response_time' => 0,
        'error' => null,
        'data' => null,
        'headers' => [],
    ];

    try {
        // Make the request
        $response = file_get_contents($url, false, $context);
        $responseTime = microtime(true) - $startTime;

        // Parse HTTP response headers
        $headers = [];
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                    $result['status_code'] = (int) $matches[1];
                } else {
                    $headers[] = $header;
                }
            }
            $result['headers'] = $headers;
        }

        if (false !== $response) {
            $result['success'] = true;
            $result['response_time'] = $responseTime;

            // Try to decode JSON
            $decoded = json_decode($response, true);
            if (JSON_ERROR_NONE === json_last_error()) {
                $result['data'] = $decoded;
            } else {
                $result['data'] = $response;
            }

            echo "✓ SUCCESS - Status: {$result['status_code']}, Time: " . number_format($responseTime, 3) . "s\n";

            // Show some key data
            if (\is_array($result['data'])) {
                // Extension details endpoint
                if (isset($result['data']['name'])) {
                    echo "  Extension: {$result['data']['name']}\n";
                }
                if (isset($result['data']['key'])) {
                    echo "  Key: {$result['data']['key']}\n";
                }

                // Extension versions endpoint - detailed analysis
                if (isset($result['data']['versions']) && \is_array($result['data']['versions'])) {
                    echo '  Versions found: ' . \count($result['data']['versions']) . "\n";

                    // Analyze TYPO3 compatibility across versions
                    $typo3Compatibility = [];
                    $recentVersions = \array_slice($result['data']['versions'], 0, 10, true);

                    foreach ($recentVersions as $versionData) {
                        if (isset($versionData['typo3_versions']) && \is_array($versionData['typo3_versions'])) {
                            foreach ($versionData['typo3_versions'] as $typo3Version) {
                                if (!\in_array($typo3Version, $typo3Compatibility, true)) {
                                    $typo3Compatibility[] = $typo3Version;
                                }
                            }
                        }
                    }

                    if (!empty($typo3Compatibility)) {
                        sort($typo3Compatibility);
                        echo '  TYPO3 compatibility (all versions): ' . implode(', ', $typo3Compatibility) . "\n";

                        // Check specific version support
                        if (\in_array(12, $typo3Compatibility, true)) {
                            echo "  ✓ TYPO3 12.x support: YES\n";
                        } else {
                            echo "  ✗ TYPO3 12.x support: NO\n";
                        }

                        if (\in_array(11, $typo3Compatibility, true)) {
                            echo "  ✓ TYPO3 11.x support: YES\n";
                        } else {
                            echo "  ✗ TYPO3 11.x support: NO\n";
                        }
                    }

                    // Show latest version details
                    $firstVersion = reset($result['data']['versions']);
                    if (isset($firstVersion['version'])) {
                        echo "  Latest version: {$firstVersion['version']}\n";

                        if (isset($firstVersion['typo3_versions']) && \is_array($firstVersion['typo3_versions'])) {
                            echo '  Latest version TYPO3 compatibility: ' . implode(', ', $firstVersion['typo3_versions']) . "\n";
                        }
                    }
                }

                // Extension listing endpoint
                if (isset($result['data']['data']) && \is_array($result['data']['data'])) {
                    echo '  Extensions found: ' . \count($result['data']['data']) . "\n";
                }

                // Single extension response format
                if (isset($result['data']['key']) && !isset($result['data']['versions'])) {
                    echo "  Extension key: {$result['data']['key']}\n";
                    if (isset($result['data']['description'])) {
                        echo '  Description: ' . substr($result['data']['description'], 0, 60) . "...\n";
                    }
                }
            }
        } else {
            $result['error'] = 'Request failed - no response received';
            echo "✗ FAILED - No response received\n";
        }
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        echo "✗ EXCEPTION - {$e->getMessage()}\n";
    }

    echo "\n";

    return $result;
}

// Test various TER API endpoints
$tests = [
    // Test general API health
    [
        'url' => 'https://extensions.typo3.org/api/v1/ping',
        'description' => 'API Health Check (ping)',
    ],

    // Test extension listing with TYPO3 12 filter
    [
        'url' => 'https://extensions.typo3.org/api/v1/extension?filter[typo3_version]=12&page[size]=5',
        'description' => 'Extensions compatible with TYPO3 12 (first 5)',
    ],

    // Test specific extension - news
    [
        'url' => 'https://extensions.typo3.org/api/v1/extension/news',
        'description' => 'News extension details',
    ],

    // Test extension versions - news
    [
        'url' => 'https://extensions.typo3.org/api/v1/extension/news/versions',
        'description' => 'News extension versions',
    ],

    // Test specific extension - realurl (archived)
    [
        'url' => 'https://extensions.typo3.org/api/v1/extension/realurl',
        'description' => 'RealURL extension details (archived)',
    ],

    // Test extension versions - realurl
    [
        'url' => 'https://extensions.typo3.org/api/v1/extension/realurl/versions',
        'description' => 'RealURL extension versions',
    ],

    // Test non-existent extension
    [
        'url' => 'https://extensions.typo3.org/api/v1/extension/non_existent_extension_test_12345',
        'description' => 'Non-existent extension (should return 404)',
    ],
];

$results = [];
foreach ($tests as $test) {
    $results[] = testTerApiEndpoint($test['url'], $test['description'], $terToken);
}

// Summary
echo "=== SUMMARY ===\n";
$successCount = 0;
$errorCount = 0;

foreach ($results as $result) {
    if ($result['success']) {
        ++$successCount;
        echo "✓ {$result['description']}: SUCCESS (Status: {$result['status_code']})\n";
    } else {
        ++$errorCount;
        echo "✗ {$result['description']}: FAILED";
        if ($result['status_code']) {
            echo " (Status: {$result['status_code']})";
        }
        if ($result['error']) {
            echo " - {$result['error']}";
        }
        echo "\n";
    }
}

echo "\nTotal: " . \count($results) . " tests\n";
echo "Success: {$successCount}\n";
echo "Failed: {$errorCount}\n";

// Check for specific issues
if ($errorCount > 0) {
    echo "\n=== TROUBLESHOOTING ===\n";

    foreach ($results as $result) {
        if (!$result['success']) {
            echo "Failed test: {$result['description']}\n";
            echo "URL: {$result['url']}\n";

            if ($result['status_code']) {
                switch ($result['status_code']) {
                    case 403:
                        echo "Issue: Access forbidden - API might require authentication\n";
                        break;
                    case 404:
                        echo "Issue: Not found - endpoint or extension doesn't exist\n";
                        break;
                    case 429:
                        echo "Issue: Rate limited - too many requests\n";
                        break;
                    case 500:
                        echo "Issue: Server error\n";
                        break;
                    default:
                        echo "Issue: HTTP status {$result['status_code']}\n";
                }
            }

            if ($result['error']) {
                echo "Error: {$result['error']}\n";
            }

            echo "\n";
        }
    }
}

echo "\n=== API ANALYSIS ===\n";

// Check if news extension was found
$newsTest = array_filter($results, fn ($r): bool => str_contains($r['url'], '/extension/news') && !str_contains($r['url'], '/versions'));
if (!empty($newsTest)) {
    $newsResult = reset($newsTest);
    if ($newsResult['success'] && isset($newsResult['data']['key'])) {
        echo "✓ News extension is accessible via TER API\n";
    } else {
        echo "✗ News extension not accessible - this may explain test failures\n";
    }
}

// Check if realurl extension was found
$realurlTest = array_filter($results, fn ($r): bool => str_contains($r['url'], '/extension/realurl') && !str_contains($r['url'], '/versions'));
if (!empty($realurlTest)) {
    $realurlResult = reset($realurlTest);
    if ($realurlResult['success'] && isset($realurlResult['data']['key'])) {
        echo "✓ RealURL extension is accessible via TER API\n";
    } else {
        echo "✗ RealURL extension not accessible\n";
    }
}

// Check API availability
$successfulTests = array_filter($results, fn ($r) => $r['success']);
if (\count($successfulTests) > 0) {
    echo "✓ TER API is reachable and responding\n";
} else {
    echo "✗ TER API appears to be unreachable or all endpoints failed\n";
}

echo "\nTest completed at " . date('Y-m-d H:i:s') . "\n";
