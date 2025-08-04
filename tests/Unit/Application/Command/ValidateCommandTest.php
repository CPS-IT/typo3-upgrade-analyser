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

use CPSIT\UpgradeAnalyzer\Application\Command\ValidateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test case for the ValidateCommand.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Application\Command\ValidateCommand
 */
class ValidateCommandTest extends TestCase
{
    private ValidateCommand $command;
    private CommandTester $commandTester;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->command = new ValidateCommand();

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);
        $this->tempDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDir();
    }

    public function testCommandConfiguration(): void
    {
        self::assertEquals('validate', $this->command->getName());
        self::assertEquals('Validate a TYPO3 installation for analysis compatibility', $this->command->getDescription());

        $definition = $this->command->getDefinition();

        // Check required argument
        self::assertTrue($definition->hasArgument('path'));
        self::assertTrue($definition->getArgument('path')->isRequired());
        self::assertEquals('Path to the TYPO3 installation', $definition->getArgument('path')->getDescription());

        // Should have no options
        self::assertCount(0, $definition->getOptions());
    }

    public function testExecuteWithNonExistentPath(): void
    {
        $this->commandTester->execute([
            'path' => '/non/existent/path',
        ]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('TYPO3 Installation Validation', $display);
        self::assertStringContainsString('Validating: /non/existent/path', $display);
        self::assertStringContainsString('Directory does not exist', $display);
        self::assertStringContainsString('❌', $display);
    }

    public function testExecuteWithEmptyDirectory(): void
    {
        $this->commandTester->execute([
            'path' => $this->tempDir,
        ]);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Path exists', $display);
        self::assertStringContainsString('✅', $display);
        self::assertStringContainsString('No TYPO3 installation indicators found', $display);
    }

    public function testExecuteWithValidTYPO3Installation(): void
    {
        // Create TYPO3 installation structure
        mkdir($this->tempDir . '/typo3conf');
        touch($this->tempDir . '/typo3conf/LocalConfiguration.php');

        $this->commandTester->execute([
            'path' => $this->tempDir,
        ]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('TYPO3 installation is valid and ready for analysis!', $display);
        self::assertStringContainsString('✅', $display);
    }

    public function testExecuteWithComposerModeInstallation(): void
    {
        // Create Composer-mode TYPO3 installation
        mkdir($this->tempDir . '/vendor/typo3/cms-core', 0o755, true);
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'require' => [
                'typo3/cms-core' => '^12.4',
            ],
        ]));

        $this->commandTester->execute([
            'path' => $this->tempDir,
        ]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('TYPO3 Core found (Composer mode)', $display);
        self::assertStringContainsString('Composer configuration found', $display);
        self::assertStringContainsString('✅', $display);
    }

    public function testExecuteWithMultipleIndicators(): void
    {
        // Create multiple TYPO3 indicators
        mkdir($this->tempDir . '/typo3conf');
        touch($this->tempDir . '/typo3conf/LocalConfiguration.php');
        mkdir($this->tempDir . '/typo3');
        touch($this->tempDir . '/typo3/index.php');
        file_put_contents($this->tempDir . '/composer.json', '{}');

        $this->commandTester->execute([
            'path' => $this->tempDir,
        ]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('LocalConfiguration found', $display);
        self::assertStringContainsString('TYPO3 backend entry point found', $display);
        self::assertStringContainsString('Composer configuration found', $display);
    }

    public function testValidationChecksTable(): void
    {
        mkdir($this->tempDir . '/typo3conf');
        touch($this->tempDir . '/typo3conf/LocalConfiguration.php');

        $this->commandTester->execute([
            'path' => $this->tempDir,
        ]);

        $display = $this->commandTester->getDisplay();

        // Check table headers
        self::assertStringContainsString('Check', $display);
        self::assertStringContainsString('Status', $display);
        self::assertStringContainsString('Details', $display);

        // Check specific validation items
        self::assertStringContainsString('Path exists', $display);
        self::assertStringContainsString('Read permissions', $display);
        self::assertStringContainsString('Database configuration', $display);
    }

    public function testDetectTYPO3VersionFromComposer(): void
    {
        // Create installation with version info in composer.json
        mkdir($this->tempDir . '/typo3conf');
        touch($this->tempDir . '/typo3conf/LocalConfiguration.php');

        $composerData = [
            'require' => [
                'typo3/cms-core' => '^12.4',
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composerData));

        $this->commandTester->execute([
            'path' => $this->tempDir,
        ]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Detected TYPO3 version: ^12.4', $display);
    }

    public function testDetectTYPO3VersionFromSystemExtension(): void
    {
        // Create installation with version info in system extension
        mkdir($this->tempDir . '/typo3conf');
        touch($this->tempDir . '/typo3conf/LocalConfiguration.php');

        $versionFilePath = $this->tempDir . '/typo3/sysext/core/Classes/Information/Typo3Version.php';
        mkdir(\dirname($versionFilePath), 0o755, true);

        $versionFileContent = '<?php
class Typo3Version {
    const VERSION = "12.4.8";
}';
        file_put_contents($versionFilePath, $versionFileContent);

        $this->commandTester->execute([
            'path' => $this->tempDir,
        ]);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Detected TYPO3 version: 12.4.8', $display);
    }

    public function testReadPermissionsCheck(): void
    {
        // Create a directory but make it unreadable (if possible)
        mkdir($this->tempDir . '/unreadable');

        // Note: This test may not work on all systems due to permission handling
        if (is_readable($this->tempDir . '/unreadable')) {
            $this->markTestSkipped('Cannot test unreadable directory on this system');
        }

        $this->commandTester->execute([
            'path' => $this->tempDir . '/unreadable',
        ]);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Read permissions', $display);
    }

    public function testDatabaseConfigurationWarning(): void
    {
        // Create installation without LocalConfiguration.php
        mkdir($this->tempDir . '/typo3');
        touch($this->tempDir . '/typo3/index.php');

        $this->commandTester->execute([
            'path' => $this->tempDir,
        ]);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Database configuration', $display);
        self::assertStringContainsString('⚠️', $display);
        self::assertStringContainsString('LocalConfiguration.php not accessible', $display);
    }

    public function testValidationTitle(): void
    {
        $testPath = '/some/test/path';

        $this->commandTester->execute([
            'path' => $testPath,
        ]);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('TYPO3 Installation Validation', $display);
        self::assertStringContainsString("Validating: $testPath", $display);
    }

    public function testCommandWorksWithRelativePaths(): void
    {
        // Test with relative path
        $relativePath = basename($this->tempDir);
        $parentDir = \dirname($this->tempDir);

        // Change to parent directory context (simulate relative path usage)
        $oldCwd = getcwd();
        chdir($parentDir);

        try {
            mkdir($relativePath . '/typo3conf');
            touch($relativePath . '/typo3conf/LocalConfiguration.php');

            $this->commandTester->execute([
                'path' => $relativePath,
            ]);

            self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
        } finally {
            chdir($oldCwd);
        }
    }

    private function cleanupTempDir(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }

            rmdir($this->tempDir);
        }
    }
}
