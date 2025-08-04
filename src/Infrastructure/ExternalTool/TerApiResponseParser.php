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
 * Parser for TER API responses.
 */
class TerApiResponseParser
{
    /**
     * Parse extension data from TER API response.
     */
    public function parseExtensionData(array $responseData): ?array
    {
        if (!\is_array($responseData) || !isset($responseData[0])) {
            return null;
        }

        return $responseData[0];
    }

    /**
     * Parse versions data from TER API response.
     */
    public function parseVersionsData(array $responseData): ?array
    {
        if (!\is_array($responseData) || !isset($responseData[0])) {
            return null;
        }

        return $responseData[0];
    }

    /**
     * Extract extension key from extension data.
     */
    public function extractExtensionKey(array $extensionData): ?string
    {
        return $extensionData['key'] ?? null;
    }

    /**
     * Extract version numbers from versions data.
     */
    public function extractVersionNumbers(array $versions): array
    {
        $versionNumbers = [];

        foreach ($versions as $versionData) {
            if (isset($versionData['number'])) {
                $versionNumbers[] = $versionData['number'];
            }
        }

        return $versionNumbers;
    }
}
