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
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use Psr\Log\LoggerInterface;

readonly class PackagistSource implements VersionSourceInterface
{
    public function __construct(
        private PackagistClient $packagistClient,
        private LoggerInterface $logger,
        private CacheService $cacheService,
    ) {
    }

    public function getName(): string
    {
        return 'packagist';
    }

    public function checkAvailability(Extension $extension, AnalysisContext $context): array
    {
        $composerName = $extension->getComposerName();
        if (!$composerName) {
            return ['packagist_available' => false];
        }

        $cacheKey = $this->cacheService->generateSimpleKey('packagist_availability', $composerName, [
            'target_version' => $context->getTargetVersion()->toString(),
        ]);

        if ($this->cacheService->has($cacheKey)) {
            return $this->cacheService->get($cacheKey) ?? ['packagist_available' => false];
        }

        try {
            $versionInfo = $this->packagistClient->getLatestVersionInfo(
                $composerName,
                $context->getTargetVersion(),
            );

            $available = null !== $versionInfo['latest_version'];

            $result = [
                'packagist_available' => $available,
                'packagist_latest_version' => $versionInfo['latest_version'],
                'packagist_latest_compatible' => $versionInfo['is_compatible'],
            ];

            $this->cacheService->set($cacheKey, $result);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning('Packagist availability check failed', [
                'extension' => $extension->getKey(),
                'composer_name' => $extension->getComposerName(),
                'error' => $e->getMessage(),
            ]);

            return ['packagist_available' => false];
        }
    }
}
