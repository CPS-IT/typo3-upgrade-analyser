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
        private readonly ?string $projectRoot = null,
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
            // Create a temporary minimal config file for the availability check
            $tempConfigPath = sys_get_temp_dir() . '/fractor_availability_check_' . uniqid() . '.php';
            $minimalConfig = <<<'PHP'
                <?php

                declare(strict_types=1);

                use a9f\Fractor\Configuration\FractorConfiguration;

                return FractorConfiguration::configure()
                    ->withPaths([])
                    ->withSets([]);
                PHP;
            file_put_contents($tempConfigPath, $minimalConfig);

            try {
                // Test with version command using the temporary config
                $process = new Process([
                    'php',
                    $this->fractorBinaryPath,
                    '--version',
                    '--config',
                    $tempConfigPath,
                ]);
                $process->run();

                return $process->isSuccessful();
            } finally {
                // Clean up temporary config file
                if (file_exists($tempConfigPath)) {
                    unlink($tempConfigPath);
                }
            }
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
        // Set working directory to project root to ensure consistent path resolution
        $workingDirectory = $this->projectRoot ?? $this->findProjectRoot();
        $process->setWorkingDirectory($workingDirectory);

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

    /**
     * Find project root by searching upwards for characteristic files.
     * Searches from the binary path upwards until it finds composer.json or bin/typo3-analyzer.
     */
    private function findProjectRoot(): string
    {
        $currentDir = dirname($this->fractorBinaryPath);
        $maxLevels = 10; // Prevent infinite loops

        for ($i = 0; $i < $maxLevels; ++$i) {
            // Check for characteristic project files
            if (file_exists($currentDir . '/composer.json')
                || file_exists($currentDir . '/bin/typo3-analyzer')
            ) {
                return $currentDir;
            }

            $parentDir = dirname($currentDir);

            // Reached filesystem root
            if ($parentDir === $currentDir) {
                break;
            }

            $currentDir = $parentDir;
        }

        // Fallback: Use the directory 3 levels up from binary (backwards compatibility)
        return dirname($this->fractorBinaryPath, 3);
    }
}
