<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingHelperInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingHelperTrait;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingInterface;

/**
 * Represents a single finding from Rector analysis.
 */
class RectorFinding implements AnalyzerFindingInterface, AnalyzerFindingHelperInterface
{
    use AnalyzerFindingHelperTrait;

    public function __construct(
        private readonly string $file,
        private readonly int $line,
        private readonly string $ruleClass,
        private readonly string $message,
        private readonly RectorRuleSeverity $severity,
        private readonly RectorChangeType $changeType,
        private readonly ?string $suggestedFix = null,
        private readonly ?string $oldCode = null,
        private readonly ?string $newCode = null,
        private readonly array $context = [],
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

    public function getSeverity(): RectorRuleSeverity
    {
        return $this->severity;
    }

    /**
     * Get severity value as string for generic processing.
     */
    public function getSeverityValue(): string
    {
        return $this->severity->value;
    }

    public function getChangeType(): RectorChangeType
    {
        return $this->changeType;
    }

    public function getSuggestedFix(): ?string
    {
        return $this->suggestedFix;
    }

    public function getOldCode(): ?string
    {
        return $this->oldCode;
    }

    public function getNewCode(): ?string
    {
        return $this->newCode;
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
        return RectorRuleSeverity::CRITICAL === $this->severity
               || RectorChangeType::BREAKING_CHANGE === $this->changeType;
    }

    /**
     * Check if this finding represents deprecated code.
     */
    public function isDeprecation(): bool
    {
        return RectorChangeType::DEPRECATION === $this->changeType
               || RectorRuleSeverity::WARNING === $this->severity;
    }

    /**
     * Check if this finding is a code improvement.
     */
    public function isImprovement(): bool
    {
        return RectorRuleSeverity::INFO === $this->severity
               || RectorRuleSeverity::SUGGESTION === $this->severity;
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
        return null !== $this->oldCode && null !== $this->newCode;
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
        return null !== $this->oldCode && null !== $this->newCode;
    }

    /**
     * Check if this finding has context information.
     */
    public function hasContext(): bool
    {
        return !empty($this->context);
    }

    /**
     * Check if this finding has documentation available.
     */
    public function hasDocumentation(): bool
    {
        // Rector findings typically don't have direct documentation URLs
        // but may have suggested fixes that serve as documentation
        return $this->hasSuggestedFix();
    }

    /**
     * Convert finding to array format.
     */
    public function toArray(): array
    {
        // Get base data from trait that provides interface-compliant fields
        $baseData = $this->getBaseArrayData();

        // Add Rector-specific fields
        $baseData['change_type'] = $this->changeType->value;
        $baseData['suggested_fix'] = $this->suggestedFix;
        $baseData['old_code'] = $this->oldCode;
        $baseData['new_code'] = $this->newCode;
        $baseData['context'] = $this->context;

        return $baseData;
    }
}
