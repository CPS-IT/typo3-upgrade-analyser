<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Application;

use CPSIT\UpgradeAnalyzer\Application\AnalyzerApplication;
use CPSIT\UpgradeAnalyzer\Application\Command\AnalyzeCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\InitConfigCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\ListAnalyzersCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\ListExtensionsCommand;
use CPSIT\UpgradeAnalyzer\Application\Command\ValidateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Application\AnalyzerApplication
 */
class AnalyzerApplicationTest extends TestCase
{
    private AnalyzerApplication $subject;

    protected function setUp(): void
    {
        $this->subject = new AnalyzerApplication();
    }

    public function testExtendsSymfonyApplication(): void
    {
        self::assertInstanceOf(Application::class, $this->subject);
    }

    public function testHasCorrectNameAndVersion(): void
    {
        self::assertSame('TYPO3 Upgrade Analyzer', $this->subject->getName());
        self::assertSame('1.0.0', $this->subject->getVersion());
    }

    public function testGetContainerReturnsContainerInterface(): void
    {
        $container = $this->subject->getContainer();

        self::assertInstanceOf(ContainerInterface::class, $container);
    }

    public function testAllCommandsAreRegistered(): void
    {
        $expectedCommands = [
            AnalyzeCommand::getDefaultName(),
            InitConfigCommand::getDefaultName(),
            ListAnalyzersCommand::getDefaultName(),
            ListExtensionsCommand::getDefaultName(),
            ValidateCommand::getDefaultName(),
        ];

        foreach ($expectedCommands as $commandName) {
            self::assertTrue(
                $this->subject->has($commandName),
                \sprintf('Command "%s" is not registered', $commandName),
            );
        }
    }

    public function testAnalyzeCommandIsRegistered(): void
    {
        $command = $this->subject->find('analyze');

        self::assertInstanceOf(AnalyzeCommand::class, $command);
    }

    public function testInitConfigCommandIsRegistered(): void
    {
        $command = $this->subject->find('init-config');

        self::assertInstanceOf(InitConfigCommand::class, $command);
    }

    public function testListAnalyzersCommandIsRegistered(): void
    {
        $command = $this->subject->find('list-analyzers');

        self::assertInstanceOf(ListAnalyzersCommand::class, $command);
    }

    public function testListExtensionsCommandIsRegistered(): void
    {
        $command = $this->subject->find('list-extensions');

        self::assertInstanceOf(ListExtensionsCommand::class, $command);
    }

    public function testValidateCommandIsRegistered(): void
    {
        $command = $this->subject->find('validate');

        self::assertInstanceOf(ValidateCommand::class, $command);
    }

    public function testContainerReturnsSameInstance(): void
    {
        $container1 = $this->subject->getContainer();
        $container2 = $this->subject->getContainer();

        self::assertSame($container1, $container2);
    }
}
