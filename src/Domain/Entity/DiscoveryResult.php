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
 * Represents the result of installation and extension discovery.
 */
class DiscoveryResult implements ResultInterface
{
    private \DateTimeImmutable $timestamp;
    private ?string $error = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $type,
        private readonly string $id,
        private readonly string $name,
        private array $data = [],
    ) {
        $this->timestamp = new \DateTimeImmutable();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isSuccessful(): bool
    {
        return $this->error === null;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(string $error): void
    {
        $this->error = $error;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getValue(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function hasValue(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function setValue(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function getSummary(): string
    {
        if (!$this->isSuccessful()) {
            return "Discovery failed: {$this->error}";
        }

        return match ($this->type) {
            'installation' => $this->getInstallationSummary(),
            'extensions' => $this->getExtensionsSummary(),
            default => "Discovery completed: {$this->name}",
        };
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'name' => $this->name,
            'successful' => $this->isSuccessful(),
            'error' => $this->error,
            'data' => $this->data,
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
            'summary' => $this->getSummary(),
        ];
    }

    private function getInstallationSummary(): string
    {
        $version = $this->getValue('version');
        $type = $this->getValue('type');

        return "TYPO3 {$version} ({$type}) installation discovered";
    }

    private function getExtensionsSummary(): string
    {
        $count = $this->getValue('count') ?? 0;
        $methods = $this->getValue('successful_methods') ?? [];

        $methodsStr = implode(', ', $methods);

        return "{$count} extensions discovered via {$methodsStr}";
    }
}