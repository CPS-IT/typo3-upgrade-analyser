<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DTO\DeclaredRepository;
use Psr\Log\LoggerInterface;

/**
 * Parses composer.lock (or composer.json as fallback) to extract VCS source URLs
 * and the packages associated with each URL.
 */
final readonly class ComposerSourceParser
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return DeclaredRepository[]
     */
    public function parse(string $composerLockPath): array
    {
        if (is_file($composerLockPath)) {
            return $this->parseComposerLock($composerLockPath);
        }

        $composerJsonPath = \dirname($composerLockPath) . '/composer.json';
        if (is_file($composerJsonPath)) {
            return $this->parseComposerJson($composerJsonPath);
        }

        return [];
    }

    /**
     * @return DeclaredRepository[]
     */
    private function parseComposerLock(string $path): array
    {
        $data = $this->decodeJsonFile($path);
        if (null === $data) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $packages */
        $packages = array_merge(
            \is_array($data['packages'] ?? null) ? $data['packages'] : [],
            \is_array($data['packages-dev'] ?? null) ? $data['packages-dev'] : [],
        );

        /** @var array<string, list<string>> $grouped */
        $grouped = [];

        foreach ($packages as $package) {
            if (!\is_array($package)) {
                continue;
            }

            $source = $package['source'] ?? null;
            if (!\is_array($source)) {
                continue;
            }

            if (($source['type'] ?? '') === 'path') {
                continue;
            }

            $url = $source['url'] ?? null;
            if (!\is_string($url) || '' === $url) {
                continue;
            }

            $name = \is_string($package['name'] ?? null) ? $package['name'] : '';
            $grouped[$url] ??= [];
            if ('' !== $name && !\in_array($name, $grouped[$url], true)) {
                $grouped[$url][] = $name;
            }
        }

        return $this->buildResult($grouped);
    }

    /**
     * @return DeclaredRepository[]
     */
    private function parseComposerJson(string $path): array
    {
        $data = $this->decodeJsonFile($path);
        if (null === $data) {
            return [];
        }

        $repositories = \is_array($data['repositories'] ?? null) ? $data['repositories'] : [];

        /** @var array<string, list<string>> $grouped */
        $grouped = [];

        foreach ($repositories as $repo) {
            if (!\is_array($repo)) {
                continue;
            }

            if (($repo['type'] ?? '') !== 'vcs') {
                continue;
            }

            $url = $repo['url'] ?? null;
            if (!\is_string($url) || '' === $url) {
                continue;
            }

            $grouped[$url] ??= [];
        }

        return $this->buildResult($grouped);
    }

    /**
     * @param array<string, list<string>> $grouped
     *
     * @return DeclaredRepository[]
     */
    private function buildResult(array $grouped): array
    {
        $result = [];
        foreach ($grouped as $url => $packages) {
            $result[] = new DeclaredRepository(url: (string) $url, packages: $packages);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonFile(string $path): ?array
    {
        $content = @file_get_contents($path);
        if (false === $content) {
            $this->logger->warning(
                \sprintf('Failed to read file %s', $path),
            );

            return null;
        }

        try {
            $data = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(
                \sprintf('Failed to parse JSON in %s: %s', $path, $e->getMessage()),
            );

            return null;
        }

        if (!\is_array($data)) {
            $this->logger->warning(
                \sprintf('Unexpected JSON structure in %s: expected object, got %s', $path, get_debug_type($data)),
            );

            return null;
        }

        return $data;
    }
}
