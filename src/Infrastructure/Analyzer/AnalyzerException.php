<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer;

/**
 * Exception thrown when analyzer operations fail.
 */
class AnalyzerException extends \Exception
{
    public function __construct(
        string $message,
        private readonly string $analyzerName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getAnalyzerName(): string
    {
        return $this->analyzerName;
    }
}
