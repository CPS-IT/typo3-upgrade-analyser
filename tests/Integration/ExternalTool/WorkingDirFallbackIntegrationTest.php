<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\ExternalTool;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\VcsAvailability;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\Source\VcsSource;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ComposerEnvironment;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ComposerVersionResolver;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionStatus;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Integration test for the --working-dir fallback path in ComposerVersionResolver.
 *
 * Creates a local Composer fixture project with a path-type private package
 * (not on Packagist) and verifies the full chain:
 *   VcsSource → ComposerVersionResolver → --working-dir fallback
 *
 * Requires Composer to be installed on the host. Skipped when Composer is
 * unavailable or when TYPO3_ANALYZER_SKIP_COMPOSER_TESTS=true.
 *
 * @group integration
 * @group composer
 */
#[CoversClass(ComposerVersionResolver::class)]
#[CoversClass(VcsSource::class)]
final class WorkingDirFallbackIntegrationTest extends TestCase
{
    private string $fixtureDir = '';
    private Filesystem $fs;

    protected function setUp(): void
    {
        parent::setUp();

        if ('true' === (getenv('TYPO3_ANALYZER_SKIP_COMPOSER_TESTS') ?: 'false')) {
            $this->markTestSkipped('Composer integration tests disabled via TYPO3_ANALYZER_SKIP_COMPOSER_TESTS');
        }

        $process = new Process(['composer', '--version']);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->markTestSkipped('Composer binary not available on this system');
        }

        $this->fs = new Filesystem();
        $this->fixtureDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid('', true);
        $this->fs->mkdir($this->fixtureDir);
        $this->fs->mkdir($this->fixtureDir . '/private-pkg');

        // Create a minimal private package (not on Packagist)
        $packageComposer = [
            'name' => 'test-vendor/private-extension',
            'type' => 'typo3-cms-extension',
            'description' => 'Test private extension',
            'require' => ['typo3/cms-core' => '^13.0'],
            'version' => '2.3.0',
        ];
        file_put_contents(
            $this->fixtureDir . '/private-pkg/composer.json',
            json_encode($packageComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        // Create the host project that declares the path repository
        $hostComposer = [
            'name' => 'test/typo3-project',
            'description' => 'Integration test fixture',
            'require' => ['test-vendor/private-extension' => '*'],
            'repositories' => [
                ['type' => 'path', 'url' => './private-pkg'],
            ],
            'config' => ['allow-plugins' => false],
            'minimum-stability' => 'dev',
        ];
        file_put_contents(
            $this->fixtureDir . '/composer.json',
            json_encode($hostComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        // Run composer install to create vendor/ and composer.lock
        $install = new Process(
            ['composer', 'install', '--no-scripts', '--no-interaction', '--quiet', '--no-plugins', '--prefer-dist'],
            $this->fixtureDir,
        );
        $install->setTimeout(60);
        $install->run();

        if (!$install->isSuccessful()) {
            $this->markTestSkipped('composer install failed in fixture: ' . $install->getErrorOutput());
        }
    }

    protected function tearDown(): void
    {
        if ('' !== $this->fixtureDir && $this->fs->exists($this->fixtureDir)) {
            $this->fs->remove($this->fixtureDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function workingDirFallbackResolvesNonPackagistPackage(): void
    {
        // Primary `composer show --all test-vendor/private-extension` fails (not on Packagist).
        // Fallback `composer show --working-dir=$fixtureDir test-vendor/private-extension`
        // succeeds because the fixture project declares it as a path dependency.

        $resolver = new ComposerVersionResolver(
            new NullLogger(),
            new ComposerConstraintChecker(),
            new ComposerEnvironment(new NullLogger()),
        );

        $result = $resolver->resolve(
            'test-vendor/private-extension',
            null,
            new Version('13.4.0'),
            $this->fixtureDir,
        );

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status, 'Package should resolve via --working-dir fallback');
    }

    #[Test]
    public function fullVcsSourceChainResolvesNonPackagistPackage(): void
    {
        // Full chain: VcsSource → ComposerVersionResolver → --working-dir fallback.
        $resolver = new ComposerVersionResolver(
            new NullLogger(),
            new ComposerConstraintChecker(),
            new ComposerEnvironment(new NullLogger()),
        );

        $cacheService = new CacheService(new NullLogger(), sys_get_temp_dir() . '/typo3-analyzer-test-cache');
        $source = new VcsSource($resolver, new NullLogger(), $cacheService);

        $extension = new Extension(
            'private_extension',
            'Private Extension',
            new Version('2.0.0'),
            'composer',
            'test-vendor/private-extension',
        );
        // repositoryUrl = null: package not on Packagist, no VCS URL available
        // Installation path wires the --working-dir fallback

        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            [],
            $this->fixtureDir,
        );

        $metrics = $source->checkAvailability($extension, $context);

        self::assertSame(
            VcsAvailability::Available,
            $metrics['vcs_available'],
            'VcsSource should report Available for non-Packagist package resolved via --working-dir',
        );
    }
}
