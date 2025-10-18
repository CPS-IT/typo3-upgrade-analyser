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

use Psr\Log\LoggerInterface;

/**
 * Parses Fractor execution results.
 */
class FractorResultParser
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function parse(FractorExecutionResult $result): FractorAnalysisSummary
    {
        $this->logger->debug('Parsing Fractor results', [
            'exit_code' => $result->exitCode,
            'has_output' => $result->hasOutput(),
            'has_error' => $result->hasErrorOutput(),
        ]);

        $filesScanned = 0;
        $rulesApplied = 0;
        $findings = [];
        $data = [];
        $errorMessage = null;

        if ($result->hasOutput()) {
            $output = trim($result->output);

            // Try to parse JSON output first
            if ($this->isJsonOutput($output)) {
                $data = $this->parseJsonOutput($output);
                $filesScanned = $data['files_scanned'] ?? $data['files_changed'] ?? 0;
                $rulesApplied = $data['rules_applied'] ?? 0;
                $findings = $data['findings'] ?? [];
            } else {
                // Parse text output
                $data = $this->parseTextOutput($output);
                $filesScanned = $data['files_scanned'];
                $rulesApplied = $data['rules_applied'];
                $findings = $data['findings'];
            }
        }

        // Handle error output
        if ($result->hasErrorOutput()) {
            $errorMessage = trim($result->errorOutput);
            $this->logger->warning('Fractor reported errors', [
                'errors' => $errorMessage,
            ]);
        }

        // Determine success based on whether we got meaningful output
        // Fractor may return non-zero exit code even when analysis is successful
        $analysisSuccessful = $this->determineAnalysisSuccess($result, $findings);

        return new FractorAnalysisSummary(
            $filesScanned,
            $rulesApplied,
            $findings,
            $analysisSuccessful,
            $data['change_blocks'] ?? 0,
            $data['changed_lines'] ?? 0,
            $data['file_paths'] ?? [],
            $data['applied_rules'] ?? [],
            $errorMessage,
        );
    }

    private function isJsonOutput(string $output): bool
    {
        return str_starts_with(trim($output), '{') || str_starts_with(trim($output), '[');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonOutput(string $output): array
    {
        try {
            $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            return [
                'files_changed' => $data['files_changed'] ?? 0,
                'rules_applied' => $data['rules_applied'] ?? 0,
                'findings' => $data['findings'] ?? [],
            ];
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse Fractor JSON output', [
                'error' => $e->getMessage(),
                'output' => substr($output, 0, 500),
            ]);

            return [
                'files_changed' => 0,
                'rules_applied' => 0,
                'findings' => [],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTextOutput(string $output): array
    {
        $filesWithChanges = 0;
        $rulesApplied = 0;
        $changeBlocks = 0;
        $changedLines = 0;
        $findings = [];
        $filePaths = [];
        $appliedRules = [];

        $lines = explode("\n", $output);
        /** @var array{file_path: string, line_number: int, diff_lines: array<string>, applied_rules: array<string>, documentation_urls: array<string>, in_diff?: bool}|null $currentEntry */
        $currentEntry = null;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Parse the summary line: "22 files with changes"
            if (preg_match('/(\d+)\s+files?\s+with\s+changes/', $line, $matches)) {
                $filesWithChanges = (int) $matches[1];
                continue;
            }

            // Parse numbered file entries: "1) ../path/to/file.xml:8"
            if (preg_match('/^\d+\)\s+(.+):(\d+)$/', $line, $matches)) {
                // Save previous entry if it exists
                if (null !== $currentEntry) {
                    $findings[] = $this->createFractorFinding($currentEntry);
                }

                // Start new entry
                $currentEntry = [
                    'file_path' => $matches[1],
                    'line_number' => (int) $matches[2],
                    'diff_lines' => [],
                    'applied_rules' => [],
                    'documentation_urls' => [],
                ];
                $filePaths[] = $matches[1] . ':' . $matches[2];
                continue;
            }

            // Track diff sections
            if (str_contains($line, '---------- begin diff ----------')) {
                if (null !== $currentEntry) {
                    $currentEntry['in_diff'] = true;
                }
                ++$changeBlocks;
                continue;
            }

            if (str_contains($line, '----------- end diff -----------')) {
                if (null !== $currentEntry) {
                    $currentEntry['in_diff'] = false;
                }
                continue;
            }

            // Collect diff content for the current entry
            if (null !== $currentEntry && ($currentEntry['in_diff'] ?? false)) {
                $currentEntry['diff_lines'][] = $line;
                // Count changed lines within diff sections
                if ((str_starts_with($line, '-') || str_starts_with($line, '+'))
                    && !str_starts_with($line, '---') && !str_starts_with($line, '+++')) {
                    ++$changedLines;
                }
                continue;
            }

            // Parse applied rules: "* RuleName (url)"
            if (str_starts_with($line, '* ') && str_contains($line, 'Fractor')) {
                // Extract rule name and URL
                preg_match('/^\*\s+([^(]+)(?:\s*\(([^)]+)\))?/', $line, $ruleMatches);
                $ruleName = trim($ruleMatches[1] ?? '');
                $ruleUrl = $ruleMatches[2] ?? null;

                if ('' !== $ruleName && null !== $currentEntry) {
                    $currentEntry['applied_rules'][] = $ruleName;
                    if (null !== $ruleUrl) {
                        $currentEntry['documentation_urls'][] = $ruleUrl;
                    }
                }

                if ('' !== $ruleName && !\in_array($ruleName, $appliedRules, true)) {
                    $appliedRules[] = $ruleName;
                }
                continue;
            }
        }

        // Don't forget the last entry
        if (null !== $currentEntry) {
            $findings[] = $this->createFractorFinding($currentEntry);
        }

        // Count unique rules applied
        $rulesApplied = \count($appliedRules);

        // Use files with changes as files scanned (since Fractor only reports files that need changes)
        $filesScanned = $filesWithChanges;

        $this->logger->debug('Parsed Fractor output', [
            'files_with_changes' => $filesWithChanges,
            'files_scanned' => $filesScanned,
            'change_blocks' => $changeBlocks,
            'changed_lines' => $changedLines,
            'rules_applied' => $rulesApplied,
            'findings_created' => \count($findings),
            'unique_file_paths' => \count($filePaths),
            'applied_rules' => $appliedRules,
        ]);

        return [
            'files_scanned' => $filesScanned,
            'rules_applied' => $rulesApplied,
            'change_blocks' => $changeBlocks,
            'changed_lines' => $changedLines,
            'findings' => $findings,
            'file_paths' => \array_slice($filePaths, 0, 10), // Limit to prevent excessive data
            'applied_rules' => $appliedRules,
        ];
    }

    /**
     * Create FractorFinding from parsed entry data.
     */
    private function createFractorFinding(array $entryData): FractorFinding
    {
        $filePath = $entryData['file_path'];
        $lineNumber = $entryData['line_number'];
        $diffLines = $entryData['diff_lines'] ?? [];
        $appliedRules = $entryData['applied_rules'] ?? [];
        $documentationUrls = $entryData['documentation_urls'] ?? [];

        // Build full diff text
        $diff = implode("\n", $diffLines);

        // Extract before and after code from diff
        $codeBefore = '';
        $codeAfter = '';
        foreach ($diffLines as $diffLine) {
            if (str_starts_with($diffLine, '-') && !str_starts_with($diffLine, '---')) {
                $codeBefore .= substr($diffLine, 1) . "\n";
            } elseif (str_starts_with($diffLine, '+') && !str_starts_with($diffLine, '+++')) {
                $codeAfter .= substr($diffLine, 1) . "\n";
            }
        }

        // Use first applied rule as primary rule
        $primaryRule = $appliedRules[0] ?? 'Unknown Rule';
        $ruleClass = 'CPSIT\\Fractor\\Rule\\' . $primaryRule;

        // Generate message based on the rule and change
        $message = $this->generateFindingMessage($primaryRule, $codeBefore, $codeAfter);

        // Determine severity and change type based on rule name
        $severity = $this->determineSeverity($primaryRule);
        $changeType = $this->determineChangeType($primaryRule);

        // Get documentation URL
        $documentationUrl = $documentationUrls[0] ?? null;

        return new FractorFinding(
            $filePath,
            $lineNumber,
            $ruleClass,
            $message,
            $severity,
            $changeType,
            trim($codeBefore),
            trim($codeAfter),
            $diff,
            $documentationUrl,
            [
                'applied_rules' => $appliedRules,
                'documentation_urls' => $documentationUrls,
            ],
        );
    }

    private function generateFindingMessage(string $ruleName, string $codeBefore, string $codeAfter): string
    {
        // Generate meaningful messages based on rule patterns
        if (str_contains($ruleName, 'RemoveNoCacheHash')) {
            return 'Remove deprecated noCacheHash attribute from form ViewHelper';
        }

        if (str_contains($ruleName, 'FlexForm')) {
            return 'Migrate FlexForm configuration to current TYPO3 standards';
        }

        if (str_contains($ruleName, 'TCA')) {
            return 'Update TCA configuration for TYPO3 compatibility';
        }

        if (str_contains($ruleName, 'TypoScript')) {
            return 'Modernize TypoScript configuration';
        }

        // Generic fallback
        return \sprintf('Apply %s rule to modernize code', $ruleName);
    }

    private function determineSeverity(string $ruleName): FractorRuleSeverity
    {
        // Rules that fix breaking changes are critical
        if (str_contains($ruleName, 'Remove') && str_contains($ruleName, 'Deprecat')) {
            return FractorRuleSeverity::CRITICAL;
        }

        // Most Fractor rules fix deprecations - warnings
        if (str_contains($ruleName, 'Remove') || str_contains($ruleName, 'Replace')) {
            return FractorRuleSeverity::WARNING;
        }

        // Modernization rules are informational
        if (str_contains($ruleName, 'Migrate') || str_contains($ruleName, 'Modernize')) {
            return FractorRuleSeverity::INFO;
        }

        // Default to warning for safety
        return FractorRuleSeverity::WARNING;
    }

    private function determineChangeType(string $ruleName): FractorChangeType
    {
        if (str_contains($ruleName, 'FlexForm')) {
            return FractorChangeType::FLEXFORM_MIGRATION;
        }

        if (str_contains($ruleName, 'TCA')) {
            return FractorChangeType::TCA_MIGRATION;
        }

        if (str_contains($ruleName, 'TypoScript')) {
            return FractorChangeType::TYPOSCRIPT_MIGRATION;
        }

        if (str_contains($ruleName, 'Fluid')) {
            return FractorChangeType::FLUID_MIGRATION;
        }

        if (str_contains($ruleName, 'Template')) {
            return FractorChangeType::TEMPLATE_UPDATE;
        }

        if (str_contains($ruleName, 'Config')) {
            return FractorChangeType::CONFIGURATION_UPDATE;
        }

        if (str_contains($ruleName, 'Remove') && str_contains($ruleName, 'Deprecat')) {
            return FractorChangeType::DEPRECATION_REMOVAL;
        }

        // Default to modernization
        return FractorChangeType::MODERNIZATION;
    }

    /**
     * @param array<string> $findings
     */
    private function determineAnalysisSuccess(FractorExecutionResult $result, array $findings): bool
    {
        // If process was successful, trust that
        if ($result->successful) {
            return true;
        }

        // Check for fatal error indicators in error output
        if ($result->hasErrorOutput()) {
            $errorOutput = strtolower($result->errorOutput);

            // These indicate serious failures
            if (str_contains($errorOutput, 'fatal error')
                || str_contains($errorOutput, 'configuration file not found')
                || str_contains($errorOutput, 'no such file or directory')
                || str_contains($errorOutput, 'permission denied')) {
                return false;
            }
        }

        // If we have output with findings, consider it successful even with non-zero exit
        if ($result->hasOutput()) {
            $output = strtolower(trim($result->output));

            // Check for positive indicators
            if (!empty($findings)
                || str_contains($output, 'processed')
                || str_contains($output, 'analyzed')
                || str_contains($output, 'completed')
                || str_contains($output, '[ok]')
                || str_contains($output, 'files with changes')
                || str_contains($output, 'would have been changed')) {
                return true;
            }
        }

        // If no clear success indicators but also no fatal errors,
        // and we have an exit code that might just mean "changes suggested"
        if (\in_array($result->exitCode, [0, 1, 2], true)) {
            return !empty($findings);
        }

        // Default to the process result for other cases
        return $result->successful;
    }
}
