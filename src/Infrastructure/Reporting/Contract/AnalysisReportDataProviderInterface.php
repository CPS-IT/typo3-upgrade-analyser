<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\Contract;

use CPSIT\UpgradeAnalyzer\Domain\Contract\ResultInterface;

interface AnalysisReportDataProviderInterface
{
    /**
     * Get the analyzer name this provider handles.
     */
    public function getAnalyzerName(): string;

    /**
     * Extract data from analysis results.
     *
     * @param array<ResultInterface> $results
     */
    public function extractData(array $results): ?array;

    /**
     * Get the key used to store the data in the report context.
     */
    public function getResultKey(): string;

    /**
     * Get the template path for the specified format.
     */
    public function getTemplatePath(string $format): string;
}
