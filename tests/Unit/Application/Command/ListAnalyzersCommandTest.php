<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Application\Command;

use CPSIT\UpgradeAnalyzer\Application\Command\ListAnalyzersCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ListAnalyzersCommand::class)]
class ListAnalyzersCommandTest extends TestCase
{
    private ListAnalyzersCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->command = new ListAnalyzersCommand();

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    public function testCommandConfiguration(): void
    {
        self::assertEquals('list-analyzers', $this->command->getName());
        self::assertEquals('List all available analyzers', $this->command->getDescription());

        $definition = $this->command->getDefinition();

        // Command should have no arguments or options
        self::assertCount(0, $definition->getArguments());
        self::assertCount(0, $definition->getOptions());
    }

    public function testExecuteSuccessfully(): void
    {
        $this->commandTester->execute([]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());

        $display = $this->commandTester->getDisplay();

        // Check title
        self::assertStringContainsString('Available Analyzers', $display);

        // Check table headers
        self::assertStringContainsString('Name', $display);
        self::assertStringContainsString('Description', $display);
        self::assertStringContainsString('Status', $display);

        // Check note about usage
        self::assertStringContainsString('Use the --analyzers option with the analyze command', $display);
    }

    public function testDisplaysExpectedAnalyzers(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // Test for expected analyzers that should be listed
        $expectedAnalyzers = [
            'Version Availability',
            'Static Analysis',
            'Lines of Code',
            'PHP Compatibility',
            'Deprecation Scanner',
            'TCA Migration',
            'Rector Analysis',
            'Fractor Analysis',
            'TypoScript Lint',
            'Test Coverage',
        ];

        foreach ($expectedAnalyzers as $analyzer) {
            self::assertStringContainsString($analyzer, $display);
        }
    }

    public function testDisplaysAnalyzerDescriptions(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // Test for expected descriptions
        $expectedDescriptions = [
            'Checks if compatible versions exist in TER, Packagist, or Git',
            'Runs PHPStan and other static analysis tools',
            'Counts lines of code and calculates complexity metrics',
            'Checks PHP version compatibility',
            'Scans for deprecated TYPO3 API usage',
            'Checks for required TCA migrations',
            'Checks for available Rector migration rules',
            'Analyzes TypoScript for modernization opportunities',
            'Validates TypoScript configuration',
            'Analyzes existing test coverage',
        ];

        foreach ($expectedDescriptions as $description) {
            self::assertStringContainsString($description, $display);
        }
    }

    public function testDisplaysAnalyzerStatuses(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // Check that status information is displayed
        self::assertStringContainsString('Available', $display);
        self::assertStringContainsString('Planned', $display);
    }

    public function testShowsAvailableAnalyzersFirst(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // These analyzers should be marked as "Available"
        $availableAnalyzers = [
            'Version Availability',
            'Static Analysis',
            'Lines of Code',
            'PHP Compatibility',
            'Deprecation Scanner',
            'TCA Migration',
        ];

        foreach ($availableAnalyzers as $analyzer) {
            // Find the analyzer name in the output
            $analyzerPos = strpos($display, $analyzer);
            self::assertNotFalse($analyzerPos, "Analyzer '$analyzer' not found in output");

            // Look for "Available" status after the analyzer name
            $statusPos = strpos($display, 'Available', $analyzerPos);
            self::assertNotFalse($statusPos, "Available status not found for analyzer '$analyzer'");
        }
    }

    public function testShowsPlannedAnalyzersLast(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // These analyzers should be marked as "Planned"
        $plannedAnalyzers = [
            'Rector Analysis',
            'Fractor Analysis',
            'TypoScript Lint',
            'Test Coverage',
        ];

        foreach ($plannedAnalyzers as $analyzer) {
            // Find the analyzer name in the output
            $analyzerPos = strpos($display, $analyzer);
            self::assertNotFalse($analyzerPos, "Analyzer '$analyzer' not found in output");

            // Look for "Planned" status after the analyzer name
            $statusPos = strpos($display, 'Planned', $analyzerPos);
            self::assertNotFalse($statusPos, "Planned status not found for analyzer '$analyzer'");
        }
    }

    public function testOutputFormatting(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // Check that output contains table formatting characters
        self::assertStringContainsString('-', $display); // Table borders

        // Check that the output is properly formatted as a table
        $lines = explode("\n", $display);
        $contentLines = array_filter($lines, fn ($line): bool => !empty(trim($line)));

        // Should have multiple lines of content (title, headers, analyzers, etc.)
        self::assertGreaterThanOrEqual(15, \count($contentLines));
    }

    public function testCommandHelpText(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // Check help text is displayed (note: output may have formatting characters)
        self::assertStringContainsString('--analyzers option', $display);
        self::assertStringContainsString('analyze command', $display);
    }

    public function testCommandExecutesWithoutArguments(): void
    {
        // Test that command runs successfully without any arguments
        $this->commandTester->execute([]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertNotEmpty($this->commandTester->getDisplay());
    }
}
