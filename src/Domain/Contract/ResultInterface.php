<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Domain\Contract;

/**
 * Common interface for all results in the TYPO3 Upgrade Analyzer.
 *
 * This interface provides a unified structure for discovery results,
 * analysis results, and reporting results, allowing the report service
 * to handle different types of results consistently.
 */
interface ResultInterface
{
    /**
     * Get the result type (e.g., 'discovery', 'analysis', 'reporting').
     */
    public function getType(): string;

    /**
     * Get the unique identifier for this result.
     */
    public function getId(): string;

    /**
     * Get the name or title of this result.
     */
    public function getName(): string;

    /**
     * Get whether this result represents a successful operation.
     */
    public function isSuccessful(): bool;

    /**
     * Get error message if the operation failed.
     */
    public function getError(): ?string;

    /**
     * Get all data associated with this result.
     *
     * @return array<string, mixed>
     */
    public function getData(): array;

    /**
     * Get a specific data value by key.
     */
    public function getValue(string $key): mixed;

    /**
     * Check if a specific data key exists.
     */
    public function hasValue(string $key): bool;

    /**
     * Get timestamp when this result was created.
     */
    public function getTimestamp(): \DateTimeImmutable;

    /**
     * Get human-readable summary of this result.
     */
    public function getSummary(): string;

    /**
     * Convert result to array format for templates.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
