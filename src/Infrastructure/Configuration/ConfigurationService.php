<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Configuration;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

class ConfigurationService implements ConfigurationServiceInterface
{
    public const string DEFAULT_CONFIG_PATH = 'typo3-analyzer.yaml';

    // Default configuration values based on documentation/configuration.example.yaml
    public const string DEFAULT_TARGET_VERSION = '13.4';
    public const array DEFAULT_PHP_VERSIONS = ['8.3', '8.4'];
    public const array DEFAULT_REPORT_FORMATS = ['markdown'];
    public const string DEFAULT_OUTPUT_DIRECTORY = 'var/reports/';
    public const bool DEFAULT_RESULT_CACHE_ENABLED = true;
    public const int DEFAULT_RESULT_CACHE_TTL = 3600;
    public const bool DEFAULT_INCLUDE_CHARTS = false;

    // Analyzer defaults
    public const array DEFAULT_VERSION_AVAILABILITY_SOURCES = ['ter', 'packagist', 'github'];
    public const int DEFAULT_ANALYZER_TIMEOUT = 30;
    public const int DEFAULT_PHPSTAN_LEVEL = 6;
    public const int DEFAULT_COMPLEXITY_THRESHOLD = 10;
    public const int DEFAULT_LOC_THRESHOLD = 1000;

    // External tool defaults
    public const string DEFAULT_RECTOR_BINARY = 'vendor/bin/rector';
    public const string DEFAULT_FRACTOR_BINARY = 'vendor/bin/fractor';
    public const string DEFAULT_TYPOSCRIPT_LINT_BINARY = 'vendor/bin/typoscript-lint';
    public const string DEFAULT_TYPOSCRIPT_LINT_CONFIG = 'typoscript-lint.yml';

    private array $configuration = [];
    private string $configPath;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $configPath = self::DEFAULT_CONFIG_PATH,
    ) {
        $this->configPath = $configPath;
        $this->loadConfiguration();
    }

    public function isResultCacheEnabled(): bool
    {
        return $this->get(self::CONFIG_ANALYSIS_RESULT_CACHE_ENABLED, self::DEFAULT_RESULT_CACHE_ENABLED);
    }

    public function getResultCacheTtl(): int
    {
        return $this->get(self::CONFIG_ANALYSIS_RESULT_CACHE_TTL, self::DEFAULT_RESULT_CACHE_TTL);
    }

    public function getInstallationPath(): ?string
    {
        return $this->get(self::CONFIG_ANALYSIS_INSTALLATION_PATH);
    }

    public function getTargetVersion(): string
    {
        return $this->get(self::CONFIG_ANALYSIS_TARGET_VERSION, self::DEFAULT_TARGET_VERSION);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->configuration;

        foreach ($keys as $keyPart) {
            if (!\is_array($value) || !\array_key_exists($keyPart, $value)) {
                return $default;
            }
            $value = $value[$keyPart];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$this->configuration;

        foreach ($keys as $i => $keyPart) {
            if ($i === \count($keys) - 1) {
                $current[$keyPart] = $value;
            } else {
                if (!isset($current[$keyPart]) || !\is_array($current[$keyPart])) {
                    $current[$keyPart] = [];
                }
                $current = &$current[$keyPart];
            }
        }
    }

    public function getAll(): array
    {
        return $this->configuration;
    }

    public function reload(): void
    {
        $this->loadConfiguration();
    }

    /**
     * Create a new ConfigurationService instance with a different config path.
     */
    public function withConfigPath(string $configPath): self
    {
        return new self($this->logger, $configPath);
    }

    private function loadConfiguration(): void
    {
        if (!file_exists($this->configPath)) {
            $this->logger->warning('Configuration file not found, using defaults', ['path' => $this->configPath]);
            $this->configuration = $this->getDefaultConfiguration();

            return;
        }

        try {
            $config = Yaml::parseFile($this->configPath);
            $this->configuration = \is_array($config) ? $config : [];

            $this->logger->debug('Configuration loaded', ['path' => $this->configPath]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to load configuration file', [
                'path' => $this->configPath,
                'error' => $e->getMessage(),
            ]);
            $this->configuration = $this->getDefaultConfiguration();
        }
    }

    /**
     * Get the default configuration array.
     * This can be used by commands and other components that need the default configuration structure.
     */
    public function getDefaultConfiguration(): array
    {
        return [
            'analysis' => [
                'installationPath' => null,
                'targetVersion' => self::DEFAULT_TARGET_VERSION,
                'resultCache' => [
                    'enabled' => self::DEFAULT_RESULT_CACHE_ENABLED,
                    'ttl' => self::DEFAULT_RESULT_CACHE_TTL,
                ],
                'phpVersions' => self::DEFAULT_PHP_VERSIONS,
                'analyzers' => [
                    'version_availability' => [
                        'enabled' => true,
                        'sources' => self::DEFAULT_VERSION_AVAILABILITY_SOURCES,
                        'timeout' => self::DEFAULT_ANALYZER_TIMEOUT,
                    ],
                    'static_analysis' => [
                        'enabled' => true,
                        'tools' => [
                            'phpstan' => [
                                'level' => self::DEFAULT_PHPSTAN_LEVEL,
                                'config' => null,
                            ],
                        ],
                    ],
                    'deprecation_scanner' => [
                        'enabled' => true,
                    ],
                    'tca_migration' => [
                        'enabled' => true,
                    ],
                    'code_quality' => [
                        'enabled' => true,
                        'complexity_threshold' => self::DEFAULT_COMPLEXITY_THRESHOLD,
                        'loc_threshold' => self::DEFAULT_LOC_THRESHOLD,
                    ],
                ],
            ],
            'reporting' => [
                'formats' => self::DEFAULT_REPORT_FORMATS,
                'output_directory' => self::DEFAULT_OUTPUT_DIRECTORY,
                'includeCharts' => self::DEFAULT_INCLUDE_CHARTS,
            ],
            'externalTools' => [
                'rector' => [
                    'binary' => self::DEFAULT_RECTOR_BINARY,
                    'config' => null,
                ],
                'fractor' => [
                    'binary' => self::DEFAULT_FRACTOR_BINARY,
                    'config' => null,
                ],
                'typoscript_lint' => [
                    'binary' => self::DEFAULT_TYPOSCRIPT_LINT_BINARY,
                    'config' => self::DEFAULT_TYPOSCRIPT_LINT_CONFIG,
                ],
            ],
        ];
    }
}
