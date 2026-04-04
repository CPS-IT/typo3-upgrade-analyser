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

enum VcsResolutionStatus: string
{
    case RESOLVED_COMPATIBLE = 'resolved_compatible';
    case RESOLVED_NO_MATCH = 'resolved_no_match';
    /** Package not found in the queried source registry — caller must hand off to Tier 2. */
    case NOT_FOUND = 'not_found';
    case FAILURE = 'failure';
    /** SSH host unreachable — fallback skipped, availability unknown. */
    case SSH_UNREACHABLE = 'ssh_unreachable';
}
