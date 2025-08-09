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
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Analyzer for counting lines of code in extensions.
 */
class LinesOfCodeAnalyzer extends AbstractCachedAnalyzer
{
    public function __construct(
        CacheService $cacheService,
        LoggerInterface $logger,
    ) {
        parent::__construct($cacheService, $logger);
    }

    public function getName(): string
    {
        return 'lines_of_code';
    }

    public function getDescription(): string
    {
        return 'Analyzes lines of code in extension files to assess codebase size and complexity';
    }

    public function supports(Extension $extension): bool
    {
        // Only analyze third-party and local extensions, skip system extensions
        return !$extension->isSystemExtension();
    }

    public function hasRequiredTools(): bool
    {
        // No external tools required, uses built-in PHP functionality
        return true;
    }

    public function getRequiredTools(): array
    {
        return [];
    }

    protected function doAnalyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $result = new AnalysisResult($this->getName(), $extension);

        // Get installation path from context
        $installationPath = $context->getConfigurationValue('installation_path', '');

        if (empty($installationPath)) {
            throw new \RuntimeException('No installation path available in context');
        }

        // Convert relative path to absolute path
        if (!str_starts_with($installationPath, '/')) {
            $installationPath = realpath(getcwd() . '/' . $installationPath);
            if (!$installationPath) {
                throw new \RuntimeException('Invalid installation path - could not resolve to absolute path');
            }
        }

        // Build extension path - assume typical TYPO3 structure
        $extensionPath = $this->findExtensionPath($installationPath, $extension->getKey(), $context, $extension);

        $this->logger->debug('LOC analyzer path discovery', [
            'extension' => $extension->getKey(),
            'installation_path' => $installationPath,
            'found_path' => $extensionPath,
            'path_exists' => $extensionPath ? is_dir($extensionPath) : false,
        ]);

        if (!$extensionPath || !is_dir($extensionPath)) {
            // Extension path not found, return zero metrics
            $this->logger->warning('Extension path not found for LOC analysis', [
                'extension' => $extension->getKey(),
                'installation_path' => $installationPath,
                'attempted_path' => $extensionPath,
            ]);

            $metrics = [
                'total_lines' => 0,
                'code_lines' => 0,
                'comment_lines' => 0,
                'blank_lines' => 0,
                'php_files' => 0,
                'classes' => 0,
                'methods' => 0,
                'functions' => 0,
                'largest_file_lines' => 0,
                'largest_file_path' => '',
                'average_file_size' => 0,
            ];
        } else {
            $metrics = $this->scanExtensionDirectory($extensionPath);
        }

        // Store metrics
        foreach ($metrics as $key => $value) {
            $result->addMetric($key, $value);
        }

        // Calculate risk score based on codebase size
        $riskScore = $this->calculateRiskScore($metrics);
        $result->setRiskScore($riskScore);

        // Add recommendations based on codebase size
        $recommendations = $this->generateRecommendations($metrics);
        foreach ($recommendations as $recommendation) {
            $result->addRecommendation($recommendation);
        }

        $this->logger->info('Lines of code analysis completed', [
            'extension' => $extension->getKey(),
            'total_lines' => $metrics['total_lines'],
            'php_files' => $metrics['php_files'],
            'risk_score' => $riskScore,
        ]);

        return $result;
    }

    protected function getAnalyzerSpecificCacheKeyComponents(Extension $extension, AnalysisContext $context): array
    {
        // Include installation path since extension paths depend on it
        $components = [
            'installation_path' => $context->getConfigurationValue('installation_path', ''),
        ];

        // Include custom paths if available
        $customPaths = $context->getConfigurationValue('custom_paths', []);
        if (!empty($customPaths)) {
            $components['custom_paths'] = $customPaths;
        }

        return $components;
    }

    /**
     * Scan extension files and calculate metrics.
     */
    private function scanExtensionFiles(array $phpFiles): array
    {
        $metrics = [
            'total_lines' => 0,
            'code_lines' => 0,
            'comment_lines' => 0,
            'blank_lines' => 0,
            'php_files' => 0,
            'classes' => 0,
            'methods' => 0,
            'functions' => 0,
            'largest_file_lines' => 0,
            'largest_file_path' => '',
            'average_file_size' => 0,
        ];

        foreach ($phpFiles as $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }

            $fileMetrics = $this->analyzeFileByPath($filePath);

            $metrics['total_lines'] += $fileMetrics['total_lines'];
            $metrics['code_lines'] += $fileMetrics['code_lines'];
            $metrics['comment_lines'] += $fileMetrics['comment_lines'];
            $metrics['blank_lines'] += $fileMetrics['blank_lines'];
            $metrics['classes'] += $fileMetrics['classes'];
            $metrics['methods'] += $fileMetrics['methods'];
            $metrics['functions'] += $fileMetrics['functions'];
            ++$metrics['php_files'];

            if ($fileMetrics['total_lines'] > $metrics['largest_file_lines']) {
                $metrics['largest_file_lines'] = $fileMetrics['total_lines'];
                $metrics['largest_file_path'] = basename($filePath);
            }
        }

        if ($metrics['php_files'] > 0) {
            $metrics['average_file_size'] = (int) round($metrics['total_lines'] / $metrics['php_files']);
        }

        return $metrics;
    }

    /**
     * Analyze a single PHP file by file path.
     */
    private function analyzeFileByPath(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        return $this->analyzeFileContent($content, $lines);
    }

    /**
     * Analyze a single PHP file.
     */
    private function analyzeFile(SplFileInfo $file): array
    {
        $content = $file->getContents();
        $lines = explode("\n", $content);

        return $this->analyzeFileContent($content, $lines);
    }

    /**
     * Analyze file content and return metrics.
     */
    private function analyzeFileContent(string $content, array $lines): array
    {
        $metrics = [
            'total_lines' => \count($lines),
            'code_lines' => 0,
            'comment_lines' => 0,
            'blank_lines' => 0,
            'classes' => 0,
            'methods' => 0,
            'functions' => 0,
        ];

        $inMultiLineComment = false;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if (empty($trimmedLine)) {
                ++$metrics['blank_lines'];
                continue;
            }

            // Handle multi-line comments
            if ($inMultiLineComment) {
                ++$metrics['comment_lines'];
                if (str_contains($trimmedLine, '*/')) {
                    $inMultiLineComment = false;
                }
                continue;
            }

            if (str_starts_with($trimmedLine, '/*')) {
                ++$metrics['comment_lines'];
                if (!str_contains($trimmedLine, '*/')) {
                    $inMultiLineComment = true;
                }
                continue;
            }

            if (str_starts_with($trimmedLine, '//') || str_starts_with($trimmedLine, '#')) {
                ++$metrics['comment_lines'];
                continue;
            }

            // Count code constructs
            if (preg_match('/^(class|interface|trait|enum)\s+\w+/', $trimmedLine)) {
                ++$metrics['classes'];
            }

            if (preg_match('/^\s*(public|private|protected|static)?\s*(function)\s+\w+/', $trimmedLine)) {
                if (str_contains($trimmedLine, 'function __')) {
                    // Count constructors/destructors as methods
                    ++$metrics['methods'];
                } elseif (preg_match('/class\s+\w+.*{/', $content) && !str_starts_with($trimmedLine, 'function ')) {
                    ++$metrics['methods'];
                } else {
                    ++$metrics['functions'];
                }
            }

            ++$metrics['code_lines'];
        }

        return $metrics;
    }

    /**
     * Calculate risk score based on codebase metrics.
     */
    private function calculateRiskScore(array $metrics): float
    {
        $score = 0.0;

        // Base score on total lines of code
        $totalLines = $metrics['total_lines'];
        if ($totalLines > 10000) {
            $score += 3.0; // Very large codebase
        } elseif ($totalLines > 5000) {
            $score += 2.0; // Large codebase
        } elseif ($totalLines > 2000) {
            $score += 1.0; // Medium codebase
        }

        // Factor in largest file size
        $largestFile = $metrics['largest_file_lines'];
        if ($largestFile > 1000) {
            $score += 2.0; // Very large file
        } elseif ($largestFile > 500) {
            $score += 1.0; // Large file
        }

        // Factor in average file size
        $avgFileSize = $metrics['average_file_size'];
        if ($avgFileSize > 300) {
            $score += 1.0; // Large average file size
        }

        // Factor in complexity (methods per class ratio)
        $filesCount = $metrics['php_files'];
        if ($filesCount > 0) {
            $complexity = ($metrics['methods'] + $metrics['functions']) / $filesCount;
            if ($complexity > 20) {
                $score += 1.0; // High complexity
            }
        }

        return min($score, 10.0); // Cap at 10.0
    }

    /**
     * Generate recommendations based on metrics.
     */
    private function generateRecommendations(array $metrics): array
    {
        $recommendations = [];
        $totalLines = $metrics['total_lines'];
        $largestFile = $metrics['largest_file_lines'];
        $avgFileSize = $metrics['average_file_size'];

        if ($totalLines > 10000) {
            $recommendations[] = "Large codebase ({$totalLines} lines) - plan extensive testing and consider phased migration approach";
        } elseif ($totalLines > 5000) {
            $recommendations[] = "Medium-large codebase ({$totalLines} lines) - allocate sufficient time for testing";
        } elseif ($totalLines > 1000) {
            $recommendations[] = "Medium codebase ({$totalLines} lines) - standard testing approach should suffice";
        } else {
            $recommendations[] = "Small codebase ({$totalLines} lines) - low complexity upgrade expected";
        }

        if ($largestFile > 1000) {
            $recommendations[] = "Large file detected ({$largestFile} lines in {$metrics['largest_file_path']}) - consider refactoring before upgrade";
        } elseif ($largestFile > 500) {
            $recommendations[] = "Medium-large file detected ({$largestFile} lines) - review for potential issues";
        }

        if ($avgFileSize > 300) {
            $recommendations[] = "High average file size ({$avgFileSize} lines) - code may be complex to migrate";
        }

        if ($metrics['php_files'] > 50) {
            $recommendations[] = "Large number of files ({$metrics['php_files']}) - systematic testing approach recommended";
        }

        return $recommendations;
    }

    /**
     * Find extension path in TYPO3 installation.
     */
    private function findExtensionPath(string $installationPath, string $extensionKey, AnalysisContext $context, Extension $extension): ?string
    {
        // Use the same path resolution logic as ExtensionDiscoveryService
        $customPaths = $this->getCustomPathsFromContext($context);

        $this->logger->debug('LOC analyzer starting path resolution', [
            'extension' => $extensionKey,
            'composer_name' => $extension->getComposerName(),
            'extension_type' => $extension->getType(),
            'installation_path' => $installationPath,
            'custom_paths' => $customPaths,
        ]);

        $paths = $this->resolvePaths($installationPath, $customPaths);

        // Build possible extension locations based on extension type
        $possiblePaths = [];

        if ($extension->getComposerName()) {
            // For Composer-managed extensions, use the composer name for vendor directory
            $composerName = $extension->getComposerName();
            $possiblePaths[] = $paths['vendor_dir'] . '/' . $composerName;
        }

        // Add traditional TYPO3 extension paths
        $possiblePaths = array_merge($possiblePaths, [
            // Local extensions (using resolved typo3conf path)
            $paths['typo3conf_dir'] . '/ext/' . $extensionKey,
            // System extensions (TYPO3 < 12)
            $installationPath . '/typo3/sysext/' . $extensionKey,
            // System extensions (TYPO3 >= 12, using resolved vendor path)
            $paths['vendor_dir'] . '/typo3/cms-' . str_replace('_', '-', $extensionKey),
            // Public extensions directory (using resolved web dir)
            $paths['web_dir'] . '/typo3conf/ext/' . $extensionKey,
        ]);

        $this->logger->debug('LOC analyzer attempting paths', [
            'extension' => $extensionKey,
            'composer_name' => $extension->getComposerName(),
            'possible_paths' => $possiblePaths,
        ]);

        foreach ($possiblePaths as $path) {
            if (str_contains($path, '*')) {
                // Handle wildcard paths for vendor extensions
                $pattern = str_replace('*', '[^/]+', $path);
                $matches = glob($pattern, GLOB_ONLYDIR);
                $this->logger->debug('Wildcard path search', [
                    'pattern' => $pattern,
                    'matches' => $matches,
                ]);
                if (!empty($matches) && is_dir($matches[0])) {
                    return $matches[0];
                }
            } elseif (is_dir($path)) {
                $this->logger->debug('Found extension path', [
                    'extension' => $extensionKey,
                    'path' => $path,
                ]);

                return $path;
            }
        }

        return null;
    }

    /**
     * Resolve paths based on custom paths or defaults (same logic as ExtensionDiscoveryService).
     */
    private function resolvePaths(string $installationPath, ?array $customPaths): array
    {
        $vendorDir = $customPaths['vendor-dir'] ?? 'vendor';
        $webDir = $customPaths['web-dir'] ?? 'public';
        $typo3confDir = $customPaths['typo3conf-dir'] ?? $webDir . '/typo3conf';

        return [
            'package_states' => $installationPath . '/' . $typo3confDir . '/PackageStates.php',
            'composer_installed' => $installationPath . '/' . $vendorDir . '/composer/installed.json',
            'vendor_dir' => $installationPath . '/' . $vendorDir,
            'web_dir' => $installationPath . '/' . $webDir,
            'typo3conf_dir' => $installationPath . '/' . $typo3confDir,
        ];
    }

    /**
     * Get custom paths from context (passed from installation discovery).
     */
    private function getCustomPathsFromContext(AnalysisContext $context): ?array
    {
        return $context->getConfigurationValue('custom_paths', null);
    }

    /**
     * Scan extension directory for PHP files and calculate metrics.
     */
    private function scanExtensionDirectory(string $extensionPath): array
    {
        $finder = new Finder();
        $finder->files()
            ->in($extensionPath)
            ->name('*.php')
            ->notPath('vendor')
            ->notPath('node_modules')
            ->notPath('Tests')
            ->notPath('tests');

        $metrics = [
            'total_lines' => 0,
            'code_lines' => 0,
            'comment_lines' => 0,
            'blank_lines' => 0,
            'php_files' => 0,
            'classes' => 0,
            'methods' => 0,
            'functions' => 0,
            'largest_file_lines' => 0,
            'largest_file_path' => '',
            'average_file_size' => 0,
        ];

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            $fileMetrics = $this->analyzeFile($file);

            $metrics['total_lines'] += $fileMetrics['total_lines'];
            $metrics['code_lines'] += $fileMetrics['code_lines'];
            $metrics['comment_lines'] += $fileMetrics['comment_lines'];
            $metrics['blank_lines'] += $fileMetrics['blank_lines'];
            $metrics['classes'] += $fileMetrics['classes'];
            $metrics['methods'] += $fileMetrics['methods'];
            $metrics['functions'] += $fileMetrics['functions'];
            ++$metrics['php_files'];

            if ($fileMetrics['total_lines'] > $metrics['largest_file_lines']) {
                $metrics['largest_file_lines'] = $fileMetrics['total_lines'];
                $metrics['largest_file_path'] = $file->getRelativePathname();
            }
        }

        if ($metrics['php_files'] > 0) {
            $metrics['average_file_size'] = (int) round($metrics['total_lines'] / $metrics['php_files']);
        }

        return $metrics;
    }
}
