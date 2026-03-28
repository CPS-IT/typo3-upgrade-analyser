<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ComposerEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[CoversClass(ComposerEnvironment::class)]
class ComposerEnvironmentTest extends TestCase
{
    private function makeSuccessProcess(string $stdout): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($stdout);

        return $process;
    }

    private function makeFailProcess(): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn(1);
        $process->method('isSuccessful')->willReturn(false);

        return $process;
    }

    private function makeTimedOutProcess(): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willThrowException(
            new ProcessTimedOutException($this->createStub(Process::class), ProcessTimedOutException::TYPE_GENERAL),
        );

        return $process;
    }

    private function makeEnvironment(Process $process): ComposerEnvironment
    {
        return new ComposerEnvironment(new NullLogger(), 30, fn (array $cmd): Process => $process);
    }

    public function testReturnsTrueForSufficientVersion(): void
    {
        $env = $this->makeEnvironment($this->makeSuccessProcess('Composer version 2.8.9 2024-11-01 09:34:21 UTC'));

        self::assertTrue($env->isVersionSufficient());
    }

    public function testReturnsFalseForVersionBelowMinimum(): void
    {
        $env = $this->makeEnvironment($this->makeSuccessProcess('Composer version 2.0.14 2021-05-21 17:03:37'));

        self::assertFalse($env->isVersionSufficient());
    }

    public function testReturnsFalseWhenVersionStringIsUnparseable(): void
    {
        $env = $this->makeEnvironment($this->makeSuccessProcess('some unexpected output without version'));

        self::assertFalse($env->isVersionSufficient());
    }

    public function testReturnsFalseWhenProcessFails(): void
    {
        $env = $this->makeEnvironment($this->makeFailProcess());

        self::assertFalse($env->isVersionSufficient());
    }

    public function testReturnsFalseOnTimeout(): void
    {
        $env = $this->makeEnvironment($this->makeTimedOutProcess());

        self::assertFalse($env->isVersionSufficient());
    }

    public function testResultIsCached(): void
    {
        $callCount = 0;
        $env = new ComposerEnvironment(
            new NullLogger(),
            30,
            function (array $cmd) use (&$callCount): Process {
                ++$callCount;

                return $this->makeSuccessProcess('Composer version 2.8.9 2024-11-01 09:34:21 UTC');
            },
        );

        $env->isVersionSufficient();
        $env->isVersionSufficient();

        self::assertSame(1, $callCount);
    }
}
