<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Domain\Entity;

use CPSIT\UpgradeAnalyzer\Domain\Contract\ResultInterface;

/**
 * Represents the result of an analysis performed on an extension.
 */
class AnalysisResult implements ResultInterface
{
    private array $metrics = [];
    private float $riskScore = 0.0;
    private array $recommendations = [];
    private ?\DateTimeImmutable $executedAt = null;
    private ?string $error = null;

    public function __construct(
        private readonly string $analyzerName,
        private readonly Extension $extension,
    ) {
        $this->executedAt = new \DateTimeImmutable();
    }

    public function getAnalyzerName(): string
    {
        return $this->analyzerName;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function addMetric(string $name, mixed $value): void
    {
        $this->metrics[$name] = $value;
    }

    public function getMetric(string $name): mixed
    {
        return $this->metrics[$name] ?? null;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function hasMetric(string $name): bool
    {
        return \array_key_exists($name, $this->metrics);
    }

    public function setRiskScore(float $score): void
    {
        if ($score < 0.0 || $score > 10.0) {
            throw new \InvalidArgumentException('Risk score must be between 0.0 and 10.0');
        }
        $this->riskScore = $score;
    }

    public function getRiskScore(): float
    {
        return $this->riskScore;
    }

    public function getRiskLevel(): string
    {
        return match (true) {
            $this->riskScore <= 2.0 => 'low',
            $this->riskScore <= 5.0 => 'medium',
            $this->riskScore <= 8.0 => 'high',
            default => 'critical'
        };
    }

    public function addRecommendation(string $recommendation): void
    {
        $this->recommendations[] = $recommendation;
    }

    public function getRecommendations(): array
    {
        return $this->recommendations;
    }

    public function getExecutedAt(): ?\DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function setError(string $error): void
    {
        $this->error = $error;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function hasError(): bool
    {
        return null !== $this->error;
    }

    public function isSuccessful(): bool
    {
        return null === $this->error;
    }

    public function getType(): string
    {
        return 'analysis';
    }

    public function getId(): string
    {
        return $this->analyzerName . '_' . $this->extension->getKey();
    }

    public function getName(): string
    {
        return $this->analyzerName . ' analysis for ' . $this->extension->getKey();
    }

    public function getData(): array
    {
        return [
            'analyzer_name' => $this->analyzerName,
            'extension_key' => $this->extension->getKey(),
            'metrics' => $this->metrics,
            'risk_score' => $this->riskScore,
            'risk_level' => $this->getRiskLevel(),
            'recommendations' => $this->recommendations,
        ];
    }

    public function getValue(string $key): mixed
    {
        return match ($key) {
            'analyzer_name' => $this->analyzerName,
            'extension_key' => $this->extension->getKey(),
            'metrics' => $this->metrics,
            'risk_score' => $this->riskScore,
            'risk_level' => $this->getRiskLevel(),
            'recommendations' => $this->recommendations,
            default => $this->getMetric($key),
        };
    }

    public function hasValue(string $key): bool
    {
        return match ($key) {
            'analyzer_name', 'extension_key', 'metrics', 'risk_score', 'risk_level', 'recommendations' => true,
            default => $this->hasMetric($key),
        };
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->executedAt ?? new \DateTimeImmutable();
    }

    public function getSummary(): string
    {
        if (!$this->isSuccessful()) {
            return "Analysis failed: {$this->error}";
        }

        $riskLevel = $this->getRiskLevel();
        $extensionKey = $this->extension->getKey();

        return "Extension {$extensionKey}: {$riskLevel} risk (score: {$this->riskScore})";
    }

    public function toArray(): array
    {
        return [
            'type' => $this->getType(),
            'id' => $this->getId(),
            'name' => $this->getName(),
            'analyzer_name' => $this->analyzerName,
            'extension_key' => $this->extension->getKey(),
            'metrics' => $this->metrics,
            'risk_score' => $this->riskScore,
            'risk_level' => $this->getRiskLevel(),
            'recommendations' => $this->recommendations,
            'executed_at' => $this->executedAt?->format(\DateTime::ATOM),
            'timestamp' => $this->getTimestamp()->format(\DateTimeInterface::ATOM),
            'error' => $this->error,
            'successful' => $this->isSuccessful(),
            'summary' => $this->getSummary(),
        ];
    }
}
