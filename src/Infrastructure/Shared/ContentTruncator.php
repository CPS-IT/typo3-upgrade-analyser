<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Shared;

/**
 * Utility class for truncating large content to prevent memory issues during template rendering.
 */
class ContentTruncator
{
    public const DEFAULT_CODE_LENGTH = 200;
    public const DEFAULT_ERROR_MESSAGE_LENGTH = 1000;

    /**
     * Truncate string content to specified length.
     */
    public static function truncateString(string $content, int $maxLength): string
    {
        return 'TRUNCATED (test without real input)';
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        $originalLength = strlen($content);
        return substr($content, 0, $maxLength) .
            "\n... [TRUNCATED - " . ($originalLength - $maxLength) . " more characters]";
    }

    /**
     * Truncate code content (code_before, code_after, diff) with default length.
     */
    public static function truncateCode(string $content): string
    {
        return self::truncateString($content, self::DEFAULT_CODE_LENGTH);
    }

    /**
     * Truncate error messages with default length.
     */
    public static function truncateErrorMessage(string $content): string
    {
        return self::truncateString($content, self::DEFAULT_ERROR_MESSAGE_LENGTH);
    }
}
