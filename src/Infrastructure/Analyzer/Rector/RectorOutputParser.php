<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector;

use CPSIT\UpgradeAnalyzer\Shared\Utility\DiffProcessor;
use Psr\Log\LoggerInterface;

/**
 * Parser for Rector JSON output.
 */
readonly class RectorOutputParser
{
    public function __construct(
        private LoggerInterface $logger,
        private DiffProcessor $diffProcessor,
    ) {
    }

    /**
     * Parse Rector JSON output into structured data.
     */
    public function parse(string $output): RectorOutputResult
    {
        $findings = [];
        $errors = [];
        $processedFiles = 0;

        if (empty(trim($output))) {
            return new RectorOutputResult(
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

            // Handle different JSON structures from different Rector versions
            if (isset($data['file_diffs']) && \is_array($data['file_diffs'])) {
                // Newer Rector versions with file_diffs structure
                foreach ($data['file_diffs'] as $fileDiff) {
                    $file = $fileDiff['file'] ?? '';

                    if (isset($fileDiff['applied_rectors']) && \is_array($fileDiff['applied_rectors'])) {
                        foreach ($fileDiff['applied_rectors'] as $rectorClass) {
                            $findings[] = $this->createFindingFromRectorClass($file, $rectorClass, $fileDiff);
                        }
                    }
                }
            } elseif (isset($data['changed_files']) && \is_array($data['changed_files'])) {
                // Older Rector versions or different structure
                foreach ($data['changed_files'] as $fileData) {
                    if (\is_string($fileData)) {
                        // Simple array of file paths
                        $findings[] = $this->createFindingFromFilePath($fileData);
                    } elseif (\is_array($fileData)) {
                        // Object with file and applied_rectors
                        $file = $fileData['file'] ?? '';

                        if (isset($fileData['applied_rectors']) && \is_array($fileData['applied_rectors'])) {
                            foreach ($fileData['applied_rectors'] as $rectorData) {
                                $findings[] = $this->createFindingFromRectorData($file, $rectorData);
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
                    $this->logger->warning('Rector execution errors detected', [
                        'error_count' => \count($errors),
                        'errors' => $errors,
                    ]);
                }
            }
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse Rector JSON output', [
                'error' => $e->getMessage(),
                'output_preview' => substr($output, 0, 200),
            ]);

            $errors[] = 'Failed to parse Rector output: ' . $e->getMessage();
        }

        return new RectorOutputResult(
            findings: $findings,
            errors: $errors,
            processedFiles: $processedFiles,
        );
    }

    /**
     * Create RectorFinding from parsed Rector data.
     */
    private function createFindingFromRectorData(string $file, array $rectorData): RectorFinding
    {
        $ruleClass = $rectorData['class'] ?? 'Unknown';
        $message = $rectorData['message'] ?? 'No message provided';
        $line = (int) ($rectorData['line'] ?? 0);
        $diff = null;

        if (isset($rectorData['diff'])) {
            $diff = $this->diffProcessor->extractDiff($rectorData['diff']);
        } elseif (isset($rectorData['old'], $rectorData['new'])) {
            // Deprecated path: try to construct a diff if old/new exist but no diff
            // This is a fallback and might not produce a real diff
            // $diff = ...
        }

        // Determine severity and change type from rule class
        $severity = $this->determineSeverityFromRule($ruleClass);
        $changeType = $this->determineChangeTypeFromRule($ruleClass);

        $suggestedFix = null;
        if ($diff) {
            $suggestedFix = 'Apply Rector changes';
        }

        return new RectorFinding(
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
     * Create RectorFinding from Rector class name and file diff.
     */
    private function createFindingFromRectorClass(string $file, string $rectorClass, array $fileDiff): RectorFinding
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

        return new RectorFinding(
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
     * Create RectorFinding from file path only.
     */
    private function createFindingFromFilePath(string $file): RectorFinding
    {
        return new RectorFinding(
            file: $file,
            line: 0,
            ruleClass: 'Unknown',
            message: 'Code changes detected in file',
            severity: RectorRuleSeverity::INFO,
            changeType: RectorChangeType::BEST_PRACTICE,
            suggestedFix: 'Review changes in file',
            diff: null,
            context: ['file' => $file],
        );
    }

    /**
     * Determine severity from rule class name.
     */
    private function determineSeverityFromRule(string $ruleClass): RectorRuleSeverity
    {
        if (str_contains($ruleClass, 'CodeQuality')) {
            return RectorRuleSeverity::SUGGESTION;
        }

        if (str_contains($ruleClass, 'Remove')
            || str_contains($ruleClass, 'Drop')
            || str_contains($ruleClass, 'Breaking')
            || str_contains($ruleClass, 'Strict')
        ) {
            return RectorRuleSeverity::CRITICAL;
        }

        if (str_contains($ruleClass, 'Substitute')
            || str_contains($ruleClass, 'Migrate')
            || str_contains($ruleClass, 'Replace')
            || str_contains($ruleClass, 'Change')
        ) {
            return RectorRuleSeverity::WARNING;
        }

        return RectorRuleSeverity::INFO;
    }

    /**
     * Determine change type from rule class name.
     */
    private function determineChangeTypeFromRule(string $ruleClass): RectorChangeType
    {
        if (str_contains($ruleClass, 'Remove')) {
            if (str_contains($ruleClass, 'Method')) {
                return RectorChangeType::METHOD_SIGNATURE;
            }

            if (str_contains($ruleClass, 'Class')) {
                return RectorChangeType::CLASS_REMOVAL;
            }

            return RectorChangeType::BREAKING_CHANGE;
        }

        if (str_contains($ruleClass, 'Substitute') || str_contains($ruleClass, 'Replace')) {
            return RectorChangeType::DEPRECATION;
        }

        if (str_contains($ruleClass, 'Migrate') || str_contains($ruleClass, 'Tca')) {
            return RectorChangeType::CONFIGURATION_CHANGE;
        }

        return RectorChangeType::BEST_PRACTICE;
    }
}
