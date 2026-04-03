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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\VcsAvailability;
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
            'vcs_available' => VcsAvailability::Unknown,
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
            $cached = $this->cacheService->get($cacheKey) ?? $defaultResponse;
            // Rehydrate string back to enum — CacheService serializes enums to their string value via json_encode.
            if (isset($cached['vcs_available']) && \is_string($cached['vcs_available'])) {
                $cached['vcs_available'] = VcsAvailability::tryFrom($cached['vcs_available']) ?? VcsAvailability::Unknown;
            }

            return $cached;
        }

        $result = $this->resolver->resolve(
            $composerName,
            $repositoryUrl,
            $context->getTargetVersion(),
            $context->getInstallationPath(),
        );

        return match ($result->status) {
            VcsResolutionStatus::RESOLVED_COMPATIBLE => $this->cacheAndReturn($cacheKey, [
                'vcs_available' => VcsAvailability::Available,
                'vcs_source_url' => $result->sourceUrl,
                'vcs_latest_version' => $result->latestCompatibleVersion,
            ]),
            VcsResolutionStatus::RESOLVED_NO_MATCH => $this->cacheAndReturn($cacheKey, [
                'vcs_available' => VcsAvailability::Unavailable,
                'vcs_source_url' => $result->sourceUrl,
                'vcs_latest_version' => null,
            ]),
            VcsResolutionStatus::NOT_FOUND => $this->handleNotFound($composerName, $repositoryUrl),
            VcsResolutionStatus::FAILURE => $this->handleFailure($composerName, $repositoryUrl, $defaultResponse),
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
     * NOT_FOUND: Composer returned a definitive "package not found" answer.
     * Maps to Unavailable, not Unknown — the system asked and got a clear answer.
     *
     * @return array<string, mixed>
     */
    private function handleNotFound(string $composerName, ?string $repositoryUrl): array
    {
        $this->logger->debug(
            'VCS package "{package}" not found via Composer (primary + fallback).',
            ['package' => $composerName, 'url' => $repositoryUrl],
        );

        return [
            'vcs_available' => VcsAvailability::Unavailable,
            'vcs_source_url' => $repositoryUrl,
            'vcs_latest_version' => null,
        ];
    }

    /**
     * FAILURE: Composer crashed, timed out, or encountered an auth/network error.
     * Maps to Unknown — the system could not determine the status.
     *
     * @param array<string, mixed> $defaultResponse
     *
     * @return array<string, mixed>
     */
    private function handleFailure(string $composerName, ?string $repositoryUrl, array $defaultResponse): array
    {
        $dedupKey = $repositoryUrl ?? $composerName;
        if (!isset($this->warnedUrls[$dedupKey])) {
            $this->warnedUrls[$dedupKey] = true;

            if ($this->isSshUrl($repositoryUrl)) {
                $this->logger->warning(
                    'VCS source "{url}" for package "{package}" could not be resolved. SSH authentication may not be configured for this host.',
                    ['url' => $repositoryUrl ?? 'unknown', 'package' => $composerName],
                );
            } else {
                $this->logger->warning(
                    'VCS source "{url}" for package "{package}" could not be resolved. Ensure Composer authentication is configured for this URL.',
                    ['url' => $repositoryUrl ?? 'unknown', 'package' => $composerName],
                );
            }
        }

        return $defaultResponse;
    }

    private function isSshUrl(?string $url): bool
    {
        if (null === $url) {
            return false;
        }

        return str_starts_with($url, 'ssh://') || (bool) preg_match('/^git@[^:]+:/', $url);
    }
}
