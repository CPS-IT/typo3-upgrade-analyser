<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Parser;

use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception\YamlParseException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException as SymfonyParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * YAML configuration parser for TYPO3.
 *
 * Parses YAML configuration files such as Services.yaml, site configurations,
 * and other YAML-based configuration files used in modern TYPO3 installations.
 */
final class YamlConfigurationParser extends AbstractConfigurationParser
{
    private int $yamlFlags;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);

        // Set YAML parsing flags for TYPO3 compatibility
        $this->yamlFlags = Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE |
                          Yaml::PARSE_DATETIME;
    }

    public function getFormat(): string
    {
        return 'yaml';
    }

    public function getName(): string
    {
        return 'YAML Configuration Parser';
    }

    public function getSupportedExtensions(): array
    {
        return ['yaml', 'yml'];
    }

    public function getPriority(): int
    {
        return 70; // High priority for YAML files
    }

    public function getRequiredDependencies(): array
    {
        return ['symfony/yaml'];
    }

    public function isReady(): bool
    {
        if (!class_exists(Yaml::class)) {
            throw new \RuntimeException('Symfony YAML component is required but not available');
        }

        return parent::isReady();
    }

    protected function supportsSpecific(string $filePath): bool
    {
        $fileName = basename($filePath);

        // Check for known TYPO3 YAML configuration files
        $supportedFiles = [
            'Services.yaml',
            'services.yaml',
            'config.yaml',
            'site.yaml',
        ];

        if (\in_array($fileName, $supportedFiles, true)) {
            return true;
        }

        // Check for filename patterns (for test files)
        if ((str_starts_with($fileName, 'Services') && str_ends_with($fileName, '.yaml'))
            || (str_starts_with($fileName, 'site') && str_ends_with($fileName, '.yaml'))
            || (str_starts_with($fileName, 'language') && str_ends_with($fileName, '.yaml'))) {
            return true;
        }

        // Check if it's in a TYPO3 configuration directory
        $supportedPaths = [
            '/config/sites/',
            '/config/system/',
            '/Configuration/',
            '/Resources/Private/Language/',
        ];

        foreach ($supportedPaths as $path) {
            if (str_contains($filePath, $path)) {
                return true;
            }
        }

        // Check if file contains TYPO3-specific YAML patterns
        return $this->looksLikeTypo3YamlFile($filePath);
    }

    protected function doParse(string $content, string $sourcePath): array
    {
        try {
            // Apply custom parsing options if set
            $flags = $this->getOption('yaml_flags', $this->yamlFlags);

            // Parse YAML content
            $parsedData = Yaml::parse($content, $flags);

            // Handle null result (empty YAML file)
            if (null === $parsedData) {
                $this->logger->debug('YAML file is empty or contains only comments', [
                    'source' => $sourcePath,
                ]);

                return [];
            }

            // Ensure we return an array
            if (!\is_array($parsedData)) {
                throw YamlParseException::invalidYamlStructure('YAML root must be an array or object, got ' . \gettype($parsedData), $sourcePath);
            }

            $this->logger->debug('YAML configuration parsed successfully', [
                'source' => $sourcePath,
                'keys_found' => \count($parsedData),
                'has_nested_data' => $this->hasNestedArrays($parsedData),
            ]);

            return $parsedData;
        } catch (SymfonyParseException $e) {
            throw YamlParseException::fromSymfonyYamlException($e, $sourcePath);
        } catch (\Throwable $e) {
            throw YamlParseException::invalidYamlStructure($e->getMessage(), $sourcePath);
        }
    }

    protected function validateParsedData(array $data, string $sourcePath): array
    {
        $errors = [];
        $warnings = [];

        $fileName = basename($sourcePath);

        // Validate specific TYPO3 YAML file types
        if ('Services.yaml' === $fileName || 'services.yaml' === $fileName
            || (str_starts_with($fileName, 'Services') && str_ends_with($fileName, '.yaml'))) {
            $this->validateServicesYaml($data, $errors, $warnings);
        } elseif ('site.yaml' === $fileName || str_contains($sourcePath, '/config/sites/')
                 || (str_starts_with($fileName, 'site') && str_ends_with($fileName, '.yaml'))) {
            $this->validateSiteConfiguration($data, $errors, $warnings);
        } elseif (str_contains($sourcePath, '/Resources/Private/Language/')
                 || (str_starts_with($fileName, 'language') && str_ends_with($fileName, '.yaml'))) {
            $this->validateLanguageFile($data, $errors, $warnings);
        }

        // General YAML structure validation
        $this->validateYamlStructure($data, $errors, $warnings, $sourcePath);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    protected function postProcessData(array $data, string $sourcePath): array
    {
        // Apply TYPO3-specific post-processing
        $processedData = $this->processTypo3Placeholders($data);
        $processedData = $this->normalizeTypo3Configuration($processedData);

        return $processedData;
    }

    /**
     * Check if file looks like a TYPO3 YAML configuration file.
     *
     * @param string $filePath File path to check
     *
     * @return bool True if file looks like TYPO3 YAML config
     */
    private function looksLikeTypo3YamlFile(string $filePath): bool
    {
        if (!$this->isFileAccessible($filePath)) {
            return false;
        }

        // Read first few lines to check for TYPO3 patterns
        $handle = fopen($filePath, 'r');
        if (false === $handle) {
            return false;
        }

        $lines = [];
        for ($i = 0; $i < 50 && !feof($handle); ++$i) {
            $line = fgets($handle);
            if (false !== $line) {
                $lines[] = trim($line);
            }
        }
        fclose($handle);

        $content = implode(' ', $lines);

        // Look for TYPO3-specific YAML patterns
        $patterns = [
            '/services\s*:/',
            '/base\s*:.*https?:\/\//',
            '/rootPageId\s*:/',
            '/languages\s*:/',
            '/routes\s*:/',
            '/errorHandling\s*:/',
            '/class\s*:.*\\\\/',
            '/arguments\s*:/',
            '/tags\s*:/',
            '/_defaults\s*:/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate Services.yaml structure.
     *
     * @param array<string, mixed> $data     Configuration data
     * @param array<string>        $errors   Error array to populate
     * @param array<string>        $warnings Warning array to populate
     */
    private function validateServicesYaml(array $data, array &$errors, array &$warnings): void
    {
        // Check for services section
        if (!isset($data['services'])) {
            $warnings[] = 'Services.yaml file does not contain services section';

            return;
        }

        $services = $data['services'];
        if (!\is_array($services)) {
            $errors[] = 'Services section must be an array';

            return;
        }

        // Check for _defaults section
        if (!isset($services['_defaults'])) {
            $warnings[] = 'Services configuration missing _defaults section';
        }

        // Validate individual service definitions
        foreach ($services as $serviceId => $serviceConfig) {
            if ('_defaults' === $serviceId) {
                continue;
            }

            if (!\is_array($serviceConfig)) {
                $warnings[] = "Service '{$serviceId}' configuration is not an array";
                continue;
            }

            // Check for class definition
            if (!isset($serviceConfig['class']) && !str_contains($serviceId, '\\')) {
                $warnings[] = "Service '{$serviceId}' missing class definition";
            }

            // Check for common configuration issues
            if (isset($serviceConfig['arguments']) && !\is_array($serviceConfig['arguments'])) {
                $errors[] = "Service '{$serviceId}' arguments must be an array";
            }

            if (isset($serviceConfig['tags']) && !\is_array($serviceConfig['tags'])) {
                $errors[] = "Service '{$serviceId}' tags must be an array";
            }
        }
    }

    /**
     * Validate site configuration structure.
     *
     * @param array<string, mixed> $data     Configuration data
     * @param array<string>        $errors   Error array to populate
     * @param array<string>        $warnings Warning array to populate
     */
    private function validateSiteConfiguration(array $data, array &$errors, array &$warnings): void
    {
        $requiredKeys = ['rootPageId', 'base'];
        $recommendedKeys = ['languages', 'routes'];

        // Check required keys
        foreach ($requiredKeys as $key) {
            if (!isset($data[$key])) {
                $errors[] = "Missing required site configuration key: {$key}";
            }
        }

        // Check recommended keys
        foreach ($recommendedKeys as $key) {
            if (!isset($data[$key])) {
                $warnings[] = "Missing recommended site configuration key: {$key}";
            }
        }

        // Validate rootPageId
        if (isset($data['rootPageId'])) {
            if (!\is_int($data['rootPageId']) || $data['rootPageId'] < 1) {
                $errors[] = 'rootPageId must be a positive integer';
            }
        }

        // Validate base URL
        if (isset($data['base'])) {
            if (!\is_string($data['base']) || !filter_var($data['base'], FILTER_VALIDATE_URL)) {
                $errors[] = 'base must be a valid URL';
            }
        }

        // Validate languages configuration
        if (isset($data['languages'])) {
            if (!\is_array($data['languages'])) {
                $errors[] = 'languages must be an array';
            } else {
                foreach ($data['languages'] as $languageId => $languageConfig) {
                    if (!\is_array($languageConfig)) {
                        $errors[] = "Language configuration '{$languageId}' must be an array";
                        continue;
                    }

                    if (!isset($languageConfig['title'])) {
                        $warnings[] = "Language '{$languageId}' missing title";
                    }

                    if (!isset($languageConfig['enabled'])) {
                        $warnings[] = "Language '{$languageId}' missing enabled flag";
                    }
                }
            }
        }
    }

    /**
     * Validate language file structure.
     *
     * @param array<string, mixed> $data     Configuration data
     * @param array<string>        $errors   Error array to populate
     * @param array<string>        $warnings Warning array to populate
     */
    private function validateLanguageFile(array $data, array &$errors, array &$warnings): void
    {
        // Language files should have specific structure
        if (empty($data)) {
            $warnings[] = 'Language file is empty';

            return;
        }

        // Check for common language file patterns
        $hasLanguageKeys = false;
        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            if (str_starts_with($keyStr, 'LLL:') || str_contains($keyStr, '.')) {
                $hasLanguageKeys = true;
                break;
            }
        }

        if (!$hasLanguageKeys) {
            $warnings[] = 'File does not appear to contain language labels';
        }
    }

    /**
     * Validate general YAML structure.
     *
     * @param array<string, mixed> $data       Configuration data
     * @param array<string>        $errors     Error array to populate
     * @param array<string>        $warnings   Warning array to populate
     * @param string               $sourcePath Source path for context
     */
    private function validateYamlStructure(array $data, array &$errors, array &$warnings, string $sourcePath): void
    {
        // Check for extremely deep nesting
        $maxDepth = $this->getOption('max_nesting_depth', 10);
        $actualDepth = $this->getArrayDepth($data);

        if ($actualDepth > $maxDepth) {
            $warnings[] = "YAML structure is deeply nested (depth: {$actualDepth}, max recommended: {$maxDepth})";
        }

        // Check for potential circular references (simplified check)
        $this->checkForCircularReferences($data, $warnings);

        // Check for empty sections
        $this->checkForEmptySections($data, $warnings);
    }

    /**
     * Process TYPO3 placeholders in configuration.
     *
     * @param array<string, mixed> $data Configuration data
     *
     * @return array<string, mixed> Processed data
     */
    private function processTypo3Placeholders(array $data): array
    {
        // This is a simplified placeholder processor
        // In a full implementation, this would handle %env()% and other TYPO3 placeholders
        return $data;
    }

    /**
     * Normalize TYPO3 configuration values.
     *
     * @param array<string, mixed> $data Configuration data
     *
     * @return array<string, mixed> Normalized data
     */
    private function normalizeTypo3Configuration(array $data): array
    {
        // Convert string boolean values to actual booleans where appropriate
        return $this->normalizeBooleansRecursive($data);
    }

    /**
     * Recursively normalize boolean values.
     *
     * @param mixed $data Data to normalize
     *
     * @return mixed Normalized data
     */
    private function normalizeBooleansRecursive($data)
    {
        if (\is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->normalizeBooleansRecursive($value);
            }

            return $result;
        }

        if (\is_string($data)) {
            $lower = strtolower($data);
            if (\in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (\in_array($lower, ['false', '0', 'no', 'off'], true)) {
                return false;
            }
        }

        return $data;
    }

    /**
     * Get maximum array depth.
     *
     * @param array<mixed> $array Array to check
     *
     * @return int Maximum depth
     */
    private function getArrayDepth(array $array): int
    {
        $maxDepth = 0;

        foreach ($array as $value) {
            if (\is_array($value)) {
                $depth = 1 + $this->getArrayDepth($value);
                $maxDepth = max($maxDepth, $depth);
            }
        }

        return $maxDepth;
    }

    /**
     * Check for potential circular references.
     *
     * @param array<string, mixed> $data     Data to check
     * @param array<string>        $warnings Warning array to populate
     */
    private function checkForCircularReferences(array $data, array &$warnings): void
    {
        // Simplified check - look for suspicious self-references
        foreach ($data as $key => $value) {
            if (\is_string($value) && str_contains($value, (string) $key)) {
                $warnings[] = "Potential circular reference detected in key: {$key}";
            }
        }
    }

    /**
     * Check for empty configuration sections.
     *
     * @param array<string, mixed> $data     Data to check
     * @param array<string>        $warnings Warning array to populate
     */
    private function checkForEmptySections(array $data, array &$warnings): void
    {
        foreach ($data as $key => $value) {
            if ([] === $value) {
                $warnings[] = "Empty configuration section found: {$key}";
            }
        }
    }
}
