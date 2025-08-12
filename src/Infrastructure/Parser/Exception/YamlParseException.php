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
 * Exception for YAML configuration parsing errors.
 *
 * Specialized exception for YAML configuration files like Services.yaml
 * and site configuration files that provides YAML-specific error information.
 */
final class YamlParseException extends ParseException
{
    private ?string $yamlSnippet;
    private ?string $problemMark;

    /**
     * @param string               $message     Error message
     * @param string               $sourcePath  Source file path
     * @param int|null             $line        Line number
     * @param int|null             $column      Column number
     * @param string|null          $yamlSnippet YAML snippet around error
     * @param string|null          $problemMark Problem mark from YAML parser
     * @param array<string, mixed> $context     Additional context
     * @param int                  $code        Error code
     * @param \Throwable|null      $previous    Previous exception
     */
    public function __construct(
        string $message,
        string $sourcePath,
        ?int $line = null,
        ?int $column = null,
        ?string $yamlSnippet = null,
        ?string $problemMark = null,
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $this->yamlSnippet = $yamlSnippet;
        $this->problemMark = $problemMark;

        parent::__construct($message, $sourcePath, 'yaml', $line, $column, $context, $code, $previous);
    }

    /**
     * Get YAML snippet around the error.
     *
     * @return string|null YAML snippet or null if not available
     */
    public function getYamlSnippet(): ?string
    {
        return $this->yamlSnippet;
    }

    /**
     * Get problem mark from YAML parser.
     *
     * @return string|null Problem mark or null if not available
     */
    public function getProblemMark(): ?string
    {
        return $this->problemMark;
    }

    /**
     * Check if error has YAML snippet.
     *
     * @return bool True if snippet is available
     */
    public function hasYamlSnippet(): bool
    {
        return null !== $this->yamlSnippet;
    }

    /**
     * Create exception from Symfony YAML parser exception.
     *
     * @param \Throwable $yamlException Symfony YAML exception
     * @param string     $sourcePath    Source file path
     */
    public static function fromSymfonyYamlException(\Throwable $yamlException, string $sourcePath): self
    {
        // Extract line number from Symfony YAML exception message if available
        $line = null;
        $column = null;
        $snippet = null;
        $problemMark = null;

        // Try to extract line information from exception message
        if (preg_match('/at line (\d+)/', $yamlException->getMessage(), $matches)) {
            $line = (int) $matches[1];
        }

        // For Symfony\Component\Yaml\Exception\ParseException
        if (method_exists($yamlException, 'getParsedLine')) {
            $line = $yamlException->getParsedLine();
        }

        if (method_exists($yamlException, 'getSnippet')) {
            $snippet = $yamlException->getSnippet();
        }

        return new self(
            $yamlException->getMessage(),
            $sourcePath,
            $line,
            $column,
            $snippet,
            $problemMark,
            ['original_exception' => \get_class($yamlException)],
            $yamlException->getCode(),
            $yamlException,
        );
    }

    /**
     * Create exception for indentation errors.
     *
     * @param string $sourcePath     Source file path
     * @param int    $line           Line number
     * @param int    $expectedIndent Expected indentation level
     * @param int    $actualIndent   Actual indentation level
     */
    public static function indentationError(
        string $sourcePath,
        int $line,
        int $expectedIndent,
        int $actualIndent,
    ): self {
        $message = "Indentation error: expected {$expectedIndent} spaces, got {$actualIndent}";
        $context = [
            'expected_indent' => $expectedIndent,
            'actual_indent' => $actualIndent,
        ];

        return new self($message, $sourcePath, $line, null, null, null, $context);
    }

    /**
     * Create exception for invalid YAML structure.
     *
     * @param string      $message    Error message
     * @param string      $sourcePath Source file path
     * @param int|null    $line       Line number
     * @param string|null $snippet    YAML snippet
     */
    public static function invalidYamlStructure(
        string $message,
        string $sourcePath,
        ?int $line = null,
        ?string $snippet = null,
    ): self {
        return new self($message, $sourcePath, $line, null, $snippet);
    }

    /**
     * Create exception for unsupported YAML features.
     *
     * @param string   $feature    Unsupported feature
     * @param string   $sourcePath Source file path
     * @param int|null $line       Line number
     */
    public static function unsupportedFeature(string $feature, string $sourcePath, ?int $line = null): self
    {
        $message = "Unsupported YAML feature: {$feature}";
        $context = ['feature' => $feature];

        return new self($message, $sourcePath, $line, null, null, null, $context);
    }

    /**
     * Create exception for duplicate keys.
     *
     * @param string   $key        Duplicate key
     * @param string   $sourcePath Source file path
     * @param int|null $line       Line number
     */
    public static function duplicateKey(string $key, string $sourcePath, ?int $line = null): self
    {
        $message = "Duplicate key found: {$key}";
        $context = ['duplicate_key' => $key];

        return new self($message, $sourcePath, $line, null, null, null, $context);
    }
}
