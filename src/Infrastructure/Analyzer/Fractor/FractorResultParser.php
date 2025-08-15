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
        $inDiff = false;
        $currentFile = '';

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
            if (preg_match('/^\d+\)\s+(.+)/', $line, $matches)) {
                $currentFile = $matches[1];
                $filePaths[] = $currentFile;
                continue;
            }

            // Track diff sections
            if (str_contains($line, '---------- begin diff ----------')) {
                $inDiff = true;
                ++$changeBlocks;
                continue;
            }

            if (str_contains($line, '----------- end diff -----------')) {
                $inDiff = false;
                continue;
            }

            // Count changed lines within diff sections (but don't include as findings)
            if ($inDiff && (str_starts_with($line, '-') || str_starts_with($line, '+'))) {
                if (!str_starts_with($line, '---') && !str_starts_with($line, '+++')) {
                    ++$changedLines;
                }
                continue;
            }

            // Parse applied rules: "* RuleName (url)"
            if (str_starts_with($line, '* ') && str_contains($line, 'Fractor')) {
                $ruleName = trim(explode('(', $line)[0]);
                $ruleName = str_replace('* ', '', $ruleName);
                if (!\in_array($ruleName, $appliedRules, true)) {
                    $appliedRules[] = $ruleName;
                }
                continue;
            }
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
