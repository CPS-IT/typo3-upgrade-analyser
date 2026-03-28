<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Shared\Utility;

/**
 * Service for processing and cleaning up diffs for display.
 */
class DiffProcessor
{
    /**
     * Extract a clean diff with context from a raw diff string.
     *
     * Removes file headers (--- Original, +++ New) but preserves
     * context lines, additions, and deletions.
     */
    public function extractDiff(string $diff): string
    {
        $lines = explode("\n", $diff);
        $processedLines = [];

        foreach ($lines as $line) {
            // Skip file headers commonly found in Rector/Fractor output
            if ('--- Original' === $line || '+++ New' === $line) {
                continue;
            }

            // Skip standard unified diff file headers
            if (preg_match('/^(---|\+\+\+) /', $line)) {
                continue;
            }

            // Keep all other lines (context, additions, deletions, hunks)
            $processedLines[] = $line;
        }

        return trim(implode("\n", $processedLines));
    }
}
