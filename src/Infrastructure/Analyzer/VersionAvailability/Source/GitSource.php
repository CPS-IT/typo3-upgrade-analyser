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
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitAnalysisException;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer;
use Psr\Log\LoggerInterface;

readonly class GitSource implements VersionSourceInterface
{
    public function __construct(
        private GitRepositoryAnalyzer $gitAnalyzer,
        private LoggerInterface $logger,
        private CacheService $cacheService,
    ) {
    }

    public function getName(): string
    {
        return 'git';
    }

    public function checkAvailability(Extension $extension, AnalysisContext $context): array
    {
        $defaultResponse = [
            'git_available' => false,
            'git_repository_health' => null,
            'git_repository_url' => null,
            'git_latest_version' => null,
        ];

        $cacheKey = $this->cacheService->generateSimpleKey('git_availability', $extension->getKey(), [
            'target_version' => $context->getTargetVersion()->toString(),
        ]);

        if ($this->cacheService->has($cacheKey)) {
            return $this->cacheService->get($cacheKey) ?? $defaultResponse;
        }

        try {
            $gitInfo = $this->gitAnalyzer->analyzeExtension($extension, $context->getTargetVersion());

            $result = [
                'git_available' => $gitInfo->hasCompatibleVersion(),
                'git_repository_health' => $gitInfo->getHealthScore(),
                'git_repository_url' => $gitInfo->getRepositoryUrl(),
            ];

            if ($gitInfo->getLatestCompatibleVersion()) {
                $result['git_latest_version'] = $gitInfo->getLatestCompatibleVersion()->getName();
            }

            $this->cacheService->set($cacheKey, $result);

            return $result;
        } catch (GitAnalysisException $e) {
            $this->logger->info('Git analysis skipped for extension', [
                'extension' => $extension->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return $defaultResponse;
        } catch (\Throwable $e) {
            $this->logger->warning('Git availability check failed', [
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $defaultResponse;
        }
    }
}
