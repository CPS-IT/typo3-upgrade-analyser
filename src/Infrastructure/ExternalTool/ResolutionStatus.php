<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool;

enum ResolutionStatus: string
{
    case RESOLVED_COMPATIBLE = 'resolved_compatible';
    case RESOLVED_NO_MATCH = 'resolved_no_match';
    case NOT_ON_PACKAGIST = 'not_on_packagist';
    case FAILURE = 'failure';
}
