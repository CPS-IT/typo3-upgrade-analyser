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
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionStatus;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolverInterface;
use Psr\Log\LoggerInterface;

class VcsSource implements VersionSourceInterface
{
    /** @var array<string, true> */
    private array $warnedUrls = [];

    public function __construct(
        private readonly VcsResolverInterface $resolver,
        private readonly LoggerInterface $logger,
        private readonly CacheService $cacheService,
    ) {
    }

    public function getName(): string
    {
        return 'vcs';
    }

    public function checkAvailability(Extension $extension, AnalysisContext $context): array
    {
        $defaultResponse = [
            'vcs_available' => null,
            'vcs_source_url' => null,
            'vcs_latest_version' => null,
        ];

        $composerName = $extension->getComposerName();

        if (null === $composerName) {
            return $defaultResponse;
        }

        $repositoryUrl = $extension->getRepositoryUrl();

        $cacheKey = $this->cacheService->generateSimpleKey('vcs_availability', $extension->getKey(), [
            'target_version' => $context->getTargetVersion()->toString(),
        ]);

        if ($this->cacheService->has($cacheKey)) {
            return $this->cacheService->get($cacheKey) ?? $defaultResponse;
        }

        $result = $this->resolver->resolve($composerName, $repositoryUrl, $context->getTargetVersion());

        return match ($result->status) {
            VcsResolutionStatus::RESOLVED_COMPATIBLE => $this->cacheAndReturn($cacheKey, [
                'vcs_available' => true,
                'vcs_source_url' => $result->sourceUrl,
                'vcs_latest_version' => $result->latestCompatibleVersion,
            ]),
            VcsResolutionStatus::RESOLVED_NO_MATCH => $this->cacheAndReturn($cacheKey, [
                'vcs_available' => false,
                'vcs_source_url' => $result->sourceUrl,
                'vcs_latest_version' => null,
            ]),
            VcsResolutionStatus::NOT_FOUND, VcsResolutionStatus::FAILURE => $this->handleFailure($composerName, $repositoryUrl, $defaultResponse),
        };
    }

    /**
     * @param array<string, mixed> $metrics
     *
     * @return array<string, mixed>
     */
    private function cacheAndReturn(string $cacheKey, array $metrics): array
    {
        $this->cacheService->set($cacheKey, $metrics);

        return $metrics;
    }

    /**
     * @param array<string, mixed> $defaultResponse
     *
     * @return array<string, mixed>
     */
    private function handleFailure(string $composerName, ?string $repositoryUrl, array $defaultResponse): array
    {
        $dedupKey = $repositoryUrl ?? $composerName;
        if (!isset($this->warnedUrls[$dedupKey])) {
            $this->warnedUrls[$dedupKey] = true;
            $this->logger->warning(
                'VCS source "{url}" for package "{package}" could not be resolved. Ensure Composer authentication is configured for this URL.',
                ['url' => $repositoryUrl ?? 'unknown', 'package' => $composerName],
            );
        }

        return $defaultResponse;
    }
}
