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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
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
        // Create mock analyzers
        $mockAnalyzers = [
            $this->createMockAnalyzer('version_availability', 'Checks if compatible versions exist in TER, Packagist, or Git repositories', true, ['curl']),
            $this->createMockAnalyzer('lines_of_code', 'Analyzes lines of code in extension files to assess codebase size and complexity', true, []),
            $this->createMockAnalyzer('typo3_rector', 'Uses TYPO3 Rector to detect deprecated code patterns, breaking changes, and upgrade requirements', false, ['php', 'rector']),
        ];

        $this->command = new ListAnalyzersCommand($mockAnalyzers);

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    private function createMockAnalyzer(string $name, string $description, bool $hasTools, array $requiredTools): MockObject&AnalyzerInterface
    {
        $mock = $this->createMock(AnalyzerInterface::class);
        $mock->method('getName')->willReturn($name);
        $mock->method('getDescription')->willReturn($description);
        $mock->method('hasRequiredTools')->willReturn($hasTools);
        $mock->method('getRequiredTools')->willReturn($requiredTools);

        return $mock;
    }

    public function testCommandConfiguration(): void
    {
        self::assertEquals('list-analyzers', $this->command->getName());
        self::assertEquals('List all registered analyzers', $this->command->getDescription());

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
        self::assertStringContainsString('Registered Analyzers', $display);

        // Check table headers
        self::assertStringContainsString('Name', $display);
        self::assertStringContainsString('Description', $display);
        self::assertStringContainsString('OK', $display);
        self::assertStringContainsString('Tools', $display);

        // Check note about usage
        self::assertStringContainsString('Use the --analyzers option with the analyze command', $display);

        // Check that our mock analyzers are displayed
        self::assertStringContainsString('version_availability', $display);
        self::assertStringContainsString('lines_of_code', $display);
        self::assertStringContainsString('typo3_rector', $display);
    }

    public function testDisplaysExpectedAnalyzers(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // Test for mock analyzers that should be listed
        $expectedAnalyzers = [
            'version_availability',
            'lines_of_code',
            'typo3_rector',
        ];

        foreach ($expectedAnalyzers as $analyzer) {
            self::assertStringContainsString($analyzer, $display);
        }
    }

    public function testDisplaysAnalyzerDescriptions(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // Test for truncated mock analyzer descriptions
        $expectedDescriptions = [
            'Checks if compatible versions exist in TER, Pac...',
            'Analyzes lines of code in extension files to as...',
            'Uses TYPO3 Rector to detect deprecated code pat...',
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
        self::assertStringContainsString('✅', $display);
        self::assertStringContainsString('❌', $display);
    }

    public function testShowsAvailableAnalyzersFirst(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // These mock analyzers should be marked as "Available"
        $availableAnalyzers = [
            'version_availability',
            'lines_of_code',
        ];

        foreach ($availableAnalyzers as $analyzer) {
            // Find the analyzer name in the output
            $analyzerPos = strpos($display, $analyzer);
            self::assertNotFalse($analyzerPos, "Analyzer '$analyzer' not found in output");

            // Look for "✅" status after the analyzer name
            $statusPos = strpos($display, '✅', $analyzerPos);
            self::assertNotFalse($statusPos, "Available status not found for analyzer '$analyzer'");
        }
    }

    public function testShowsAnalyzersWithMissingTools(): void
    {
        $this->commandTester->execute([]);
        $display = $this->commandTester->getDisplay();

        // This mock analyzer should be marked as "Missing tools"
        $unavailableAnalyzers = [
            'typo3_rector',
        ];

        foreach ($unavailableAnalyzers as $analyzer) {
            // Find the analyzer name in the output
            $analyzerPos = strpos($display, $analyzer);
            self::assertNotFalse($analyzerPos, "Analyzer '$analyzer' not found in output");

            // Look for "❌" status after the analyzer name
            $statusPos = strpos($display, '❌', $analyzerPos);
            self::assertNotFalse($statusPos, "Missing tools status not found for analyzer '$analyzer'");
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
        self::assertGreaterThanOrEqual(8, \count($contentLines));
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
