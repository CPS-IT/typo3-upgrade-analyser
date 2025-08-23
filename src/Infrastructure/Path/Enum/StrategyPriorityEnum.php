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
 * Strategy priority enumeration for conflict resolution.
 */
enum StrategyPriorityEnum: int
{
    case HIGHEST = 100;
    case HIGH = 75;
    case NORMAL = 50;
    case LOW = 25;
    case LOWEST = 10;
}
