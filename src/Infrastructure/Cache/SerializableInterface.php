<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Cache;

interface SerializableInterface
{
    /**
     * Convert object to array for serialization
     * 
     * @return array<string, mixed> Array representation of the object
     */
    public function toArray(): array;

    /**
     * Create object from array data
     * 
     * @param array<string, mixed> $data Array representation to deserialize from
     * @return static Deserialized object instance
     */
    public static function fromArray(array $data): static;
}