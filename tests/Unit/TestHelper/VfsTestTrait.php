<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\TestHelper;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

/**
 * Trait providing VFS setup for tests that need realistic TYPO3 directory structures.
 */
trait VfsTestTrait
{
    private vfsStreamDirectory $vfsRoot;

    /**
     * Set up VFS with realistic TYPO3 directory structure.
     */
    protected function setUpVfs(): void
    {
        $this->vfsRoot = vfsStream::setup('typo3', 0o755, [
            'vendor' => [
                'typo3' => [
                    'cms-core' => [
                        'composer.json' => '{"name": "typo3/cms-core"}',
                    ],
                ],
            ],
            'public' => [
                'typo3conf' => [
                    'ext' => [
                        'test_extension' => [
                            'ext_emconf.php' => $this->getExtEmconfContent(),
                            'composer.json' => $this->getComposerJsonContent(),
                            'Classes' => [
                                'Controller' => [
                                    'TestController.php' => $this->getPhpClassContent(),
                                ],
                                'Service' => [
                                    'TestService.php' => $this->getPhpClassContent(),
                                ],
                            ],
                            'Resources' => [
                                'Private' => [
                                    'Templates' => [
                                        'Test' => [
                                            'List.html' => $this->getFluidTemplateContent(),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'typo3' => [
                'sysext' => [
                    'core' => [
                        'composer.json' => '{"name": "typo3/cms-core"}',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Create analysis context with VFS paths.
     */
    protected function createAnalysisContextWithVfs(): AnalysisContext
    {
        $currentVersion = Version::fromString('12.4.0');
        $targetVersion = Version::fromString('13.0.0');

        // Use direct URL construction since ->url() sometimes returns empty
        $vfsUrl = 'vfs://' . $this->vfsRoot->getName();

        return new AnalysisContext($currentVersion, $targetVersion, [
            'installation_path' => $vfsUrl,
            'custom_paths' => [
                'vendor-dir' => 'vendor',
                'web-dir' => 'public',
                'typo3conf-dir' => 'public/typo3conf',
            ],
        ]);
    }

    /**
     * Get VFS root URL for absolute path references.
     */
    protected function getVfsRootUrl(): string
    {
        return $this->vfsRoot->url();
    }

    /**
     * Get extension path in VFS.
     */
    protected function getExtensionPath(string $extensionKey): string
    {
        return $this->vfsRoot->url() . '/public/typo3conf/ext/' . $extensionKey;
    }

    /**
     * Add extension to VFS structure.
     */
    protected function addExtensionToVfs(string $extensionKey, array $files = []): string
    {
        $extensionDir = vfsStream::newDirectory($extensionKey, 0o755);

        // Add default files
        $extensionDir->addChild(vfsStream::newFile('ext_emconf.php')->setContent($this->getExtEmconfContent()));
        $extensionDir->addChild(vfsStream::newFile('composer.json')->setContent($this->getComposerJsonContent()));

        // Add Classes directory
        $classesDir = vfsStream::newDirectory('Classes', 0o755);
        $controllerDir = vfsStream::newDirectory('Controller', 0o755);
        $controllerDir->addChild(vfsStream::newFile('TestController.php')->setContent($this->getPhpClassContent()));
        $classesDir->addChild($controllerDir);
        $extensionDir->addChild($classesDir);

        // Add custom files
        foreach ($files as $path => $content) {
            $pathParts = explode('/', $path);
            $fileName = array_pop($pathParts);

            $currentDir = $extensionDir;
            foreach ($pathParts as $dirName) {
                $childDir = $currentDir->getChild($dirName);
                if (null === $childDir) {
                    $childDir = vfsStream::newDirectory($dirName, 0o755);
                    $currentDir->addChild($childDir);
                }
                $currentDir = $childDir;
            }

            $currentDir->addChild(vfsStream::newFile($fileName)->setContent($content));
        }

        // Add to VFS root structure
        $extDir = $this->vfsRoot->getChild('public/typo3conf/ext');
        if ($extDir instanceof vfsStreamDirectory) {
            $extDir->addChild($extensionDir);
        }

        return $this->vfsRoot->url() . '/public/typo3conf/ext/' . $extensionKey;
    }

    /**
     * Create temporary config file in VFS.
     */
    protected function createTempConfigFile(string $content): string
    {
        $tempDir = $this->vfsRoot->hasChild('temp')
            ? $this->vfsRoot->getChild('temp')
            : vfsStream::newDirectory('temp', 0o755);

        if (!$this->vfsRoot->hasChild('temp')) {
            $this->vfsRoot->addChild($tempDir);
        }

        $configFile = vfsStream::newFile('fractor_config_' . uniqid() . '.php')->setContent($content);
        if ($tempDir instanceof vfsStreamDirectory) {
            $tempDir->addChild($configFile);
        }

        return $tempDir->url() . '/' . $configFile->getName();
    }

    private function getExtEmconfContent(): string
    {
        return <<<'PHP'
            <?php

            $EM_CONF['test_extension'] = [
                'title' => 'Test Extension',
                'description' => 'Test extension for analyzer tests',
                'category' => 'misc',
                'version' => '1.0.0',
                'state' => 'stable',
                'clearCacheOnLoad' => 1,
                'author' => 'Test Author',
                'author_email' => 'test@example.com',
                'constraints' => [
                    'depends' => [
                        'typo3' => '11.5.0-13.9.99',
                    ],
                ],
            ];
            PHP;
    }

    private function getComposerJsonContent(): string
    {
        $content = json_encode([
            'name' => 'vendor/test-extension',
            'type' => 'typo3-cms-extension',
            'description' => 'Test extension for analyzer tests',
            'require' => [
                'php' => '^8.1',
                'typo3/cms-core' => '^11.5 || ^12.4 || ^13.0',
            ],
            'extra' => [
                'typo3/cms' => [
                    'extension-key' => 'test_extension',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        if (false === $content) {
            throw new \RuntimeException('Failed to encode JSON for composer.json content');
        }

        return $content;
    }

    private function getPhpClassContent(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Vendor\TestExtension\Controller;

            use TYPO3\CMS\Core\Utility\GeneralUtility;

            class TestController
            {
                public function indexAction(): void
                {
                    $data = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class);
                }
            }
            PHP;
    }

    private function getFluidTemplateContent(): string
    {
        return <<<'HTML'
            <html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers" data-namespace-typo3-fluid="true">
            <f:layout name="Default" />

            <f:section name="Content">
                <f:for each="{items}" as="item">
                    <f:link.action action="show" arguments="{item: item}" noCacheHash="TRUE">{item.title}</f:link.action>
                </f:for>
            </f:section>
            </html>
            HTML;
    }
}
