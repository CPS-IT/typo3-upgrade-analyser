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
 * Formats unified diffs into HTML with highlighting.
 */
class DiffHtmlFormatter
{
    /**
     * Format a unified diff string into HTML with span classes for highlighting.
     */
    public function format(string $diff): string
    {
        $lines = explode("\n", $diff);
        $output = '';
        $count = \count($lines);

        foreach ($lines as $index => $line) {
            // Escape HTML entities to prevent XSS and ensure correct display
            $escapedLine = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $isLast = ($index === $count - 1);

            if (str_starts_with($line, '+')) {
                // Block element: No trailing newline needed in <pre> as it forces a break
                $output .= \sprintf('<span class="pl-mi1">%s</span>', $escapedLine);
            } elseif (str_starts_with($line, '-')) {
                // Block element: No trailing newline needed
                $output .= \sprintf('<span class="pl-md">%s</span>', $escapedLine);
            } elseif (str_starts_with($line, '@')) {
                // Hunk header (inline in our CSS): Needs newline
                $output .= \sprintf('<span class="pl-c">%s</span>', $escapedLine);
                if (!$isLast) {
                    $output .= "\n";
                }
            } else {
                // Context line (inline): Needs newline
                $output .= $escapedLine;
                if (!$isLast) {
                    $output .= "\n";
                }
            }
        }

        return $output;
    }
}
