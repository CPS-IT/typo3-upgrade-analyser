<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
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

        $rootDir = \dirname(__DIR__, 3);
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
}
