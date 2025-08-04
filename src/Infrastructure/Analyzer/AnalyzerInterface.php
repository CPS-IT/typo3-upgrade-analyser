<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer;

use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;

/**
 * Interface for all analyzers.
 */
interface AnalyzerInterface
{
    /**
     * Get the unique name of this analyzer.
     */
    public function getName(): string;

    /**
     * Get a human-readable description of what this analyzer does.
     */
    public function getDescription(): string;

    /**
     * Check if this analyzer supports analyzing the given extension.
     */
    public function supports(Extension $extension): bool;

    /**
     * Perform analysis on the given extension.
     *
     * @throws AnalyzerException if analysis fails
     */
    public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult;

    /**
     * Get list of external tools required by this analyzer.
     *
     * @return array<string> List of required tool names/binaries
     */
    public function getRequiredTools(): array;

    /**
     * Check if all required tools are available.
     */
    public function hasRequiredTools(): bool;
}
