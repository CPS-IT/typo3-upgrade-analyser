<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

/**
 * Represents a validation issue found during installation validation
 */
final class ValidationIssue
{
    /**
     * @param string $ruleName Name of the validation rule that detected this issue
     * @param ValidationSeverity $severity Severity level of the issue
     * @param string $message Human-readable description of the issue
     * @param string $category Category of the validation rule
     * @param array<string, mixed> $context Additional context information
     * @param array<string> $affectedPaths File paths affected by this issue
     * @param array<string> $recommendations Suggested actions to resolve the issue
     */
    public function __construct(
        private readonly string $ruleName,
        private readonly ValidationSeverity $severity,
        private readonly string $message,
        private readonly string $category,
        private readonly array $context = [],
        private readonly array $affectedPaths = [],
        private readonly array $recommendations = []
    ) {
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function getSeverity(): ValidationSeverity
    {
        return $this->severity;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getContextValue(string $key): mixed
    {
        return $this->context[$key] ?? null;
    }

    public function getAffectedPaths(): array
    {
        return $this->affectedPaths;
    }

    public function getRecommendations(): array
    {
        return $this->recommendations;
    }

    public function isBlockingAnalysis(): bool
    {
        return $this->severity->isBlockingAnalysis();
    }

    public function toArray(): array
    {
        return [
            'rule_name' => $this->ruleName,
            'severity' => $this->severity->value,
            'severity_display' => $this->severity->getDisplayName(),
            'message' => $this->message,
            'category' => $this->category,
            'context' => $this->context,
            'affected_paths' => $this->affectedPaths,
            'recommendations' => $this->recommendations,
            'blocking_analysis' => $this->isBlockingAnalysis(),
        ];
    }

    public function withAdditionalContext(array $context): self
    {
        return new self(
            $this->ruleName,
            $this->severity,
            $this->message,
            $this->category,
            array_merge($this->context, $context),
            $this->affectedPaths,
            $this->recommendations
        );
    }

    public function withAdditionalRecommendations(array $recommendations): self
    {
        return new self(
            $this->ruleName,
            $this->severity,
            $this->message,
            $this->category,
            $this->context,
            $this->affectedPaths,
            array_merge($this->recommendations, $recommendations)
        );
    }
}