<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception;

/**
 * Base exception for configuration parsing errors.
 *
 * This exception is thrown when configuration files cannot be parsed
 * due to syntax errors, invalid format, or other parsing issues.
 */
class ParseException extends \RuntimeException
{
    private string $sourcePath;
    private string $format;
    private ?int $parseLine;
    private ?int $parseColumn;
    private array $parseContext;

    /**
     * @param string               $message    Error message
     * @param string               $sourcePath Source file path or identifier
     * @param string               $format     Configuration format (php, yaml, etc.)
     * @param int|null             $line       Line number where error occurred
     * @param int|null             $column     Column number where error occurred
     * @param array<string, mixed> $context    Additional error context
     * @param int                  $code       Error code
     * @param \Throwable|null      $previous   Previous exception
     */
    public function __construct(
        string $message,
        string $sourcePath,
        string $format,
        ?int $line = null,
        ?int $column = null,
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $this->sourcePath = $sourcePath;
        $this->format = $format;
        $this->parseLine = $line;
        $this->parseColumn = $column;
        $this->parseContext = $context;

        // Enhance message with location information
        $enhancedMessage = $this->buildEnhancedMessage($message);

        parent::__construct($enhancedMessage, $code, $previous);
    }

    /**
     * Get source path where error occurred.
     *
     * @return string Source file path or identifier
     */
    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    /**
     * Get configuration format.
     *
     * @return string Format identifier
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Get line number where error occurred.
     *
     * @return int|null Line number or null if not available
     */
    public function getParseLine(): ?int
    {
        return $this->parseLine;
    }

    /**
     * Get column number where error occurred.
     *
     * @return int|null Column number or null if not available
     */
    public function getParseColumn(): ?int
    {
        return $this->parseColumn;
    }

    /**
     * Get additional error context.
     *
     * @return array<string, mixed> Context information
     */
    public function getContext(): array
    {
        return $this->parseContext;
    }

    /**
     * Get context value by key.
     *
     * @param string $key     Context key
     * @param mixed  $default Default value
     *
     * @return mixed Context value or default
     */
    public function getContextValue(string $key, $default = null)
    {
        return $this->parseContext[$key] ?? $default;
    }

    /**
     * Check if error has line/column location information.
     *
     * @return bool True if location information is available
     */
    public function hasLocation(): bool
    {
        return null !== $this->parseLine;
    }

    /**
     * Get formatted location string.
     *
     * @return string Location string (e.g., "line 10, column 5") or empty if no location
     */
    public function getLocationString(): string
    {
        if (!$this->hasLocation()) {
            return '';
        }

        $location = "line {$this->parseLine}";
        if (null !== $this->parseColumn) {
            $location .= ", column {$this->parseColumn}";
        }

        return $location;
    }

    /**
     * Create exception for syntax errors.
     *
     * @param string               $message    Error message
     * @param string               $sourcePath Source path
     * @param string               $format     Format
     * @param int|null             $line       Line number
     * @param int|null             $column     Column number
     * @param array<string, mixed> $context    Context
     *
     * @return static
     */
    public static function syntaxError(
        string $message,
        string $sourcePath,
        string $format,
        ?int $line = null,
        ?int $column = null,
        array $context = [],
    ): self {
        return new static($message, $sourcePath, $format, $line, $column, $context);
    }

    /**
     * Create exception for file access errors.
     *
     * @param string      $sourcePath Source path
     * @param string      $format     Format
     * @param string|null $reason     Optional reason
     *
     * @return static
     */
    public static function fileAccessError(string $sourcePath, string $format, ?string $reason = null): self
    {
        $message = "Cannot access configuration file: {$sourcePath}";
        if (null !== $reason) {
            $message .= " ({$reason})";
        }

        return new static($message, $sourcePath, $format);
    }

    /**
     * Create exception for unsupported format.
     *
     * @param string $sourcePath Source path
     * @param string $format     Format
     *
     * @return static
     */
    public static function unsupportedFormat(string $sourcePath, string $format): self
    {
        $message = "Unsupported configuration format: {$format}";

        return new static($message, $sourcePath, $format);
    }

    /**
     * Create exception for invalid structure.
     *
     * @param string               $message    Error message
     * @param string               $sourcePath Source path
     * @param string               $format     Format
     * @param array<string, mixed> $context    Context
     *
     * @return static
     */
    public static function invalidStructure(
        string $message,
        string $sourcePath,
        string $format,
        array $context = [],
    ): self {
        return new static($message, $sourcePath, $format, null, null, $context);
    }

    /**
     * Create exception from another throwable.
     *
     * @param \Throwable $throwable  Source exception
     * @param string     $sourcePath Source path
     * @param string     $format     Format
     *
     * @return static
     */
    public static function fromThrowable(\Throwable $throwable, string $sourcePath, string $format): self
    {
        return new static(
            $throwable->getMessage(),
            $sourcePath,
            $format,
            null,
            null,
            ['original_exception' => \get_class($throwable)],
            $throwable->getCode(),
            $throwable,
        );
    }

    /**
     * Build enhanced error message with location information.
     *
     * @param string $message Original message
     *
     * @return string Enhanced message
     */
    private function buildEnhancedMessage(string $message): string
    {
        $parts = [];

        // Add format information
        $parts[] = ucfirst($this->format) . ' parsing error';

        // Add location if available
        if ($this->hasLocation()) {
            $parts[] = "at {$this->getLocationString()}";
        }

        // Add source path
        $parts[] = 'in ' . basename($this->sourcePath);

        // Combine with original message
        return implode(' ', $parts) . ': ' . $message;
    }
}
