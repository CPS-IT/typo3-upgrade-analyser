<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Parser;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ParseResult;

/**
 * Interface for TYPO3 configuration file parsers
 * 
 * Defines the contract for parsing various TYPO3 configuration formats:
 * - PHP configuration files (LocalConfiguration.php, AdditionalConfiguration.php)
 * - YAML configuration files (Services.yaml, site configurations)
 * - PackageStates.php files
 * - Extension configuration files (ext_conf_template.txt, ext_localconf.php)
 * 
 * All parsers implement safe parsing without code execution, using AST parsing
 * for PHP files and dedicated parsers for YAML and other formats.
 */
interface ConfigurationParserInterface
{
    /**
     * Parse configuration from file path
     * 
     * @param string $filePath Absolute path to configuration file
     * @return ParseResult Result containing parsed configuration or error details
     */
    public function parseFile(string $filePath): ParseResult;

    /**
     * Parse configuration from string content
     * 
     * @param string $content Configuration file content
     * @param string $sourcePath Optional source path for error reporting
     * @return ParseResult Result containing parsed configuration or error details
     */
    public function parseContent(string $content, string $sourcePath = '<string>'): ParseResult;

    /**
     * Check if this parser can handle the given file
     * 
     * @param string $filePath Path to check
     * @return bool True if this parser can handle the file
     */
    public function supports(string $filePath): bool;

    /**
     * Get the configuration format this parser handles
     * 
     * @return string Format identifier (e.g., 'php', 'yaml', 'packagestates')
     */
    public function getFormat(): string;

    /**
     * Get human-readable name of this parser
     * 
     * @return string Parser name
     */
    public function getName(): string;

    /**
     * Get file extensions this parser supports
     * 
     * @return array<string> Array of file extensions (without dots)
     */
    public function getSupportedExtensions(): array;

    /**
     * Get parser priority for format resolution
     * 
     * Higher priority parsers are tried first when multiple parsers
     * support the same file extension.
     * 
     * @return int Priority value (higher = higher priority)
     */
    public function getPriority(): int;

    /**
     * Check if parser requires specific dependencies
     * 
     * @return array<string> Array of required class names or packages
     */
    public function getRequiredDependencies(): array;

    /**
     * Validate parser is ready to use
     * 
     * Checks if all required dependencies are available and parser
     * is properly configured.
     * 
     * @return bool True if parser is ready
     * @throws \RuntimeException If parser cannot be used
     */
    public function isReady(): bool;

    /**
     * Get parser-specific configuration options
     * 
     * @return array<string, mixed> Configuration options
     */
    public function getParserOptions(): array;

    /**
     * Set parser-specific configuration options
     * 
     * @param array<string, mixed> $options Configuration options
     * @return void
     */
    public function setParserOptions(array $options): void;
}