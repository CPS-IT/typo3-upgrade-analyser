<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor;

/**
 * Represents a single finding from Fractor analysis.
 */
readonly class FractorFinding
{
    public function __construct(
        private string $file,
        private int $line,
        private string $ruleClass,
        private string $message,
        private FractorRuleSeverity $severity,
        private FractorChangeType $changeType,
        private ?string $suggestedFix = null,
        private ?string $diff = null,
        private array $context = [],
    ) {
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getRuleClass(): string
    {
        return $this->ruleClass;
    }

    public function getRuleName(): string
    {
        // Extract class name from full class path
        return basename(str_replace('\\', '/', $this->ruleClass));
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getSeverity(): FractorRuleSeverity
    {
        return $this->severity;
    }

    public function getChangeType(): FractorChangeType
    {
        return $this->changeType;
    }

    public function getSuggestedFix(): ?string
    {
        return $this->suggestedFix;
    }

    public function getDiff(): ?string
    {
        return $this->diff;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Check if this finding represents a breaking change.
     */
    public function isBreakingChange(): bool
    {
        return FractorRuleSeverity::CRITICAL === $this->severity
               || FractorChangeType::BREAKING_CHANGE === $this->changeType;
    }

    /**
     * Check if this finding represents deprecated code.
     */
    public function isDeprecation(): bool
    {
        return FractorChangeType::DEPRECATION === $this->changeType
               || FractorRuleSeverity::WARNING === $this->severity;
    }

    /**
     * Check if this finding is a code improvement.
     */
    public function isImprovement(): bool
    {
        return FractorRuleSeverity::INFO === $this->severity
               || FractorRuleSeverity::SUGGESTION === $this->severity;
    }

    /**
     * Get estimated effort in minutes to fix this finding.
     */
    public function getEstimatedEffort(): int
    {
        return $this->changeType->getEstimatedEffort();
    }

    /**
     * Check if this finding requires manual intervention.
     */
    public function requiresManualIntervention(): bool
    {
        return $this->changeType->requiresManualIntervention();
    }

    /**
     * Get a short description of the file location.
     */
    public function getFileLocation(): string
    {
        return basename($this->file) . ':' . $this->line;
    }

    /**
     * Check if this finding has a code diff available.
     */
    public function hasDiff(): bool
    {
        return null !== $this->diff;
    }

    /**
     * Get the priority score for this finding (higher = more important).
     */
    public function getPriorityScore(): float
    {
        $severityWeight = $this->severity->getRiskWeight();
        $effortPenalty = $this->getEstimatedEffort() / 60; // Convert to hours

        // Critical issues get highest priority, but extremely time-consuming ones get lower priority
        return $severityWeight * (1 / (1 + ($effortPenalty / 2)));
    }

    /**
     * Check if this finding has a suggested fix.
     */
    public function hasSuggestedFix(): bool
    {
        return null !== $this->suggestedFix && '' !== $this->suggestedFix;
    }

    /**
     * Check if this finding has both old and new code.
     */
    public function hasCodeChange(): bool
    {
        return null !== $this->diff;
    }

    /**
     * Check if this finding has context information.
     */
    public function hasContext(): bool
    {
        return !empty($this->context);
    }

    /**
     * Convert finding to array format.
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'rule_class' => $this->ruleClass,
            'rule_name' => $this->getRuleName(),
            'message' => $this->message,
            'severity' => $this->severity->value,
            'change_type' => $this->changeType->value,
            'suggested_fix' => $this->suggestedFix,
            'diff' => $this->diff,
            'context' => $this->context,
            'priority_score' => $this->getPriorityScore(),
            'estimated_effort' => $this->getEstimatedEffort(),
        ];
    }
}
