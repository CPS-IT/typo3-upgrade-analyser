<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\Source;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\VersionSourceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use Psr\Log\LoggerInterface;

readonly class TerSource implements VersionSourceInterface
{
    public function __construct(
        private TerApiClient $terClient,
        private LoggerInterface $logger,
        private CacheService $cacheService,
    ) {
    }

    public function getName(): string
    {
        return 'ter';
    }

    public function checkAvailability(Extension $extension, AnalysisContext $context): array
    {
        $cacheKey = $this->cacheService->generateSimpleKey('ter_availability', $extension->getKey(), [
            'target_version' => $context->getTargetVersion()->toString(),
        ]);

        if ($this->cacheService->has($cacheKey)) {
            return $this->cacheService->get($cacheKey) ?? ['ter_available' => false];
        }

        try {
            $available = $this->terClient->hasVersionFor(
                $extension->getKey(),
                $context->getTargetVersion(),
            );

            $result = ['ter_available' => $available];
            $this->cacheService->set($cacheKey, $result);

            return $result;
        } catch (\Throwable $e) {
            // Let fatal errors bubble up to cause complete analysis failure
            if (str_contains($e->getMessage(), 'Fatal error')) {
                throw $e;
            }

            $this->logger->warning('TER availability check failed, checking fallback sources', [
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);

            // TER specifically failed, return false for TER availability
            return ['ter_available' => false];
        }
    }
}
