<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Holds runtime state about the Composer environment available on the host.
 *
 * Registered as a shared (singleton) service so the version check is executed
 * at most once per analysis run, regardless of how many resolvers use it.
 */
class ComposerEnvironment
{
    private ?bool $versionOk = null;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 30,
        private readonly ?\Closure $processFactory = null,
    ) {
    }

    /**
     * Returns true when a Composer 2.1+ binary is available and responsive.
     * Result is cached for the lifetime of this instance.
     */
    public function isVersionSufficient(): bool
    {
        if (null !== $this->versionOk) {
            return $this->versionOk;
        }

        $process = $this->createProcess(['composer', '--version']);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->versionOk = false;
        }

        if (!$process->isSuccessful()) {
            return $this->versionOk = false;
        }

        if (!preg_match('/version (\d+\.\d+)/', $process->getOutput(), $m)) {
            $this->logger->warning('Could not determine Composer version from output; assuming incompatible');

            return $this->versionOk = false;
        }

        if (version_compare($m[1], '2.1', '<')) {
            $this->logger->warning(
                \sprintf('Composer 2.1+ required for stable JSON output; found %s', $m[1]),
            );

            return $this->versionOk = false;
        }

        return $this->versionOk = true;
    }

    /**
     * @param list<string> $command
     */
    private function createProcess(array $command): Process
    {
        return null !== $this->processFactory
            ? ($this->processFactory)($command)
            : new Process($command);
    }
}
