<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Service for executing Rector binary and processing results.
 */
class RectorExecutor
{
    public function __construct(
        private readonly string $rectorBinaryPath,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 300,
    ) {
    }

    /**
     * Execute Rector with configuration file.
     *
     * @param array<string, mixed> $options Additional command options
     */
    public function execute(string $configPath, string $targetPath, array $options = []): RectorExecutionResult
    {
        if (!$this->isAvailable()) {
            throw new AnalyzerException('Rector binary not found at: ' . $this->rectorBinaryPath, 'RectorExecutor');
        }

        $command = $this->buildCommand($configPath, $targetPath, $options);

        $this->logger->info('Executing Rector analysis', [
            'command' => implode(' ', $command),
            'target_path' => $targetPath,
            'config_path' => $configPath,
        ]);

        $startTime = microtime(true);

        try {
            $process = new Process($command);
            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            $executionTime = microtime(true) - $startTime;
            $exitCode = $process->getExitCode();
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            $this->logger->info('Rector execution completed', [
                'exit_code' => $exitCode,
                'execution_time' => $executionTime,
                'stdout_length' => \strlen($stdout),
                'stderr_length' => \strlen($stderr),
                'stdout_preview' => substr($stdout, 0, 500),
                'stderr_preview' => substr($stderr, 0, 500),
            ]);

            // Parse the output
            $parseResult = $this->parseOutput($stdout);

            return new RectorExecutionResult(
                successful: 0 === $exitCode,
                findings: $parseResult['findings'],
                errors: $parseResult['errors'],
                executionTime: $executionTime,
                exitCode: $exitCode,
                rawOutput: $stdout,
                processedFileCount: $parseResult['processed_files'],
            );
        } catch (ProcessTimedOutException $e) {
            $executionTime = microtime(true) - $startTime;

            $this->logger->error('Rector execution timed out', [
                'timeout_seconds' => $this->timeoutSeconds,
                'execution_time' => $executionTime,
            ]);

            throw new AnalyzerException('Rector analysis timed out after ' . $this->timeoutSeconds . ' seconds', 'RectorExecutor', $e);
        } catch (ProcessFailedException $e) {
            $this->logger->error('Rector execution failed', [
                'error' => $e->getMessage(),
            ]);

            throw new AnalyzerException('Rector analysis failed: ' . $e->getMessage(), 'RectorExecutor', $e);
        }
    }

    /**
     * Execute Rector with specific rules only.
     *
     * @param array<string> $ruleNames Array of rule class names
     */
    public function executeWithRules(array $ruleNames, string $targetPath): RectorExecutionResult
    {
        // Create temporary config with only specified rules
        $tempConfig = $this->createTempConfigWithRules($ruleNames, $targetPath);

        try {
            return $this->execute($tempConfig, $targetPath);
        } finally {
            // Clean up temporary config
            if (file_exists($tempConfig)) {
                unlink($tempConfig);
            }
        }
    }

    /**
     * Check if Rector binary is available.
     */
    public function isAvailable(): bool
    {
        return file_exists($this->rectorBinaryPath) && is_executable($this->rectorBinaryPath);
    }

    /**
     * Get Rector version.
     */
    public function getVersion(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $process = new Process([$this->rectorBinaryPath, '--version']);
            $process->setTimeout(10);
            $process->run();

            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                // Extract version number from output like "Rector 0.15.25"
                if (preg_match('/Rector (\d+\.\d+\.\d+)/', $output, $matches)) {
                    return $matches[1];
                }

                return $output;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get Rector version', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Build command array for Process execution.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string>
     */
    private function buildCommand(string $configPath, string $targetPath, array $options): array
    {
        $command = [
            $this->rectorBinaryPath,
            'process',
            $targetPath,
            '--config',
            $configPath,
            '--dry-run', // Never modify files, only analyze
            '--output-format',
            'json',
            '--no-progress-bar',
        ];

        // Add memory limit if specified
        if (isset($options['memory_limit'])) {
            $command[] = '--memory-limit';
            $command[] = $options['memory_limit'];
        }

        // Add debug flag if requested
        if (!empty($options['debug'])) {
            $command[] = '--debug';
        }

        // Add clear cache flag if requested
        if (!empty($options['clear_cache'])) {
            $command[] = '--clear-cache';
        }

        return $command;
    }

    /**
     * Parse Rector JSON output into structured data.
     *
     * @return array{findings: array<RectorFinding>, errors: array<string>, processed_files: int}
     */
    private function parseOutput(string $output): array
    {
        $findings = [];
        $errors = [];
        $processedFiles = 0;

        if (empty(trim($output))) {
            return [
                'findings' => $findings,
                'errors' => $errors,
                'processed_files' => $processedFiles,
            ];
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
                $errors = array_map(fn ($error) => (string) $error, $data['errors']);
            }
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse Rector JSON output', [
                'error' => $e->getMessage(),
                'output_preview' => substr($output, 0, 200),
            ]);

            $errors[] = 'Failed to parse Rector output: ' . $e->getMessage();
        }

        return [
            'findings' => $findings,
            'errors' => $errors,
            'processed_files' => $processedFiles,
        ];
    }

    /**
     * Create RectorFinding from parsed Rector data.
     */
    private function createFindingFromRectorData(string $file, array $rectorData): RectorFinding
    {
        $ruleClass = $rectorData['class'] ?? 'Unknown';
        $message = $rectorData['message'] ?? 'No message provided';
        $line = (int) ($rectorData['line'] ?? 0);
        $oldCode = $rectorData['old'] ?? null;
        $newCode = $rectorData['new'] ?? null;

        // Determine severity and change type from rule class
        $severity = $this->determineSeverityFromRule($ruleClass);
        $changeType = $this->determineChangeTypeFromRule($ruleClass);

        $suggestedFix = null;
        if ($oldCode && $newCode) {
            $suggestedFix = "Replace '{$oldCode}' with '{$newCode}'";
        }

        return new RectorFinding(
            file: $file,
            line: $line,
            ruleClass: $ruleClass,
            message: $message,
            severity: $severity,
            changeType: $changeType,
            suggestedFix: $suggestedFix,
            oldCode: $oldCode,
            newCode: $newCode,
            context: $rectorData,
        );
    }

    /**
     * Determine severity from rule class name.
     */
    private function determineSeverityFromRule(string $ruleClass): RectorRuleSeverity
    {
        if (str_contains($ruleClass, 'Remove') || str_contains($ruleClass, 'Breaking')) {
            return RectorRuleSeverity::CRITICAL;
        } elseif (str_contains($ruleClass, 'Substitute') || str_contains($ruleClass, 'Migrate')) {
            return RectorRuleSeverity::WARNING;
        } else {
            return RectorRuleSeverity::INFO;
        }
    }

    /**
     * Determine change type from rule class name.
     */
    private function determineChangeTypeFromRule(string $ruleClass): RectorChangeType
    {
        if (str_contains($ruleClass, 'Remove')) {
            if (str_contains($ruleClass, 'Method')) {
                return RectorChangeType::METHOD_SIGNATURE;
            } elseif (str_contains($ruleClass, 'Class')) {
                return RectorChangeType::CLASS_REMOVAL;
            } else {
                return RectorChangeType::BREAKING_CHANGE;
            }
        } elseif (str_contains($ruleClass, 'Substitute') || str_contains($ruleClass, 'Replace')) {
            return RectorChangeType::DEPRECATION;
        } elseif (str_contains($ruleClass, 'Migrate')) {
            return RectorChangeType::CONFIGURATION_CHANGE;
        } else {
            return RectorChangeType::BEST_PRACTICE;
        }
    }

    /**
     * Create RectorFinding from Rector class name and file diff.
     */
    private function createFindingFromRectorClass(string $file, string $rectorClass, array $fileDiff): RectorFinding
    {
        $message = 'Code change detected by ' . $rectorClass;
        $line = 0; // Line number not available in this format
        $oldCode = null;
        $newCode = null;

        // Try to extract code changes from diff if available
        if (isset($fileDiff['diff'])) {
            $this->extractCodeFromDiff($fileDiff['diff'], $oldCode, $newCode);
        }

        // Determine severity and change type from rule class
        $severity = $this->determineSeverityFromRule($rectorClass);
        $changeType = $this->determineChangeTypeFromRule($rectorClass);

        $suggestedFix = $newCode ? 'Update code according to diff' : null;

        return new RectorFinding(
            file: $file,
            line: $line,
            ruleClass: $rectorClass,
            message: $message,
            severity: $severity,
            changeType: $changeType,
            suggestedFix: $suggestedFix,
            oldCode: $oldCode,
            newCode: $newCode,
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
            oldCode: null,
            newCode: null,
            context: ['file' => $file],
        );
    }

    /**
     * Extract code snippets from diff text.
     */
    private function extractCodeFromDiff(string $diff, ?string &$oldCode, ?string &$newCode): void
    {
        $lines = explode("\n", $diff);
        $oldLines = [];
        $newLines = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                $oldLines[] = substr($line, 1);
            } elseif (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                $newLines[] = substr($line, 1);
            }
        }

        $oldCode = !empty($oldLines) ? implode("\n", $oldLines) : null;
        $newCode = !empty($newLines) ? implode("\n", $newLines) : null;
    }

    /**
     * Create temporary config file with specified rules.
     *
     * @param array<string> $ruleNames
     */
    private function createTempConfigWithRules(array $ruleNames, string $targetPath): string
    {
        $configContent = "<?php\n\nuse Rector\\Config\\RectorConfig;\n\nreturn static function (RectorConfig \$rectorConfig): void {\n";
        $configContent .= "    \$rectorConfig->paths(['{$targetPath}']);\n";
        $configContent .= "    \$rectorConfig->rules([\n";

        foreach ($ruleNames as $ruleName) {
            $configContent .= "        {$ruleName}::class,\n";
        }

        $configContent .= "    ]);\n";
        $configContent .= "};\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'rector_config_');
        if (!$tempFile) {
            throw new AnalyzerException('Failed to create temporary Rector config file', 'RectorExecutor');
        }

        file_put_contents($tempFile, $configContent);

        return $tempFile;
    }
}
