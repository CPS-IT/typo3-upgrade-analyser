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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingHelperInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingHelperTrait;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingInterface;

/**
 * Represents a single finding from Fractor analysis.
 */
class FractorFinding implements AnalyzerFindingInterface, AnalyzerFindingHelperInterface, \JsonSerializable
{
    use AnalyzerFindingHelperTrait;

    public function __construct(
        private readonly string $filePath,
        private readonly int $lineNumber,
        private readonly string $ruleClass,
        private readonly string $message,
        private readonly FractorRuleSeverity $severity,
        private readonly FractorChangeType $changeType,
        private readonly string $codeBefore,
        private readonly string $codeAfter,
        private readonly string $diff,
        private readonly ?string $documentationUrl = null,
        private readonly array $context = [],
    ) {
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getFile(): string
    {
        return $this->filePath;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function getLine(): int
    {
        return $this->lineNumber;
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

    /**
     * Get severity value as string for generic processing.
     */
    public function getSeverityValue(): string
    {
        return $this->severity->value;
    }

    public function getChangeType(): FractorChangeType
    {
        return $this->changeType;
    }

    public function getCodeBefore(): string
    {
        return $this->codeBefore;
    }

    public function getCodeAfter(): string
    {
        return $this->codeAfter;
    }

    public function getDiff(): string
    {
        return $this->diff;
    }

    public function getDocumentationUrl(): ?string
    {
        return $this->documentationUrl;
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
               || $this->changeType->requiresManualIntervention();
    }

    /**
     * Check if this finding represents deprecated code.
     */
    public function isDeprecation(): bool
    {
        return FractorChangeType::DEPRECATION_REMOVAL === $this->changeType
               || FractorRuleSeverity::WARNING === $this->severity;
    }

    /**
     * Check if this finding is a code improvement.
     */
    public function isImprovement(): bool
    {
        return FractorRuleSeverity::INFO === $this->severity
               || FractorRuleSeverity::SUGGESTION === $this->severity
               || FractorChangeType::MODERNIZATION === $this->changeType;
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
        return basename($this->filePath) . ':' . $this->lineNumber;
    }

    /**
     * Check if this finding has a code diff available.
     */
    public function hasDiff(): bool
    {
        return '' !== $this->diff;
    }

    /**
     * Get the priority score for this finding (higher = more important).
     */
    public function getPriorityScore(): float
    {
        $severityWeight = $this->severity->getRiskWeight();
        $changeTypeWeight = $this->changeType->getPriority() / 10.0;
        $effortPenalty = $this->getEstimatedEffort() / 60; // Convert to hours

        // Combine weights with effort consideration
        return ($severityWeight + $changeTypeWeight) / 2 * (1 / (1 + ($effortPenalty / 4)));
    }

    /**
     * Check if this finding has documentation available.
     */
    public function hasDocumentation(): bool
    {
        return null !== $this->documentationUrl && '' !== $this->documentationUrl;
    }

    /**
     * Check if this finding has both before and after code.
     */
    public function hasCodeChange(): bool
    {
        return '' !== $this->codeBefore && '' !== $this->codeAfter;
    }

    /**
     * Check if this finding has context information.
     */
    public function hasContext(): bool
    {
        return !empty($this->context);
    }

    /**
     * Convert finding to array format for serialization.
     */
    public function toArray(): array
    {
        // Get base data from trait that provides interface-compliant fields
        $baseData = $this->getBaseArrayData();

        // Add Fractor-specific fields
        $baseData['file_path'] = $this->filePath; // Keep original field name for backward compatibility
        $baseData['line_number'] = $this->lineNumber; // Keep original field name for backward compatibility
        $baseData['change_type'] = $this->changeType->value;
        $baseData['code_before'] = $this->codeBefore;
        $baseData['code_after'] = $this->codeAfter;
        $baseData['diff'] = $this->diff;
        $baseData['documentation_url'] = $this->documentationUrl;
        $baseData['context'] = $this->context;
        $baseData['requires_manual_intervention'] = $this->requiresManualIntervention();

        return $baseData;
    }

    /**
     * JsonSerializable implementation - use toArray() for JSON encoding.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * String representation of the finding for template usage.
     */
    public function __toString(): string
    {
        return sprintf(
            '%s:%s - %s',
            $this->filePath,
            $this->lineNumber,
            $this->message
        );
    }
}
