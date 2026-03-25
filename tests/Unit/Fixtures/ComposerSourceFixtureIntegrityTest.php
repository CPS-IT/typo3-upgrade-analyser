<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Data-integrity smoke test for ComposerSources fixture files.
 *
 * Does not test any business logic. Asserts that every fixture is valid JSON,
 * contains the fields expected by ComposerSourceParser (Story 2.1), and that
 * each fixture's source URL contains the expected provider domain.
 */
final class ComposerSourceFixtureIntegrityTest extends TestCase
{
    private static string $fixturesDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesDir = dirname(__DIR__, 2) . '/Fixtures/ComposerSources';
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function composerLockFixtureProvider(): array
    {
        return [
            'gitlab-saas-public'  => ['GitLabSaasPublic/composer.lock',  'gitlab.com'],
            'gitlab-saas-private' => ['GitLabSaasPrivate/composer.lock', 'gitlab.com'],
            'gitlab-self-hosted'  => ['GitLabSelfHosted/composer.lock',  'git.example.com'],
            'bitbucket-public'    => ['BitbucketPublic/composer.lock',   'bitbucket.org'],
            'bitbucket-private'   => ['BitbucketPrivate/composer.lock',  'bitbucket.org'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function installedJsonFixtureProvider(): array
    {
        return [
            'gitlab-saas-public'  => ['GitLabSaasPublic/installed.json',  'gitlab.com'],
            'gitlab-saas-private' => ['GitLabSaasPrivate/installed.json', 'gitlab.com'],
            'gitlab-self-hosted'  => ['GitLabSelfHosted/installed.json',  'git.example.com'],
            'bitbucket-public'    => ['BitbucketPublic/installed.json',   'bitbucket.org'],
            'bitbucket-private'   => ['BitbucketPrivate/installed.json',  'bitbucket.org'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function composerJsonFixtureProvider(): array
    {
        return [
            'gitlab-saas-public'  => ['GitLabSaasPublic/composer.json',  'gitlab.com'],
            'gitlab-saas-private' => ['GitLabSaasPrivate/composer.json', 'gitlab.com'],
            'gitlab-self-hosted'  => ['GitLabSelfHosted/composer.json',  'git.example.com'],
            'bitbucket-public'    => ['BitbucketPublic/composer.json',   'bitbucket.org'],
            'bitbucket-private'   => ['BitbucketPrivate/composer.json',  'bitbucket.org'],
        ];
    }

    #[DataProvider('composerLockFixtureProvider')]
    public function testComposerLockIsValidJsonWithSourceFields(string $relativeFixturePath, string $expectedDomain): void
    {
        $fixturePath = self::$fixturesDir . '/' . $relativeFixturePath;
        self::assertFileExists($fixturePath, "Fixture file missing: {$relativeFixturePath}");

        $content = file_get_contents($fixturePath);
        self::assertNotFalse($content, "Could not read fixture: {$relativeFixturePath}");

        /** @var array{packages: array<int, array{source: array{type: string, url: string, reference: string}}>} $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('packages', $data, "composer.lock must have 'packages' key");
        self::assertNotEmpty($data['packages'], "composer.lock 'packages' must not be empty");

        $package = $data['packages'][0];
        self::assertArrayHasKey('source', $package, "First package must have 'source' key");

        $source = $package['source'];
        self::assertArrayHasKey('type', $source, "source must have 'type' field");
        self::assertArrayHasKey('url', $source, "source must have 'url' field");
        self::assertArrayHasKey('reference', $source, "source must have 'reference' field");

        self::assertIsString($source['type']);
        self::assertIsString($source['url']);
        self::assertIsString($source['reference']);
        self::assertNotEmpty($source['url'], "source.url must not be empty");
        self::assertNotEmpty($source['reference'], "source.reference must not be empty");
        self::assertStringContainsString($expectedDomain, $source['url'], "source.url must contain expected domain '{$expectedDomain}'");
    }

    #[DataProvider('installedJsonFixtureProvider')]
    public function testInstalledJsonIsValidJsonWithSourceFields(string $relativeFixturePath, string $expectedDomain): void
    {
        $fixturePath = self::$fixturesDir . '/' . $relativeFixturePath;
        self::assertFileExists($fixturePath, "Fixture file missing: {$relativeFixturePath}");

        $content = file_get_contents($fixturePath);
        self::assertNotFalse($content, "Could not read fixture: {$relativeFixturePath}");

        /** @var array{packages: array<int, array{source: array{type: string, url: string, reference: string}, "installation-source": string}>} $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('packages', $data, "installed.json must have 'packages' key");
        self::assertNotEmpty($data['packages'], "installed.json 'packages' must not be empty");

        $package = $data['packages'][0];
        self::assertArrayHasKey('source', $package, "First package must have 'source' key");
        self::assertArrayHasKey('installation-source', $package, "First package must have 'installation-source' key");
        self::assertSame('source', $package['installation-source'], "'installation-source' must be 'source' for VCS-installed packages");

        $source = $package['source'];
        self::assertArrayHasKey('type', $source, "source must have 'type' field");
        self::assertArrayHasKey('url', $source, "source must have 'url' field");
        self::assertArrayHasKey('reference', $source, "source must have 'reference' field");

        self::assertIsString($source['type']);
        self::assertIsString($source['url']);
        self::assertNotEmpty($source['url'], "source.url must not be empty");
        self::assertStringContainsString($expectedDomain, $source['url'], "source.url must contain expected domain '{$expectedDomain}'");
    }

    #[DataProvider('composerJsonFixtureProvider')]
    public function testComposerJsonIsValidJsonWithRepositoriesEntry(string $relativeFixturePath, string $expectedDomain): void
    {
        $fixturePath = self::$fixturesDir . '/' . $relativeFixturePath;
        self::assertFileExists($fixturePath, "Fixture file missing: {$relativeFixturePath}");

        $content = file_get_contents($fixturePath);
        self::assertNotFalse($content, "Could not read fixture: {$relativeFixturePath}");

        /** @var array{repositories: array<int, array{type: string, url: string}>} $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('repositories', $data, "composer.json must have 'repositories' key");
        self::assertNotEmpty($data['repositories'], "composer.json 'repositories' must not be empty");

        $repo = $data['repositories'][0];
        self::assertArrayHasKey('type', $repo, "repositories entry must have 'type' field");
        self::assertArrayHasKey('url', $repo, "repositories entry must have 'url' field");
        self::assertIsString($repo['url']);
        self::assertNotEmpty($repo['url'], "repositories[0].url must not be empty");
        self::assertSame('vcs', $repo['type'], "repositories[0].type must be 'vcs'");
        self::assertStringContainsString($expectedDomain, $repo['url'], "repositories[0].url must contain expected domain '{$expectedDomain}'");
    }
}
