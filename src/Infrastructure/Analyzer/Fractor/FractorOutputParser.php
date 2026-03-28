<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor;

use CPSIT\UpgradeAnalyzer\Shared\Utility\DiffProcessor;
use Psr\Log\LoggerInterface;

/**
 * Parser for Fractor JSON output.
 */
class FractorOutputParser
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly DiffProcessor $diffProcessor,
    ) {
    }

    /**
     * Parse Fractor JSON output into structured data.
     */
    public function parse(string $output): FractorOutputResult
    {
        $findings = [];
        $errors = [];
        $processedFiles = 0;

        if (empty(trim($output))) {
            return new FractorOutputResult(
                findings: $findings,
                errors: $errors,
                processedFiles: $processedFiles,
            );
        }

        try {
            $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            if (isset($data['totals']['changed_files'])) {
                $processedFiles = (int) $data['totals']['changed_files'];
            }

            // Handle different JSON structures from different Fractor versions
            if (isset($data['file_diffs']) && \is_array($data['file_diffs'])) {
                // Newer Fractor versions with file_diffs structure
                foreach ($data['file_diffs'] as $fileDiff) {
                    $file = $fileDiff['file'] ?? '';

                    if (isset($fileDiff['applied_rules']) && \is_array($fileDiff['applied_rules'])) {
                        foreach ($fileDiff['applied_rules'] as $rectorClass) {
                            $findings[] = $this->createFindingFromFractorClass($file, $rectorClass, $fileDiff);
                        }
                    }
                }
            } elseif (isset($data['changed_files']) && \is_array($data['changed_files'])) {
                // Older Fractor versions or different structure
                foreach ($data['changed_files'] as $fileData) {
                    if (\is_string($fileData)) {
                        // Simple array of file paths
                        $findings[] = $this->createFindingFromFilePath($fileData);
                    } elseif (\is_array($fileData)) {
                        // Object with file and applied_rules
                        $file = $fileData['file'] ?? '';

                        if (isset($fileData['applied_rules']) && \is_array($fileData['applied_rules'])) {
                            foreach ($fileData['applied_rules'] as $rectorData) {
                                $findings[] = $this->createFindingFromFractorData($file, $rectorData);
                            }
                        }
                    }
                }
            }

            // Handle error cases
            if (isset($data['errors']) && \is_array($data['errors'])) {
                $errors = array_map(function ($error): string {
                    if (\is_string($error)) {
                        return $error;
                    }
                    if (\is_array($error)) {
                        // Handle structured error objects
                        if (isset($error['message'])) {
                            return (string) $error['message'];
                        }
                        if (isset($error['error'])) {
                            return (string) $error['error'];
                        }

                        // Convert array to JSON string as fallback
                        return json_encode($error, JSON_UNESCAPED_SLASHES) ?: 'Invalid JSON data';
                    }

                    // Handle other types (objects, etc.)
                    return (string) $error;
                }, $data['errors']);

                if (!empty($errors)) {
                    $this->logger->warning('Fractor execution errors detected', [
                        'error_count' => \count($errors),
                        'errors' => $errors,
                    ]);
                }
            }
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse Fractor JSON output', [
                'error' => $e->getMessage(),
                'output_preview' => substr($output, 0, 200),
            ]);

            $errors[] = 'Failed to parse Fractor output: ' . $e->getMessage();
        }

        return new FractorOutputResult(
            findings: $findings,
            errors: $errors,
            processedFiles: $processedFiles,
        );
    }

    /**
     * Create FractorFinding from parsed Fractor data.
     */
    private function createFindingFromFractorData(string $file, array $rectorData): FractorFinding
    {
        $ruleClass = $rectorData['class'] ?? 'Unknown';
        $message = $rectorData['message'] ?? 'No message provided';
        $line = (int) ($rectorData['line'] ?? 0);
        $diff = null;

        if (isset($rectorData['diff'])) {
            $diff = $this->diffProcessor->extractDiff($rectorData['diff']);
        }

        // Determine severity and change type from rule class
        $severity = $this->determineSeverityFromRule($ruleClass);
        $changeType = $this->determineChangeTypeFromRule($ruleClass);

        $suggestedFix = null;
        if ($diff) {
            $suggestedFix = 'Apply Fractor changes';
        }

        return new FractorFinding(
            file: $file,
            line: $line,
            ruleClass: $ruleClass,
            message: $message,
            severity: $severity,
            changeType: $changeType,
            suggestedFix: $suggestedFix,
            diff: $diff,
            context: $rectorData,
        );
    }

    /**
     * Create FractorFinding from Fractor class name and file diff.
     */
    private function createFindingFromFractorClass(string $file, string $rectorClass, array $fileDiff): FractorFinding
    {
        $message = 'Code change detected by ' . $rectorClass;
        $line = 0; // Line number not available in this format
        $diff = null;

        // Try to extract code changes from diff if available
        if (isset($fileDiff['diff'])) {
            $diff = $this->diffProcessor->extractDiff($fileDiff['diff']);
        }

        // Determine severity and change type from rule class
        $severity = $this->determineSeverityFromRule($rectorClass);
        $changeType = $this->determineChangeTypeFromRule($rectorClass);

        $suggestedFix = $diff ? 'Update code according to diff' : null;

        return new FractorFinding(
            file: $file,
            line: $line,
            ruleClass: $rectorClass,
            message: $message,
            severity: $severity,
            changeType: $changeType,
            suggestedFix: $suggestedFix,
            diff: $diff,
            context: $fileDiff,
        );
    }

    /**
     * Create FractorFinding from file path only.
     */
    private function createFindingFromFilePath(string $file): FractorFinding
    {
        return new FractorFinding(
            file: $file,
            line: 0,
            ruleClass: 'Unknown',
            message: 'Code changes detected in file',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::BEST_PRACTICE,
            suggestedFix: 'Review changes in file',
            diff: null,
            context: ['file' => $file],
        );
    }

    /**
     * Determine severity from rule class name.
     */
    private function determineSeverityFromRule(string $ruleClass): FractorRuleSeverity
    {
        if (str_contains($ruleClass, 'CodeQuality')) {
            return FractorRuleSeverity::SUGGESTION;
        }

        if (str_contains($ruleClass, 'Remove') || str_contains($ruleClass, 'Breaking')) {
            return FractorRuleSeverity::CRITICAL;
        }

        if (str_contains($ruleClass, 'Substitute') || str_contains($ruleClass, 'Migrate')) {
            return FractorRuleSeverity::WARNING;
        }

        return FractorRuleSeverity::INFO;
    }

    /**
     * Determine change type from rule class name.
     */
    private function determineChangeTypeFromRule(string $ruleClass): FractorChangeType
    {
        if (str_contains($ruleClass, 'Remove')) {
            return FractorChangeType::BREAKING_CHANGE;
        }

        if (str_contains($ruleClass, 'Substitute') || str_contains($ruleClass, 'Replace')) {
            return FractorChangeType::DEPRECATION;
        }

        if (str_contains($ruleClass, 'Migrate')) {
            return FractorChangeType::CONFIGURATION_CHANGE;
        }

        return FractorChangeType::BEST_PRACTICE;
    }
}
