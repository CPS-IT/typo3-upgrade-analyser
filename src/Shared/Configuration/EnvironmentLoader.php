<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Shared\Configuration;

use Symfony\Component\Dotenv\Dotenv;

/**
 * Loads environment variables from .env files.
 */
class EnvironmentLoader
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $rootDir = self::findProjectRoot();
        $dotenv = new Dotenv();

        // Check for .env.local file first (higher priority)
        $envLocalFile = $rootDir . '/.env.local';
        if (file_exists($envLocalFile)) {
            $dotenv->load($envLocalFile);
        }

        // Check for .env file
        $envFile = $rootDir . '/.env';
        if (file_exists($envFile)) {
            $dotenv->load($envFile);
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();

        return $_ENV[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        self::load();

        return isset($_ENV[$key]) && '' !== $_ENV[$key];
    }

    /**
     * Find the project root directory, handling both standalone and composer installations.
     */
    private static function findProjectRoot(): string
    {
        // Try to use Composer's installed packages info to find the root
        if (class_exists('\Composer\InstalledVersions')) {
            try {
                $rootPackage = \Composer\InstalledVersions::getRootPackage();
                if (isset($rootPackage['install_path'])) {
                    return $rootPackage['install_path'];
                }
            } catch (\Throwable) {
                // Fall back to manual detection if Composer info is not available
            }
        }

        // Fallback: traverse up from current directory to find composer.json or project root
        $searchDir = __DIR__;
        $maxLevels = 10; // Prevent infinite loops

        for ($i = 0; $i < $maxLevels; ++$i) {
            // Check if we found a composer.json (indicates project root)
            if (file_exists($searchDir . '/composer.json')) {
                return $searchDir;
            }

            // Check if we found our own project structure
            if (file_exists($searchDir . '/bin/typo3-analyzer') && file_exists($searchDir . '/src')) {
                return $searchDir;
            }

            $parentDir = \dirname($searchDir);
            if ($parentDir === $searchDir) {
                // Reached filesystem root
                break;
            }
            $searchDir = $parentDir;
        }

        // Ultimate fallback: use the old behavior
        return \dirname(__DIR__, 3);
    }
}
