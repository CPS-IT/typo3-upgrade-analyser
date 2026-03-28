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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerException;
use CPSIT\UpgradeAnalyzer\Shared\Utility\DiffProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Executes Fractor commands and handles the results.
 */
class FractorExecutor
{
    private FractorOutputParser $outputParser;

    private ?string $versionCache = null;

    public function __construct(
        private readonly string $fractorBinaryPath,
        private readonly LoggerInterface $logger,
        private readonly DiffProcessor $diffProcessor,
        private readonly int $timeoutSeconds = 300,
    ) {
        $this->outputParser = new FractorOutputParser($this->logger, $this->diffProcessor);
    }

    /**
     * Check if Fractor binary is available.
     */
    public function isAvailable(): bool
    {
        return file_exists($this->fractorBinaryPath) && is_executable($this->fractorBinaryPath);
    }

    /**
     * Execute Fractor with configuration file.
     *
     * @param array<string, mixed> $options Additional command options
     */
    public function execute(string $configPath, string $targetPath, array $options = []): FractorExecutionResult
    {
        if (!$this->isAvailable()) {
            throw new AnalyzerException('Fractor binary not found at: ' . $this->fractorBinaryPath, 'FractorExecutor');
        }

        $command = $this->buildCommand($configPath, $targetPath, $options);

        $this->logger->info('Executing Fractor analysis', [
            'command' => implode(' ', $command),
            'target_path' => $targetPath,
            'config_path' => $configPath,
        ]);

        $startTime = microtime(true);

        $process = new Process($command);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();

            $executionTime = microtime(true) - $startTime;
            $exitCode = $process->getExitCode();
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            $this->logger->info('Fractor execution completed', [
                'exit_code' => $exitCode,
                'execution_time' => $executionTime,
                'stdout_length' => \strlen($stdout),
                'stderr_length' => \strlen($stderr),
                'stdout_preview' => substr($stdout, 0, 500),
                'stderr_preview' => substr($stderr, 0, 500),
            ]);

            // Parse the output
            $parseResult = $this->outputParser->parse($stdout);

            return new FractorExecutionResult(
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

            $this->logger->error('Fractor execution timed out', [
                'timeout_seconds' => $this->timeoutSeconds,
                'execution_time' => $executionTime,
            ]);

            throw new AnalyzerException('Fractor analysis timed out after ' . $this->timeoutSeconds . ' seconds', 'FractorExecutor', $e);
        } catch (ProcessFailedException $e) {
            $this->logger->error('Fractor execution failed', [
                'error' => $e->getMessage(),
            ]);

            throw new AnalyzerException('Fractor analysis failed: ' . $e->getMessage(), 'FractorExecutor', $e);
        }
    }

    /**
     * Get Fractor version.
     */
    public function getVersion(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        if (null !== $this->versionCache) {
            return $this->versionCache;
        }

        try {
            $process = new Process([$this->fractorBinaryPath, '--version']);
            $process->setTimeout(10);
            $process->run();

            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                // Extract version number from output like "Fractor 1.0.0"
                if (preg_match('/Fractor (\d+\.\d+\.\d+)/', $output, $matches)) {
                    $this->versionCache = $matches[1];

                    return $matches[1];
                }

                $this->versionCache = $output;

                return $output;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get Fractor version', [
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
            $this->fractorBinaryPath,
            'process',
            $targetPath,
            '--config',
            $configPath,
            '--dry-run', // Never modify files, only analyze
            '--output-format',
            'json',
            '--no-progress-bar',
        ];

        // Add clear cache flag if requested
        if (!empty($options['clear_cache'])) {
            $command[] = '--clear-cache';
        }

        return $command;
    }
}
