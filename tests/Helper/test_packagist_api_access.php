<?php

declare(strict_types=1);

/**
 * Simple Packagist API test script without project dependencies
 * Tests the Packagist API as alternative to TER API for TYPO3 extension compatibility
 */

echo "=== Packagist API Connection Test (Alternative to TER API) ===\n\n";

function testPackagistEndpoint(string $url, string $description): array
{
    echo "Testing: {$description}\n";
    echo "URL: {$url}\n";

    $startTime = microtime(true);

    // Create HTTP context with user agent
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: TYPO3-Upgrade-Analyzer-Test-Script',
                'Accept: application/json',
            ],
            'timeout' => 10,
        ]
    ]);

    $result = [
        'url' => $url,
        'description' => $description,
        'success' => false,
        'status_code' => null,
        'response_time' => 0,
        'error' => null,
        'data' => null,
        'headers' => []
    ];

    try {
        // Make the request
        $response = file_get_contents($url, false, $context);
        $responseTime = microtime(true) - $startTime;

        // Parse HTTP response headers
        $headers = [];
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                    $result['status_code'] = (int)$matches[1];
                } else {
                    $headers[] = $header;
                }
            }
            $result['headers'] = $headers;
        }

        if ($response !== false) {
            $result['success'] = true;
            $result['response_time'] = $responseTime;

            // Try to decode JSON
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result['data'] = $decoded;
            } else {
                $result['data'] = $response;
            }

            echo "✓ SUCCESS - Status: {$result['status_code']}, Time: " . number_format($responseTime, 3) . "s\n";

            // Show some key data
            if (is_array($result['data'])) {
                if (isset($result['data']['package'])) {
                    $package = $result['data']['package'];
                    echo "  Package: {$package['name']}\n";
                    if (isset($package['description'])) {
                        echo "  Description: " . substr($package['description'], 0, 60) . "...\n";
                    }
                    if (isset($package['versions']) && is_array($package['versions'])) {
                        echo "  Versions found: " . count($package['versions']) . "\n";

                        // Check TYPO3 compatibility in latest versions
                        $typo3CompatibleVersions = [];
                        foreach (array_slice($package['versions'], 0, 5) as $version => $data) {
                            if (isset($data['require']) && is_array($data['require'])) {
                                foreach ($data['require'] as $req => $constraint) {
                                    if (strpos($req, 'typo3/cms') !== false || $req === 'typo3/cms-core') {
                                        $typo3CompatibleVersions[] = $version . ' (requires ' . $req . ': ' . $constraint . ')';
                                        break;
                                    }
                                }
                            }
                        }

                        if (!empty($typo3CompatibleVersions)) {
                            echo "  TYPO3 compatible versions:\n";
                            foreach (array_slice($typo3CompatibleVersions, 0, 3) as $version) {
                                echo "    - {$version}\n";
                            }
                        }
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

// Test various Packagist API endpoints for TYPO3 extensions
$tests = [
    // Test news extension on Packagist
    [
        'url' => 'https://packagist.org/packages/georgringer/news.json',
        'description' => 'News extension on Packagist'
    ],

    // Test extension builder
    [
        'url' => 'https://packagist.org/packages/friendsoftypo3/extension-builder.json',
        'description' => 'Extension Builder on Packagist'
    ],

    // Test bootstrap package
    [
        'url' => 'https://packagist.org/packages/bk2k/bootstrap-package.json',
        'description' => 'Bootstrap Package on Packagist'
    ],

    // Test TYPO3 core package
    [
        'url' => 'https://packagist.org/packages/typo3/cms-core.json',
        'description' => 'TYPO3 Core on Packagist'
    ],

    // Test archived extension (realurl) - should fail
    [
        'url' => 'https://packagist.org/packages/dmitryd/typo3-realurl.json',
        'description' => 'RealURL extension on Packagist (should not exist)'
    ],

    // Test non-existent package
    [
        'url' => 'https://packagist.org/packages/non-existent/test-package.json',
        'description' => 'Non-existent package (should return 404)'
    ],
];

$results = [];
foreach ($tests as $test) {
    $results[] = testPackagistEndpoint($test['url'], $test['description']);
}

// Summary
echo "=== SUMMARY ===\n";
$successCount = 0;
$errorCount = 0;

foreach ($results as $result) {
    if ($result['success']) {
        $successCount++;
        echo "✓ {$result['description']}: SUCCESS (Status: {$result['status_code']})\n";
    } else {
        $errorCount++;
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

echo "\nTotal: " . count($results) . " tests\n";
echo "Success: {$successCount}\n";
echo "Failed: {$errorCount}\n";

echo "\n=== TYPO3 COMPATIBILITY ANALYSIS ===\n";

// Analyze TYPO3 compatibility from successful results
foreach ($results as $result) {
    if ($result['success'] && isset($result['data']['package'])) {
        $package = $result['data']['package'];
        echo "Package: {$package['name']}\n";

        if (isset($package['versions']) && is_array($package['versions'])) {
            $typo3Versions = [];
            $latestVersions = array_slice($package['versions'], 0, 10, true);

            foreach ($latestVersions as $version => $data) {
                if (isset($data['require']) && is_array($data['require'])) {
                    foreach ($data['require'] as $req => $constraint) {
                        if (preg_match('/typo3\/cms(-core)?/', $req)) {
                            // Extract TYPO3 version from constraint
                            if (preg_match('/(\d+)\.(\d+)/', $constraint, $matches)) {
                                $majorVersion = $matches[1];
                                if (!in_array($majorVersion, $typo3Versions)) {
                                    $typo3Versions[] = $majorVersion;
                                }
                            }
                            break;
                        }
                    }
                }
            }

            if (!empty($typo3Versions)) {
                echo "  TYPO3 compatibility: " . implode(', ', $typo3Versions) . "\n";

                // Check specific version compatibility
                if (in_array('12', $typo3Versions)) {
                    echo "  ✓ TYPO3 12.x compatible\n";
                } else {
                    echo "  ✗ No TYPO3 12.x compatibility found\n";
                }

                if (in_array('11', $typo3Versions)) {
                    echo "  ✓ TYPO3 11.x compatible\n";
                } else {
                    echo "  ✗ No TYPO3 11.x compatibility found\n";
                }
            } else {
                echo "  ? No clear TYPO3 version requirements found\n";
            }
        }
        echo "\n";
    }
}

echo "=== CONCLUSION ===\n";
echo "Packagist API provides a viable alternative to the TER API for checking TYPO3 extension compatibility.\n";
echo "Most modern TYPO3 extensions are available on Packagist with clear version constraints.\n";
echo "This approach can be used as the primary method for compatibility checking in integration tests.\n";

echo "\nTest completed at " . date('Y-m-d H:i:s') . "\n";
