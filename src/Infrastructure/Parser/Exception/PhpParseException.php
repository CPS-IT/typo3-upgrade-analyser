<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception;

/**
 * Exception for PHP configuration parsing errors.
 *
 * Specialized exception for PHP configuration files like LocalConfiguration.php
 * and AdditionalConfiguration.php that provides PHP-specific error information.
 */
final class PhpParseException extends ParseException
{
    private array $astErrors;
    private string $phpVersion;

    /**
     * @param string               $message    Error message
     * @param string               $sourcePath Source file path
     * @param array<string>        $astErrors  AST parser errors
     * @param string|null          $phpVersion PHP version used for parsing
     * @param int|null             $line       Line number
     * @param int|null             $column     Column number
     * @param array<string, mixed> $context    Additional context
     * @param int                  $code       Error code
     * @param \Throwable|null      $previous   Previous exception
     */
    public function __construct(
        string $message,
        string $sourcePath,
        array $astErrors = [],
        ?string $phpVersion = null,
        ?int $line = null,
        ?int $column = null,
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $this->astErrors = $astErrors;
        $this->phpVersion = $phpVersion ?? PHP_VERSION;

        parent::__construct($message, $sourcePath, 'php', $line, $column, $context, $code, $previous);
    }

    /**
     * Get AST parser errors.
     *
     * @return array<string> AST error messages
     */
    public function getAstErrors(): array
    {
        return $this->astErrors;
    }

    /**
     * Get PHP version used for parsing.
     *
     * @return string PHP version
     */
    public function getPhpVersion(): string
    {
        return $this->phpVersion;
    }

    /**
     * Check if error has AST-specific errors.
     *
     * @return bool True if AST errors are present
     */
    public function hasAstErrors(): bool
    {
        return !empty($this->astErrors);
    }

    /**
     * Get first AST error.
     *
     * @return string|null First AST error or null if none
     */
    public function getFirstAstError(): ?string
    {
        return $this->astErrors[0] ?? null;
    }

    /**
     * Create exception for syntax errors from AST parser.
     *
     * @param array<string> $astErrors  AST parser errors
     * @param string        $sourcePath Source file path
     * @param int|null      $line       Line number
     * @param int|null      $column     Column number
     */
    public static function fromAstErrors(
        array $astErrors,
        string $sourcePath,
        ?int $line = null,
        ?int $column = null,
    ): self {
        $message = 'PHP syntax error';
        if (!empty($astErrors)) {
            $message = $astErrors[0];
        }

        return new self($message, $sourcePath, $astErrors, null, $line, $column);
    }

    /**
     * Create exception for unsupported PHP constructs.
     *
     * @param string   $construct  Unsupported construct
     * @param string   $sourcePath Source file path
     * @param int|null $line       Line number
     */
    public static function unsupportedConstruct(string $construct, string $sourcePath, ?int $line = null): self
    {
        $message = "Unsupported PHP construct: {$construct}";
        $context = ['construct' => $construct];

        return new self($message, $sourcePath, [], null, $line, null, $context);
    }

    /**
     * Create exception for invalid configuration structure.
     *
     * @param string               $expectedStructure Expected structure description
     * @param string               $sourcePath        Source file path
     * @param array<string, mixed> $context           Additional context
     */
    public static function invalidConfigurationStructure(
        string $expectedStructure,
        string $sourcePath,
        array $context = [],
    ): self {
        $message = "Invalid configuration structure, expected: {$expectedStructure}";

        return new self($message, $sourcePath, [], null, null, null, $context);
    }

    /**
     * Create exception for missing required configuration keys.
     *
     * @param array<string> $missingKeys Missing configuration keys
     * @param string        $sourcePath  Source file path
     */
    public static function missingRequiredKeys(array $missingKeys, string $sourcePath): self
    {
        $keyList = implode(', ', $missingKeys);
        $message = "Missing required configuration keys: {$keyList}";
        $context = ['missing_keys' => $missingKeys];

        return new self($message, $sourcePath, [], null, null, null, $context);
    }
}
