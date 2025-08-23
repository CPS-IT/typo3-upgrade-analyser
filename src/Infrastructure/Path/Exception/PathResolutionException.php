<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;

/**
 * Base exception for all path resolution operations.
 * Provides context preservation and recovery strategy support.
 */
abstract class PathResolutionException extends \RuntimeException
{
    protected ?PathResolutionRequest $request = null;
    protected array $context = [];
    protected array $recoveryStrategies = [];

    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?PathResolutionRequest $request = null,
        array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
        $this->request = $request;
        $this->context = $context;
    }

    public function getRequest(): ?PathResolutionRequest
    {
        return $this->request;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function addContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;

        return $this;
    }

    public function getRecoveryStrategies(): array
    {
        return $this->recoveryStrategies;
    }

    public function addRecoveryStrategy(string $strategy, array $parameters = []): self
    {
        $this->recoveryStrategies[] = ['strategy' => $strategy, 'parameters' => $parameters];

        return $this;
    }

    abstract public function getErrorCode(): string;

    abstract public function getRetryable(): bool;

    abstract public function getSeverity(): string;
}
