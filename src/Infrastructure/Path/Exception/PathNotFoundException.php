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
 * Exception when path cannot be found despite valid request.
 */
class PathNotFoundException extends PathResolutionException
{
    private array $attemptedPaths = [];
    private array $suggestedPaths = [];

    public function getErrorCode(): string
    {
        return 'PATH_NOT_FOUND';
    }

    public function getRetryable(): bool
    {
        return true;
    }

    public function getSeverity(): string
    {
        return 'warning';
    }

    public function setAttemptedPaths(array $paths): self
    {
        $this->attemptedPaths = $paths;

        return $this;
    }

    public function getAttemptedPaths(): array
    {
        return $this->attemptedPaths;
    }

    public function setSuggestedPaths(array $paths): self
    {
        $this->suggestedPaths = $paths;

        return $this;
    }

    public function getSuggestedPaths(): array
    {
        return $this->suggestedPaths;
    }

    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null, ?PathResolutionRequest $request = null, array $context = [])
    {
        parent::__construct($message, $code, $previous, $request, $context);
        $this->addRecoveryStrategy('alternative_path_search');
        $this->addRecoveryStrategy('configuration_update_suggestion');
        $this->addRecoveryStrategy('fallback_to_default_paths');
    }
}
