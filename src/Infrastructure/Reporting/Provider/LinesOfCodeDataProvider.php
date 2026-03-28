<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\Provider;

use CPSIT\UpgradeAnalyzer\Domain\Contract\ResultInterface;
use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\Contract\AnalysisReportDataProviderInterface;

class LinesOfCodeDataProvider implements AnalysisReportDataProviderInterface
{
    public function getAnalyzerName(): string
    {
        return 'lines_of_code';
    }

    public function getResultKey(): string
    {
        return 'loc_analysis';
    }

    public function getTemplatePath(string $format): string
    {
        return match ($format) {
            'html' => 'html/partials/main-report/lines-of-code-table.html.twig',
            'md' => 'md/partials/main-report/lines-of-code-table.md.twig',
            default => throw new \InvalidArgumentException(\sprintf('Unsupported format: %s', $format)),
        };
    }

    public function extractData(array $results): ?array
    {
        $locResult = array_filter(
            $results,
            fn (ResultInterface $r): bool => $r instanceof AnalysisResult && $this->getAnalyzerName() === $r->getAnalyzerName(),
        );

        if (empty($locResult)) {
            return null;
        }

        /** @var AnalysisResult $result */
        $result = reset($locResult);

        return [
            'total_lines' => $result->getMetric('total_lines'),
            'code_lines' => $result->getMetric('code_lines'),
            'comment_lines' => $result->getMetric('comment_lines'),
            'blank_lines' => $result->getMetric('blank_lines'),
            'php_files' => $result->getMetric('php_files'),
            'classes' => $result->getMetric('classes'),
            'methods' => $result->getMetric('methods'),
            'functions' => $result->getMetric('functions'),
            'largest_file_lines' => $result->getMetric('largest_file_lines'),
            'largest_file_path' => $result->getMetric('largest_file_path'),
            'average_file_size' => $result->getMetric('average_file_size'),
            'risk_score' => $result->getRiskScore(),
            'recommendations' => $result->getRecommendations(),
        ];
    }
}
