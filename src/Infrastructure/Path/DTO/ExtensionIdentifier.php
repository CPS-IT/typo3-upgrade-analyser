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
 * Extension identifier object replacing domain entity dependencies.
 */
final readonly class ExtensionIdentifier
{
    public function __construct(
        public string $key,
        public ?string $version = null,
        public ?string $type = null,
        public ?string $composerName = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'type' => $this->type,
            'composerName' => $this->composerName,
        ];
    }
}
