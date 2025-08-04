<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Application\Command;

use CPSIT\UpgradeAnalyzer\Application\Command\AnalyzeCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test case for the AnalyzeCommand.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Application\Command\AnalyzeCommand
 */
class AnalyzeCommandTest extends TestCase
{
    private LoggerInterface $logger;
    private AnalyzeCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->command = new AnalyzeCommand($this->logger);

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    public function testCommandConfiguration(): void
    {
        self::assertEquals('analyze', $this->command->getName());
        self::assertEquals('Analyze a TYPO3 installation for upgrade readiness', $this->command->getDescription());

        $definition = $this->command->getDefinition();

        // Check required argument
        self::assertTrue($definition->hasArgument('path'));
        self::assertTrue($definition->getArgument('path')->isRequired());

        // Check options
        self::assertTrue($definition->hasOption('target-version'));
        self::assertTrue($definition->hasOption('config'));
        self::assertTrue($definition->hasOption('format'));
        self::assertTrue($definition->hasOption('output-dir'));
        self::assertTrue($definition->hasOption('analyzers'));
        self::assertTrue($definition->hasOption('dry-run'));
    }

    public function testExecuteWithNonExistentPath(): void
    {
        $this->commandTester->execute([
            'path' => '/non/existent/path',
        ]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Directory does not exist', $this->commandTester->getDisplay());
    }

    public function testExecuteWithInvalidTYPO3Installation(): void
    {
        // Create a temporary directory that's not a TYPO3 installation
        $tempDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($tempDir);

        try {
            $this->commandTester->execute([
                'path' => $tempDir,
            ]);

            self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
            self::assertStringContainsString('does not appear to be a valid TYPO3 installation', $this->commandTester->getDisplay());
        } finally {
            rmdir($tempDir);
        }
    }

    public function testExecuteWithValidTYPO3Installation(): void
    {
        // Create a temporary directory with TYPO3 indicators
        $tempDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/typo3conf');
        touch($tempDir . '/typo3conf/LocalConfiguration.php');

        try {
            $this->logger->expects(self::once())
                ->method('info')
                ->with('Starting TYPO3 upgrade analysis', self::isType('array'));

            $this->commandTester->execute([
                'path' => $tempDir,
                '--target-version' => '12.4',
            ]);

            self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
            self::assertStringContainsString('Analysis completed successfully', $this->commandTester->getDisplay());
        } finally {
            unlink($tempDir . '/typo3conf/LocalConfiguration.php');
            rmdir($tempDir . '/typo3conf');
            rmdir($tempDir);
        }
    }

    public function testDefaultOptions(): void
    {
        $definition = $this->command->getDefinition();

        self::assertEquals('12.4', $definition->getOption('target-version')->getDefault());
        self::assertEquals(['html', 'json'], $definition->getOption('format')->getDefault());
        self::assertEquals('tests/upgradeAnalysis', $definition->getOption('output-dir')->getDefault());
    }
}
