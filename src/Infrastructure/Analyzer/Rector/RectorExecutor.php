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
    private readonly RectorOutputParser $outputParser;

    public function __construct(
        private readonly string $rectorBinaryPath,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 300,
    ) {
        $this->outputParser = new RectorOutputParser($this->logger);
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
            $parseResult = $this->outputParser->parse($stdout);

            return new RectorExecutionResult(
                successful: 0 === $exitCode,
                findings: $parseResult->findings,
                errors: $parseResult->errors,
                executionTime: $executionTime,
                exitCode: $exitCode ?? -1,
                rawOutput: $stdout,
                processedFileCount: $parseResult->processedFiles,
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
