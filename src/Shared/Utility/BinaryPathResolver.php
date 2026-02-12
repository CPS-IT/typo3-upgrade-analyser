<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Shared\Utility;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutor;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorExecutor;
use Psr\Log\LoggerInterface;

/**
 * Utility class for resolving binary paths in different installation scenarios.
 *
 * Handles both standalone installations and Composer dependency installations.
 */
class BinaryPathResolver
{
    /**
     * Resolve the path to a binary, handling both standalone and Composer installations.
     *
     * @param string $binaryName  The name of the binary (e.g., 'fractor', 'rector')
     * @param string $projectRoot The resolved project root directory
     *
     * @return string The full path to the binary
     */
    public static function resolveBinaryPath(string $binaryName, string $projectRoot): string
    {
        // Strategy 1: Try the project root's vendor/bin directory first
        // This works when the analyzer is installed as a Composer dependency
        $projectBinary = $projectRoot . '/vendor/bin/' . $binaryName;
        if (file_exists($projectBinary)) {
            return $projectBinary;
        }

        // Strategy 2: Try to detect if we're inside the analyzer's own directory structure
        // Look for our own bin/typo3-analyzer to identify we're in standalone mode
        if (file_exists($projectRoot . '/bin/typo3-analyzer')) {
            // We're in standalone mode - binary should be in our vendor/bin
            $standaloneBinary = $projectRoot . '/vendor/bin/' . $binaryName;
            if (file_exists($standaloneBinary)) {
                return $standaloneBinary;
            }
        }

        // Strategy 3: If we're installed as a dependency, try to find the consuming project's vendor/bin
        // Walk up the directory tree to find the actual project root with vendor/bin
        $searchDir = $projectRoot;
        $maxLevels = 5; // Reasonable limit to prevent infinite loops

        for ($i = 0; $i < $maxLevels; ++$i) {
            $candidateBinary = $searchDir . '/vendor/bin/' . $binaryName;
            if (file_exists($candidateBinary)) {
                return $candidateBinary;
            }

            $parentDir = \dirname($searchDir);
            if ($parentDir === $searchDir) {
                // Reached filesystem root
                break;
            }
            $searchDir = $parentDir;
        }

        // Strategy 4: Use Composer's vendor directory detection if available
        if (class_exists('\\Composer\\InstalledVersions')) {
            try {
                // Get the vendor directory from Composer
                $reflection = new \ReflectionClass('\\Composer\\InstalledVersions');
                $filename = $reflection->getFileName();
                if (false !== $filename) {
                    // Composer's InstalledVersions is typically in vendor/composer/InstalledVersions.php
                    $vendorDir = \dirname($filename, 2);
                    $composerBinary = $vendorDir . '/bin/' . $binaryName;
                    if (file_exists($composerBinary)) {
                        return $composerBinary;
                    }
                }
            } catch (\Throwable) {
                // Fall through to default
            }
        }

        // Strategy 5: Global binary check (if installed globally)
        $globalBinary = self::findGlobalBinary($binaryName);
        if (null !== $globalBinary) {
            return $globalBinary;
        }

        // Fallback: Return the expected path even if it doesn't exist
        // This will result in a "binary not found" error, which is appropriate
        return $projectRoot . '/vendor/bin/' . $binaryName;
    }

    /**
     * Try to find a globally installed binary.
     *
     * @param string $binaryName The binary name to search for
     *
     * @return string|null The path to the global binary or null if not found
     */
    private static function findGlobalBinary(string $binaryName): ?string
    {
        $homeDir = $_SERVER['HOME'] ?? '';

        // Check common global installation paths
        $globalPaths = [
            '/usr/local/bin/' . $binaryName,
            '/usr/bin/' . $binaryName,
        ];

        // Add HOME-based paths only if HOME is available
        if ('' !== $homeDir) {
            $globalPaths[] = $homeDir . '/.composer/vendor/bin/' . $binaryName;
            $globalPaths[] = $homeDir . '/.config/composer/vendor/bin/' . $binaryName;
        }

        foreach ($globalPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try using the 'which' command if available
        $whichResult = shell_exec('which ' . escapeshellarg($binaryName) . ' 2>/dev/null');
        if (!empty($whichResult)) {
            $path = trim($whichResult);
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Factory method to create a FractorExecutor with resolved binary path.
     *
     * @param LoggerInterface $logger         Logger instance
     * @param int             $timeoutSeconds Execution timeout
     * @param string          $projectRoot    Project root directory
     *
     * @return FractorExecutor Configured executor instance
     */
    public static function createFractorExecutor(
        LoggerInterface $logger,
        int $timeoutSeconds,
        string $projectRoot,
    ): FractorExecutor {
        $binaryPath = self::resolveBinaryPath('fractor', $projectRoot);

        return new FractorExecutor(
            $binaryPath,
            $logger,
            $timeoutSeconds,
            $projectRoot,  // Pass project root to executor
        );
    }

    /**
     * Factory method to create a RectorExecutor with resolved binary path.
     *
     * @param LoggerInterface $logger         Logger instance
     * @param int             $timeoutSeconds Execution timeout
     * @param string          $projectRoot    Project root directory
     *
     * @return RectorExecutor Configured executor instance
     */
    public static function createRectorExecutor(
        LoggerInterface $logger,
        int $timeoutSeconds,
        string $projectRoot,
    ): RectorExecutor {
        $binaryPath = self::resolveBinaryPath('rector', $projectRoot);

        return new RectorExecutor(
            $binaryPath,
            $logger,
            $timeoutSeconds,
        );
    }
}
