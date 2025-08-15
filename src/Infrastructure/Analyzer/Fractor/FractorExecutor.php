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
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Executes Fractor commands and handles the results.
 */
class FractorExecutor
{
    public function __construct(
        private readonly string $fractorBinaryPath,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 300,
    ) {
    }

    public function isAvailable(): bool
    {
        if (!file_exists($this->fractorBinaryPath)) {
            $this->logger->warning('Fractor binary not found', [
                'path' => $this->fractorBinaryPath,
            ]);

            return false;
        }

        try {
            // Test with version command which doesn't require config
            $process = new Process([
                'php',
                $this->fractorBinaryPath,
                '--version',
            ]);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable $e) {
            $this->logger->warning('Fractor availability check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function execute(string $configPath, string $targetPath, bool $dryRun = true): FractorExecutionResult
    {
        $this->logger->info('Executing Fractor analysis', [
            'config' => $configPath,
            'target' => $targetPath,
            'dry_run' => $dryRun,
        ]);

        $command = [
            'php',
            $this->fractorBinaryPath,
            'process',
            '--config',
            $configPath,
        ];

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        // Note: Fractor doesn't support --output-format=json, it outputs text format
        // We'll parse the text output in the result parser

        $process = new Process($command);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();

            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();

            $this->logger->debug('Fractor execution completed', [
                'exit_code' => $process->getExitCode(),
                'output_length' => \strlen($output),
                'error_length' => \strlen($errorOutput),
            ]);

            return new FractorExecutionResult(
                $process->getExitCode() ?? 1,
                $output,
                $errorOutput,
                $process->isSuccessful(),
            );
        } catch (ProcessFailedException $e) {
            $this->logger->error('Fractor process failed', [
                'error' => $e->getMessage(),
                'command' => $process->getCommandLine(),
            ]);

            throw new FractorExecutionException('Fractor execution failed: ' . $e->getMessage(), previous: $e);
        }
    }
}
