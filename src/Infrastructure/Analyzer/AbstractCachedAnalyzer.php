<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer;

use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use Psr\Log\LoggerInterface;

/**
 * Abstract base class for analyzers with built-in caching capabilities.
 */
abstract class AbstractCachedAnalyzer implements AnalyzerInterface
{
    protected const DEFAULT_CACHE_TTL = 3600; // 1 hour default TTL

    public function __construct(
        protected readonly CacheService $cacheService,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Final analyze method that implements caching logic.
     * Concrete analyzers should implement doAnalyze() instead.
     */
    final public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $cacheKey = $this->generateCacheKey($extension, $context);

        // Try to get cached result first
        if ($this->isCacheEnabled($context)) {
            $cachedData = $this->cacheService->get($cacheKey);

            if (null !== $cachedData && $this->isValidCachedResult($cachedData, $extension, $context)) {
                $this->logger->debug('Using cached analysis result', [
                    'analyzer' => $this->getName(),
                    'extension' => $extension->getKey(),
                    'cache_key' => $cacheKey,
                ]);

                return $this->deserializeResult($cachedData, $extension);
            }
        }

        // Perform actual analysis
        $this->logger->info('Performing fresh analysis', [
            'analyzer' => $this->getName(),
            'extension' => $extension->getKey(),
        ]);

        try {
            $result = $this->doAnalyze($extension, $context);

            // Cache successful results
            if ($this->isCacheEnabled($context) && $result->isSuccessful()) {
                $serializedData = $this->serializeResult($result);
                $serializedData['cached_at'] = time();
                $serializedData['cache_ttl'] = $this->getCacheTtl($context);

                if ($this->cacheService->set($cacheKey, $serializedData)) {
                    $this->logger->debug('Analysis result cached', [
                        'analyzer' => $this->getName(),
                        'extension' => $extension->getKey(),
                        'cache_key' => $cacheKey,
                    ]);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Analysis failed', [
                'analyzer' => $this->getName(),
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);

            $result = new AnalysisResult($this->getName(), $extension);
            $result->setError('Analysis failed: ' . $e->getMessage());

            return $result;
        }
    }

    /**
     * Concrete analyzers implement this method to perform actual analysis.
     */
    abstract protected function doAnalyze(Extension $extension, AnalysisContext $context): AnalysisResult;

    /**
     * Generate a unique cache key for the given extension and context.
     */
    protected function generateCacheKey(Extension $extension, AnalysisContext $context): string
    {
        $keyData = [
            'analyzer' => $this->getName(),
            'extension_key' => $extension->getKey(),
            'extension_version' => $extension->getVersion()->toString(),
            'target_version' => $context->getTargetVersion()->toString(),
            'current_version' => $context->getCurrentVersion()->toString(),
        ];

        // Add extension-specific data that affects analysis
        $keyData['extension_type'] = $extension->getType();
        if ($extension->hasComposerName()) {
            $keyData['composer_name'] = $extension->getComposerName();
        }

        // Add analyzer-specific cache key components
        $analyzerSpecific = $this->getAnalyzerSpecificCacheKeyComponents($extension, $context);
        $keyData = array_merge($keyData, $analyzerSpecific);

        $jsonData = json_encode($keyData);
        if (false === $jsonData) {
            throw new AnalyzerException('Failed to encode cache key data', $this->getName());
        }

        return 'analysis_' . $this->getName() . '_' . hash('sha256', $jsonData);
    }

    /**
     * Override in concrete analyzers to add analyzer-specific cache key components.
     */
    protected function getAnalyzerSpecificCacheKeyComponents(Extension $extension, AnalysisContext $context): array
    {
        return [];
    }

    /**
     * Check if caching is enabled in the current context.
     */
    protected function isCacheEnabled(AnalysisContext $context): bool
    {
        // Check if result cache is explicitly disabled
        $cacheConfig = $context->getConfigurationValue('resultCache', []);

        if (\is_array($cacheConfig)) {
            return $cacheConfig['enabled'] ?? true;
        }

        return true; // Default to enabled
    }

    /**
     * Get cache TTL from context or use default.
     */
    protected function getCacheTtl(AnalysisContext $context): int
    {
        $cacheConfig = $context->getConfigurationValue('resultCache', []);

        if (\is_array($cacheConfig)) {
            return $cacheConfig['ttl'] ?? self::DEFAULT_CACHE_TTL;
        }

        return self::DEFAULT_CACHE_TTL;
    }

    /**
     * Validate that cached result is still valid.
     */
    protected function isValidCachedResult(array $cachedData, Extension $extension, AnalysisContext $context): bool
    {
        $cachedAt = $cachedData['cached_at'] ?? 0;
        $cacheTtl = $cachedData['cache_ttl'] ?? self::DEFAULT_CACHE_TTL;

        // Check if cache has expired
        if ((time() - $cachedAt) > $cacheTtl) {
            $this->logger->debug('Cached result expired', [
                'analyzer' => $this->getName(),
                'extension' => $extension->getKey(),
                'cached_at' => $cachedAt,
                'ttl' => $cacheTtl,
                'age' => time() - $cachedAt,
            ]);

            return false;
        }

        // File-based analyzers can override this method to check file modification times

        return true;
    }

    /**
     * Serialize analysis result for caching.
     */
    protected function serializeResult(AnalysisResult $result): array
    {
        return [
            'analyzer_name' => $result->getAnalyzerName(),
            'extension_key' => $result->getExtension()->getKey(),
            'metrics' => $result->getMetrics(),
            'risk_score' => $result->getRiskScore(),
            'recommendations' => $result->getRecommendations(),
            'successful' => $result->isSuccessful(),
            'error' => $result->getError(),
        ];
    }

    /**
     * Deserialize cached data back to AnalysisResult.
     */
    protected function deserializeResult(array $cachedData, Extension $extension): AnalysisResult
    {
        $result = new AnalysisResult($cachedData['analyzer_name'], $extension);

        // Restore metrics
        foreach ($cachedData['metrics'] ?? [] as $key => $value) {
            $result->addMetric($key, $value);
        }

        // Restore risk score
        if (isset($cachedData['risk_score'])) {
            $result->setRiskScore($cachedData['risk_score']);
        }

        // Restore recommendations
        foreach ($cachedData['recommendations'] ?? [] as $recommendation) {
            $result->addRecommendation($recommendation);
        }

        // Restore error state
        if (!($cachedData['successful'] ?? true)) {
            $result->setError($cachedData['error'] ?? 'Unknown error');
        }

        return $result;
    }

    /**
     * Get the most recent modification time of files in a directory.
     */
    protected function getDirectoryModificationTime(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $maxMtime = 0;

        // Get modification time of directory itself
        $dirMtime = filemtime($path);
        if (false !== $dirMtime) {
            $maxMtime = max($maxMtime, $dirMtime);
        }

        // Recursively check PHP files for modification time
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $fileCount = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $fileMtime = $file->getMTime();
                $maxMtime = max($maxMtime, $fileMtime);
                ++$fileCount;

                // Limit file checking to avoid performance issues
                if ($fileCount > 100) {
                    break;
                }
            }
        }

        return $maxMtime;
    }
}
