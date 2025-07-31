<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Test case for the Extension entity
 *
 * @covers \CPSIT\UpgradeAnalyzer\Domain\Entity\Extension
 */
class ExtensionTest extends TestCase
{
    private Extension $extension;
    private Version $version;

    protected function setUp(): void
    {
        $this->version = new Version('1.2.3');
        $this->extension = new Extension(
            'test_extension',
            'Test Extension',
            $this->version,
            'local',
            'vendor/test-extension'
        );
    }

    public function testConstructorSetsProperties(): void
    {
        self::assertEquals('test_extension', $this->extension->getKey());
        self::assertEquals('Test Extension', $this->extension->getTitle());
        self::assertSame($this->version, $this->extension->getVersion());
        self::assertEquals('local', $this->extension->getType());
        self::assertEquals('vendor/test-extension', $this->extension->getComposerName());
    }

    public function testDefaultTypeIsLocal(): void
    {
        $extension = new Extension('test_ext', 'Test', new Version('1.0.0'));
        self::assertEquals('local', $extension->getType());
    }

    public function testComposerNameCanBeNull(): void
    {
        $extension = new Extension('test_ext', 'Test', new Version('1.0.0'));
        self::assertNull($extension->getComposerName());
        self::assertFalse($extension->hasComposerName());
    }

    public function testHasComposerName(): void
    {
        self::assertTrue($this->extension->hasComposerName());
        
        $extensionWithoutComposer = new Extension('test_ext', 'Test', new Version('1.0.0'));
        self::assertFalse($extensionWithoutComposer->hasComposerName());
    }

    public function testTypeCheckers(): void
    {
        // Test local extension
        self::assertTrue($this->extension->isLocalExtension());
        self::assertFalse($this->extension->isSystemExtension());
        self::assertFalse($this->extension->isTerExtension());
        
        // Test system extension
        $systemExtension = new Extension('core', 'Core', new Version('12.4.0'), 'system');
        self::assertFalse($systemExtension->isLocalExtension());
        self::assertTrue($systemExtension->isSystemExtension());
        self::assertFalse($systemExtension->isTerExtension());
        
        // Test TER extension
        $terExtension = new Extension('news', 'News', new Version('8.7.0'), 'ter');
        self::assertFalse($terExtension->isLocalExtension());
        self::assertFalse($terExtension->isSystemExtension());
        self::assertTrue($terExtension->isTerExtension());
    }

    public function testAddAndGetDependencies(): void
    {
        $this->extension->addDependency('core', '12.4.0');
        $this->extension->addDependency('extbase');
        
        $dependencies = $this->extension->getDependencies();
        
        self::assertCount(2, $dependencies);
        self::assertArrayHasKey('core', $dependencies);
        self::assertArrayHasKey('extbase', $dependencies);
        self::assertEquals('12.4.0', $dependencies['core']);
        self::assertNull($dependencies['extbase']);
        
        self::assertTrue($this->extension->hasDependency('core'));
        self::assertTrue($this->extension->hasDependency('extbase'));
        self::assertFalse($this->extension->hasDependency('fluid'));
    }

    public function testFileManagement(): void
    {
        $phpFile = '/path/to/Classes/Controller/TestController.php';
        $tcaFile = '/path/to/Configuration/TCA/tx_test.php';
        $templateFile = '/path/to/Resources/Private/Templates/Test.html';
        $fluidFile = '/path/to/Resources/Private/Partials/Header.fluid';
        $jsFile = '/path/to/Resources/Public/JavaScript/test.js';
        
        $this->extension->addFile($phpFile);
        $this->extension->addFile($tcaFile);
        $this->extension->addFile($templateFile);
        $this->extension->addFile($fluidFile);
        $this->extension->addFile($jsFile);
        
        $allFiles = $this->extension->getFiles();
        self::assertCount(5, $allFiles);
        self::assertContains($phpFile, $allFiles);
        self::assertContains($tcaFile, $allFiles);
        self::assertContains($templateFile, $allFiles);
        self::assertContains($fluidFile, $allFiles);
        self::assertContains($jsFile, $allFiles);
    }

    public function testGetPhpFiles(): void
    {
        $phpFile1 = '/path/to/Classes/Controller/TestController.php';
        $phpFile2 = '/path/to/Configuration/TCA/tx_test.php';
        $jsFile = '/path/to/Resources/Public/JavaScript/test.js';
        $htmlFile = '/path/to/Resources/Private/Templates/Test.html';
        
        $this->extension->addFile($phpFile1);
        $this->extension->addFile($phpFile2);
        $this->extension->addFile($jsFile);
        $this->extension->addFile($htmlFile);
        
        $phpFiles = $this->extension->getPhpFiles();
        
        self::assertCount(2, $phpFiles);
        self::assertContains($phpFile1, $phpFiles);
        self::assertContains($phpFile2, $phpFiles);
        self::assertNotContains($jsFile, $phpFiles);
        self::assertNotContains($htmlFile, $phpFiles);
    }

    public function testGetTcaFiles(): void
    {
        $tcaFile = '/path/to/Configuration/TCA/tx_test.php';
        $tcaOverrideFile = '/path/to/Configuration/TCA/Overrides/pages.php';
        $regularPhpFile = '/path/to/Classes/Controller/TestController.php';
        
        $this->extension->addFile($tcaFile);
        $this->extension->addFile($tcaOverrideFile);
        $this->extension->addFile($regularPhpFile);
        
        $tcaFiles = $this->extension->getTcaFiles();
        
        self::assertCount(2, $tcaFiles);
        self::assertContains($tcaFile, $tcaFiles);
        self::assertContains($tcaOverrideFile, $tcaFiles);
        self::assertNotContains($regularPhpFile, $tcaFiles);
    }

    public function testGetTemplateFiles(): void
    {
        $htmlFile = '/path/to/Resources/Private/Templates/Test.html';
        $fluidFile = '/path/to/Resources/Private/Partials/Header.fluid';
        $phpFile = '/path/to/Classes/Controller/TestController.php';
        $jsFile = '/path/to/Resources/Public/JavaScript/test.js';
        
        $this->extension->addFile($htmlFile);
        $this->extension->addFile($fluidFile);
        $this->extension->addFile($phpFile);
        $this->extension->addFile($jsFile);
        
        $templateFiles = $this->extension->getTemplateFiles();
        
        self::assertCount(2, $templateFiles);
        self::assertContains($htmlFile, $templateFiles);
        self::assertContains($fluidFile, $templateFiles);
        self::assertNotContains($phpFile, $templateFiles);
        self::assertNotContains($jsFile, $templateFiles);
    }

    public function testGetLinesOfCodeWithExistingFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/extension-test-' . uniqid();
        mkdir($tempDir, 0755, true);
        
        try {
            // Create test files with known line counts
            $file1 = $tempDir . '/file1.php';
            $file2 = $tempDir . '/file2.php';
            $file3 = $tempDir . '/file3.js'; // Non-PHP file, should be ignored
            
            file_put_contents($file1, "<?php\n// Line 1\n// Line 2\n");  // 3 lines
            file_put_contents($file2, "<?php\n// Line 1\n// Line 2\n// Line 3\n");  // 4 lines
            file_put_contents($file3, "// JavaScript file\nconsole.log('test');\n");  // 2 lines (ignored)
            
            $this->extension->addFile($file1);
            $this->extension->addFile($file2);
            $this->extension->addFile($file3);
            
            $linesOfCode = $this->extension->getLinesOfCode();
            
            // Should count only PHP files: 3 + 4 = 7 lines
            self::assertEquals(7, $linesOfCode);
            
        } finally {
            // Clean up
            if (file_exists($tempDir . '/file1.php')) unlink($tempDir . '/file1.php');
            if (file_exists($tempDir . '/file2.php')) unlink($tempDir . '/file2.php');
            if (file_exists($tempDir . '/file3.js')) unlink($tempDir . '/file3.js');
            if (is_dir($tempDir)) rmdir($tempDir);
        }
    }

    public function testGetLinesOfCodeWithNonExistentFiles(): void
    {
        $this->extension->addFile('/non/existent/file.php');
        
        // Should return 0 for non-existent files
        self::assertEquals(0, $this->extension->getLinesOfCode());
    }

    public function testGetLinesOfCodeWithEmptyFileList(): void
    {
        $extension = new Extension('empty_ext', 'Empty Extension', new Version('1.0.0'));
        
        self::assertEquals(0, $extension->getLinesOfCode());
    }
}