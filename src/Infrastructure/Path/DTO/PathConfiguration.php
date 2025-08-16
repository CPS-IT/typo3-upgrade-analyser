<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO;

/**
 * Immutable configuration object replacing loose array configurations.
 */
final readonly class PathConfiguration
{
    private function __construct(
        public array $customPaths = [],
        public array $searchDirectories = [],
        public array $excludePatterns = [],
        public bool $followSymlinks = true,
        public bool $validateExists = true,
        public int $maxDepth = 10,
    ) {
    }

    public static function createDefault(): self
    {
        return new self();
    }

    public static function fromArray(array $config): self
    {
        return new self(
            $config['customPaths'] ?? [],
            $config['searchDirectories'] ?? [],
            $config['excludePatterns'] ?? [],
            $config['followSymlinks'] ?? true,
            $config['validateExists'] ?? true,
            $config['maxDepth'] ?? 10,
        );
    }

    public function toArray(): array
    {
        return [
            'customPaths' => $this->customPaths,
            'searchDirectories' => $this->searchDirectories,
            'excludePatterns' => $this->excludePatterns,
            'followSymlinks' => $this->followSymlinks,
            'validateExists' => $this->validateExists,
            'maxDepth' => $this->maxDepth,
        ];
    }

    public function getCustomPath(string $key): ?string
    {
        return $this->customPaths[$key] ?? null;
    }
}
