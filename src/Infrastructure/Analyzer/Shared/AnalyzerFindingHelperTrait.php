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
 * Trait providing common helper functionality for analyzer findings.
 *
 * This trait implements common patterns shared across different analyzer types
 * without creating coupling through inheritance. Classes can pick and choose
 * which functionality to include.
 */
trait AnalyzerFindingHelperTrait
{
    /**
     * Get base priority score calculation.
     * Individual analyzers can override for custom priority logic.
     *
     * Note: This trait should only be used by classes implementing AnalyzerFindingInterface
     */
    public function getBasePriorityScore(): float
    {
        // Map severity strings to weights
        $severityWeight = match ($this->getSeverityValue()) {
            'critical' => 1.0,
            'warning' => 0.7,
            'info' => 0.3,
            'suggestion' => 0.1,
            default => 0.5,
        };

        // Apply effort penalty (higher effort = lower priority)
        $effortPenalty = $this->getEstimatedEffort() / 60; // Convert to hours

        // Priority decreases with increasing effort, but never below 10% of severity weight
        return $severityWeight * (0.1 + 0.9 / (1 + ($effortPenalty / 2)));
    }

    /**
     * Get a short description of the file location.
     * Note: This trait should only be used by classes implementing AnalyzerFindingInterface.
     */
    public function getFileLocation(): string
    {
        return basename($this->getFile()) . ':' . $this->getLine();
    }

    /**
     * Check if this finding requires immediate attention.
     * Note: This trait should only be used by classes implementing AnalyzerFindingInterface.
     */
    public function requiresImmediateAction(): bool
    {
        return 'critical' === $this->getSeverityValue() || $this->isBreakingChange();
    }

    /**
     * Get the analyzer type from the rule class namespace.
     * Note: This trait should only be used by classes implementing AnalyzerFindingInterface.
     */
    public function getAnalyzerType(): string
    {
        $ruleClass = $this->getRuleClass();

        // Extract analyzer type from namespace
        if (str_contains($ruleClass, 'Rector')) {
            return 'rector';
        }

        if (str_contains($ruleClass, 'Fractor')) {
            return 'fractor';
        }

        // Generic fallback
        $parts = explode('\\', $ruleClass);
        foreach ($parts as $part) {
            $lower = strtolower($part);
            if (str_ends_with($lower, 'analyzer')) {
                return str_replace('analyzer', '', $lower) ?: 'unknown';
            }
            if (str_ends_with($lower, 'rule')) {
                return str_replace('rule', '', $lower) ?: 'unknown';
            }
        }

        return 'unknown';
    }

    /**
     * Compare findings for sorting by priority (highest first).
     * Note: This trait should only be used by classes implementing AnalyzerFindingInterface.
     */
    public function comparePriority(AnalyzerFindingInterface $other): int
    {
        $thisPriority = $this->getPriorityScore();
        $otherPriority = $other->getPriorityScore();

        // Sort by priority descending (highest first)
        return $otherPriority <=> $thisPriority;
    }

    /**
     * Get severity level as display-friendly string.
     * Note: This trait should only be used by classes implementing AnalyzerFindingInterface.
     */
    public function getSeverityDisplay(): string
    {
        return match ($this->getSeverityValue()) {
            'critical' => 'Critical',
            'warning' => 'Warning',
            'info' => 'Info',
            'suggestion' => 'Suggestion',
            default => ucfirst($this->getSeverityValue()),
        };
    }

    /**
     * Get effort category for display.
     * Note: This trait should only be used by classes implementing AnalyzerFindingInterface.
     */
    public function getEffortCategory(): string
    {
        $effort = $this->getEstimatedEffort();

        return match (true) {
            $effort <= 5 => 'Quick Fix',
            $effort <= 15 => 'Medium Effort',
            $effort <= 30 => 'High Effort',
            default => 'Complex Fix',
        };
    }

    /**
     * Common implementation for basic toArray() structure.
     * Specific analyzers should extend this with their own fields.
     *
     * Note: This trait should only be used by classes implementing AnalyzerFindingInterface
     */
    protected function getBaseArrayData(): array
    {
        $data = [
            // Core interface methods - always available
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'rule_class' => $this->getRuleClass(),
            'rule_name' => $this->getRuleName(),
            'message' => $this->getMessage(),
            'severity' => $this->getSeverityValue(),
            'priority_score' => $this->getPriorityScore(),
            'estimated_effort' => $this->getEstimatedEffort(),

            // Helper methods from trait
            'file_location' => $this->getFileLocation(),
            'severity_display' => $this->getSeverityDisplay(),
            'effort_category' => $this->getEffortCategory(),
            'analyzer_type' => $this->getAnalyzerType(),
            'requires_immediate_action' => $this->requiresImmediateAction(),
        ];

        // Additional interface methods
        $data['is_breaking_change'] = $this->isBreakingChange();
        $data['is_deprecation'] = $this->isDeprecation();
        $data['is_improvement'] = $this->isImprovement();

        return $data;
    }
}
