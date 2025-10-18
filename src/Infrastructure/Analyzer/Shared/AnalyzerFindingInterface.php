<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared;

/**
 * Generic interface for analyzer findings.
 *
 * This interface provides a common contract for all analyzer finding types
 * (Rector, Fractor, etc.) to enable consistent processing and template rendering.
 */
interface AnalyzerFindingInterface
{
    /**
     * Get the file path where this finding was discovered.
     */
    public function getFile(): string;

    /**
     * Get the line number where this finding occurs.
     */
    public function getLine(): int;

    /**
     * Get the human-readable message describing this finding.
     */
    public function getMessage(): string;

    /**
     * Get the severity level as a string (critical, warning, info, suggestion).
     */
    public function getSeverityValue(): string;

    /**
     * Get the priority score for this finding (higher = more important).
     * Used for sorting and prioritization in reports.
     */
    public function getPriorityScore(): float;

    /**
     * Get the rule or analyzer class that generated this finding.
     */
    public function getRuleClass(): string;

    /**
     * Get a human-readable name for the rule.
     */
    public function getRuleName(): string;

    /**
     * Get estimated effort in minutes to fix this finding.
     */
    public function getEstimatedEffort(): int;

    /**
     * Check if this finding represents a breaking change.
     */
    public function isBreakingChange(): bool;

    /**
     * Check if this finding represents deprecated code.
     */
    public function isDeprecation(): bool;

    /**
     * Check if this finding is a code improvement suggestion.
     */
    public function isImprovement(): bool;

    /**
     * Convert finding to array format for serialization and templating.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
