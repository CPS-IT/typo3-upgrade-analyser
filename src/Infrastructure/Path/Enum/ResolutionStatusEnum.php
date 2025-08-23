<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum;

/**
 * Resolution status enumeration for response objects.
 */
enum ResolutionStatusEnum: string
{
    case SUCCESS = 'success';
    case NOT_FOUND = 'not_found';
    case ERROR = 'error';
    case PARTIAL = 'partial';
}
