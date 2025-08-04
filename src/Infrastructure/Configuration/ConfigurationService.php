<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Configuration;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

class ConfigurationService implements ConfigurationServiceInterface
{
    public const string DEFAULT_CONFIG_PATH = 'typo3-analyzer.yaml';

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
        return $this->get('analysis.resultCache.enabled', false);
    }

    public function getResultCacheTtl(): int
    {
        return $this->get('analysis.resultCache.ttl', 3600);
    }

    public function getInstallationPath(): ?string
    {
        return $this->get('analysis.installationPath');
    }

    public function getTargetVersion(): string
    {
        return $this->get('analysis.targetVersion', '12.4');
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

    private function getDefaultConfiguration(): array
    {
        return [
            'analysis' => [
                'installationPath' => null,
                'targetVersion' => '13.4',
                'resultCache' => [
                    'enabled' => false,
                    'ttl' => 3600,
                ],
            ],
        ];
    }
}
