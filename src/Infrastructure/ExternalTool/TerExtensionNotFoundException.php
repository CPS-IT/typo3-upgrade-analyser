<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool;

/**
 * Exception thrown when an extension is not found in TER.
 */
class TerExtensionNotFoundException extends TerApiException
{
    public function __construct(string $extensionKey, ?\Throwable $previous = null)
    {
        parent::__construct(
            \sprintf('Extension "%s" not found in TER', $extensionKey),
            $previous,
        );
    }
}
