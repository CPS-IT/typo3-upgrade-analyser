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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ParseResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception\ParseException;
use Psr\Log\LoggerInterface;

/**
 * Abstract base class for configuration parsers.
 *
 * Provides common functionality for all configuration parsers including
 * file handling, error handling, logging, and configuration management.
 */
abstract class AbstractConfigurationParser implements ConfigurationParserInterface
{
    protected array $parserOptions = [];

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function parseFile(string $filePath): ParseResult
    {
        $this->logger->debug('Starting configuration file parsing', [
            'file_path' => $filePath,
            'parser' => $this->getName(),
            'format' => $this->getFormat(),
        ]);

        // Validate file accessibility
        if (!$this->isFileAccessible($filePath)) {
            $error = "Configuration file not accessible: {$filePath}";
            $this->logger->error($error);

            return ParseResult::failure(
                [$error],
                $this->getFormat(),
                $filePath,
                [],
                ['parser' => $this->getName()],
            );
        }

        // Check parser support
        if (!$this->supports($filePath)) {
            $error = "Parser does not support file: {$filePath}";
            $this->logger->warning($error);

            return ParseResult::failure(
                [$error],
                $this->getFormat(),
                $filePath,
                [],
                ['parser' => $this->getName()],
            );
        }

        try {
            // Read file content
            $content = $this->readFileContent($filePath);

            // Parse content
            $result = $this->parseContent($content, $filePath);

            $this->logger->info('Configuration file parsed successfully', [
                'file_path' => $filePath,
                'parser' => $this->getName(),
                'data_keys' => $result->isSuccessful() ? \count($result->getData()) : 0,
                'warnings' => \count($result->getWarnings()),
            ]);

            return $result;
        } catch (ParseException $e) {
            $this->logger->error('Configuration parsing failed', [
                'file_path' => $filePath,
                'parser' => $this->getName(),
                'error' => $e->getMessage(),
                'line' => $e->getParseLine(),
                'column' => $e->getParseColumn(),
            ]);

            return ParseResult::failure(
                [$e->getMessage()],
                $this->getFormat(),
                $filePath,
                [],
                [
                    'parser' => $this->getName(),
                    'exception' => \get_class($e),
                    'line' => $e->getParseLine(),
                    'column' => $e->getParseColumn(),
                ],
            );
        } catch (\Throwable $e) {
            $error = "Unexpected error during parsing: {$e->getMessage()}";
            $this->logger->error($error, [
                'file_path' => $filePath,
                'parser' => $this->getName(),
                'exception' => \get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ParseResult::failure(
                [$error],
                $this->getFormat(),
                $filePath,
                [],
                [
                    'parser' => $this->getName(),
                    'exception' => \get_class($e),
                ],
            );
        }
    }

    public function parseContent(string $content, string $sourcePath = '<string>'): ParseResult
    {
        $this->logger->debug('Starting content parsing', [
            'source_path' => $sourcePath,
            'parser' => $this->getName(),
            'content_length' => \strlen($content),
        ]);

        try {
            // Validate content is not empty
            if ('' === trim($content)) {
                $warning = 'Configuration content is empty';
                $this->logger->warning($warning, ['source_path' => $sourcePath]);

                return ParseResult::success(
                    [],
                    $this->getFormat(),
                    $sourcePath,
                    [$warning],
                    ['parser' => $this->getName(), 'content_length' => 0],
                );
            }

            // Perform format-specific parsing
            $parseData = $this->doParse($content, $sourcePath);

            // Validate parsed data
            $validationResult = $this->validateParsedData($parseData, $sourcePath);
            if (!$validationResult['valid']) {
                return ParseResult::failure(
                    $validationResult['errors'],
                    $this->getFormat(),
                    $sourcePath,
                    $validationResult['warnings'],
                    ['parser' => $this->getName()],
                );
            }

            // Apply post-processing if needed
            $processedData = $this->postProcessData($parseData, $sourcePath);

            $metadata = [
                'parser' => $this->getName(),
                'content_length' => \strlen($content),
                'data_keys' => \count($processedData),
                'has_nested_data' => $this->hasNestedArrays($processedData),
            ];

            return ParseResult::success(
                $processedData,
                $this->getFormat(),
                $sourcePath,
                $validationResult['warnings'],
                $metadata,
            );
        } catch (ParseException $e) {
            throw $e; // Re-throw parse exceptions as-is
        } catch (\Throwable $e) {
            throw ParseException::fromThrowable($e, $sourcePath, $this->getFormat());
        }
    }

    public function supports(string $filePath): bool
    {
        // Check file extension
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!\in_array($extension, $this->getSupportedExtensions(), true)) {
            return false;
        }

        // Additional format-specific checks
        return $this->supportsSpecific($filePath);
    }

    public function getPriority(): int
    {
        return 50; // Default medium priority
    }

    public function getRequiredDependencies(): array
    {
        return []; // No dependencies by default
    }

    public function isReady(): bool
    {
        $dependencies = $this->getRequiredDependencies();

        foreach ($dependencies as $dependency) {
            if (class_exists($dependency) || interface_exists($dependency)) {
                continue;
            }

            // Check if it's a package name
            if (false !== strpos($dependency, '/')) {
                // This would need actual package detection logic
                // For now, assume package dependencies are met
                continue;
            }

            throw new \RuntimeException("Required dependency not available: {$dependency}");
        }

        return true;
    }

    public function getParserOptions(): array
    {
        return $this->parserOptions;
    }

    public function setParserOptions(array $options): void
    {
        $this->parserOptions = array_merge($this->parserOptions, $options);
    }

    /**
     * Get parser option value.
     *
     * @param string $key     Option key
     * @param mixed  $default Default value
     *
     * @return mixed Option value or default
     */
    protected function getOption(string $key, $default = null)
    {
        return $this->parserOptions[$key] ?? $default;
    }

    /**
     * Set parser option value.
     *
     * @param string $key   Option key
     * @param mixed  $value Option value
     */
    protected function setOption(string $key, $value): void
    {
        $this->parserOptions[$key] = $value;
    }

    /**
     * Check if file is accessible for reading.
     *
     * @param string $filePath File path to check
     *
     * @return bool True if file is accessible
     */
    protected function isFileAccessible(string $filePath): bool
    {
        return file_exists($filePath) && is_readable($filePath) && is_file($filePath);
    }

    /**
     * Read file content safely.
     *
     * @param string $filePath File path
     *
     * @throws ParseException If file cannot be read
     *
     * @return string File content
     */
    protected function readFileContent(string $filePath): string
    {
        $content = @file_get_contents($filePath);

        if (false === $content) {
            throw ParseException::fileAccessError($filePath, $this->getFormat(), error_get_last()['message'] ?? null);
        }

        return $content;
    }

    /**
     * Validate parsed data structure.
     *
     * @param array<string, mixed> $data       Parsed data
     * @param string               $sourcePath Source path for error reporting
     *
     * @return array{valid: bool, errors: array<string>, warnings: array<string>} Validation result
     */
    protected function validateParsedData(array $data, string $sourcePath): array
    {
        $errors = [];
        $warnings = [];

        // Subclasses can override this for format-specific validation
        // Base implementation considers all data valid

        return [
            'valid' => true, // Base implementation has no validation rules
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Post-process parsed data.
     *
     * @param array<string, mixed> $data       Parsed data
     * @param string               $sourcePath Source path
     *
     * @return array<string, mixed> Processed data
     */
    protected function postProcessData(array $data, string $sourcePath): array
    {
        // Subclasses can override this for format-specific post-processing
        return $data;
    }

    /**
     * Check if data contains nested arrays.
     *
     * @param array<string, mixed> $data Data to check
     *
     * @return bool True if nested arrays are present
     */
    protected function hasNestedArrays(array $data): bool
    {
        foreach ($data as $value) {
            if (\is_array($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Perform format-specific parsing.
     *
     * This method must be implemented by subclasses to handle the actual
     * parsing logic for their specific format.
     *
     * @param string $content    Content to parse
     * @param string $sourcePath Source path for error reporting
     *
     * @throws ParseException If parsing fails
     *
     * @return array<string, mixed> Parsed data
     */
    abstract protected function doParse(string $content, string $sourcePath): array;

    /**
     * Perform format-specific support checks.
     *
     * Subclasses can override this to add additional support validation
     * beyond file extension checking.
     *
     * @param string $filePath File path to check
     *
     * @return bool True if specifically supported
     */
    protected function supportsSpecific(string $filePath): bool
    {
        return true; // Default: support all files with correct extension
    }
}
