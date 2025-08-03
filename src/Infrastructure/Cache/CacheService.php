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

use Psr\Log\LoggerInterface;

class CacheService
{
    private const CACHE_DIRECTORY = 'var/results';
    private const FILE_EXTENSION = '.json';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $projectRoot
    ) {
    }

    public function has(string $key): bool
    {
        $filePath = $this->getCacheFilePath($key);
        return file_exists($filePath);
    }

    public function get(string $key): ?array
    {
        $filePath = $this->getCacheFilePath($key);
        
        if (!file_exists($filePath)) {
            return null;
        }

        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                $this->logger->warning('Failed to read cache file', ['key' => $key, 'path' => $filePath]);
                return null;
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            
            $this->logger->debug('Cache hit', ['key' => $key]);
            return $data;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve from cache', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function set(string $key, array $data): bool
    {
        try {
            $this->ensureCacheDirectoryExists();
            
            $filePath = $this->getCacheFilePath($key);
            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            
            $result = file_put_contents($filePath, $content);
            
            if ($result === false) {
                $this->logger->error('Failed to write to cache file', ['key' => $key, 'path' => $filePath]);
                return false;
            }

            $this->logger->debug('Cache written', ['key' => $key, 'bytes' => $result]);
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to store in cache', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $filePath = $this->getCacheFilePath($key);
        
        if (!file_exists($filePath)) {
            return true;
        }

        try {
            $result = unlink($filePath);
            
            if ($result) {
                $this->logger->debug('Cache entry deleted', ['key' => $key]);
            } else {
                $this->logger->warning('Failed to delete cache entry', ['key' => $key]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete cache entry', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function clear(): bool
    {
        $cacheDir = $this->getCacheDirectory();
        
        if (!is_dir($cacheDir)) {
            return true;
        }

        try {
            $files = glob($cacheDir . '/*' . self::FILE_EXTENSION);
            $deletedCount = 0;
            
            foreach ($files as $file) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
            
            $this->logger->info('Cache cleared', ['deleted_files' => $deletedCount]);
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear cache', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function generateKey(string $type, string $path, array $additionalParams = []): string
    {
        $data = [
            'type' => $type,
            'path' => $path,
            'params' => $additionalParams,
            'timestamp' => filemtime($path) ?: time()
        ];
        
        return $type . '_' . hash('sha256', json_encode($data));
    }

    private function getCacheFilePath(string $key): string
    {
        return $this->getCacheDirectory() . '/' . $key . self::FILE_EXTENSION;
    }

    private function getCacheDirectory(): string
    {
        return $this->projectRoot . '/' . self::CACHE_DIRECTORY;
    }

    private function ensureCacheDirectoryExists(): void
    {
        $cacheDir = $this->getCacheDirectory();
        
        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                throw new \RuntimeException(sprintf('Failed to create cache directory: %s', $cacheDir));
            }
            
            $this->logger->debug('Cache directory created', ['path' => $cacheDir]);
        }
    }
}