<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Service for generating Rector configuration files.
 */
class RectorConfigGenerator
{
    public function __construct(
        private readonly RectorRuleRegistry $ruleRegistry,
        private readonly string $tempDirectory,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        $this->ensureTempDirectoryExists();
    }

    /**
     * Generate Rector configuration for extension analysis.
     */
    public function generateConfig(Extension $extension, AnalysisContext $context, string $extensionPath): string
    {
        $sets = $this->selectSetsForVersion(
            $context->getCurrentVersion(),
            $context->getTargetVersion(),
        );

        $configArray = $this->buildConfigArray(
            $sets,
            $extensionPath,
            [
                'php_version' => $this->getPhpVersion($context),
                'skip_files' => $this->getSkipPatterns($extension),
                'parallel' => true,
                'cache_directory' => $this->tempDirectory . '/cache',
            ],
        );

        return $this->writeConfigFile($configArray, $extension->getKey());
    }

    /**
     * Generate minimal configuration with specific sets.
     *
     * @param array<string> $setNames
     *
     * @throws AnalyzerException
     */
    public function generateMinimalConfig(array $setNames, string $targetPath): string
    {
        $configArray = $this->buildConfigArray(
            $setNames,
            $targetPath,
            ['php_version' => '8.1'],
        );

        return $this->writeConfigFile($configArray, 'minimal_' . md5($targetPath));
    }

    /**
     * Generate configuration for a specific set category.
     */
    public function generateConfigForCategory(string $category, Extension $extension, AnalysisContext $context): string
    {
        $sets = $this->ruleRegistry->getSetsByCategory($category);

        if (empty($sets)) {
            throw new AnalyzerException("No sets found for category: {$category}", 'RectorConfigGenerator');
        }

        $configArray = $this->buildConfigArray(
            $sets,
            $this->getExtensionPath($extension),
            [
                'php_version' => $this->getPhpVersion($context),
                'skip_files' => $this->getSkipPatterns($extension),
            ],
        );

        return $this->writeConfigFile($configArray, $extension->getKey() . '_' . $category);
    }

    /**
     * Clean up generated configuration files.
     */
    public function cleanup(): void
    {
        if ($this->filesystem->exists($this->tempDirectory)) {
            // Remove only config files, keep cache directory
            $pattern = $this->tempDirectory . '/rector_*.php';
            $files = glob($pattern);
            if (false === $files) {
                return;
            }
            foreach ($files as $file) {
                $this->filesystem->remove($file);
            }
        }
    }

    /**
     * Select appropriate sets for version upgrade.
     *
     * @return array<string>
     */
    private function selectSetsForVersion(\CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version $currentVersion, \CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version $targetVersion): array
    {
        return $this->ruleRegistry->getSetsForVersionUpgrade($currentVersion, $targetVersion);
    }

    /**
     * Build configuration array for Rector.
     *
     * @param array<string>        $sets
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildConfigArray(array $sets, string $targetPath, array $options): array
    {
        $config = [
            'paths' => [$targetPath],
            'sets' => $sets,
            'php_version' => $options['php_version'] ?? '8.1',
            'parallel' => $options['parallel'] ?? false,
        ];

        // Add skip patterns if provided
        if (!empty($options['skip_files'])) {
            $config['skip'] = $options['skip_files'];
        }

        // Add cache directory if provided
        if (!empty($options['cache_directory'])) {
            $config['cache_directory'] = $options['cache_directory'];
        }

        // Add memory limit if provided
        if (!empty($options['memory_limit'])) {
            $config['memory_limit'] = $options['memory_limit'];
        }

        return $config;
    }

    /**
     * Write configuration to file.
     *
     * @param array<string, mixed> $config
     */
    private function writeConfigFile(array $config, string $identifier): string
    {
        $configContent = $this->generateConfigFileContent($config);
        $fileName = 'rector_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier) . '.php';
        $filePath = $this->tempDirectory . '/' . $fileName;

        try {
            $this->filesystem->dumpFile($filePath, $configContent);
        } catch (\Exception $e) {
            throw new AnalyzerException("Failed to write Rector config file: {$e->getMessage()}", 'RectorConfigGenerator', $e);
        }

        return $filePath;
    }

    /**
     * Generate PHP configuration file content.
     *
     * @param array<string, mixed> $config
     */
    private function generateConfigFileContent(array $config): string
    {
        $content = "<?php\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "use Rector\\Config\\RectorConfig;\n";
        $content .= "use Rector\\ValueObject\\PhpVersion;\n\n";
        $content .= "return static function (RectorConfig \$rectorConfig): void {\n";

        // Add paths
        if (!empty($config['paths'])) {
            $paths = var_export($config['paths'], true);
            $content .= "    \$rectorConfig->paths({$paths});\n\n";
        }

        // Add PHP version
        if (!empty($config['php_version'])) {
            $phpVersionConstant = $this->getPhpVersionConstant($config['php_version']);
            $content .= "    \$rectorConfig->phpVersion({$phpVersionConstant});\n\n";
        }

        // Add parallel processing
        if (!empty($config['parallel'])) {
            $content .= "    \$rectorConfig->parallel();\n\n";
        }

        // Add cache directory
        if (!empty($config['cache_directory'])) {
            $cacheDir = var_export($config['cache_directory'], true);
            $content .= "    \$rectorConfig->cacheDirectory({$cacheDir});\n\n";
        }

        // Add skip patterns
        if (!empty($config['skip'])) {
            $skip = var_export($config['skip'], true);
            $content .= "    \$rectorConfig->skip({$skip});\n\n";
        }

        // Add sets
        if (!empty($config['sets'])) {
            $content .= "    \$rectorConfig->sets([\n";
            foreach ($config['sets'] as $set) {
                $setPath = var_export($set, true);
                $content .= "        {$setPath},\n";
            }
            $content .= "    ]);\n\n";
        }

        $content .= "};\n";

        return $content;
    }

    /**
     * Get PHP version constant for Rector configuration.
     */
    private function getPhpVersionConstant(string $phpVersion): string
    {
        $versionMap = [
            '7.4' => 'PhpVersion::PHP_74',
            '8.0' => 'PhpVersion::PHP_80',
            '8.1' => 'PhpVersion::PHP_81',
            '8.2' => 'PhpVersion::PHP_82',
            '8.3' => 'PhpVersion::PHP_83',
        ];

        return $versionMap[$phpVersion] ?? 'PhpVersion::PHP_81';
    }

    /**
     * Get PHP version from analysis context.
     */
    private function getPhpVersion(AnalysisContext $context): string
    {
        // Default PHP version mappings for TYPO3 versions
        $targetVersion = $context->getTargetVersion();

        if ($targetVersion->getMajor() >= 13) {
            return '8.2'; // TYPO3 13+ requires PHP 8.2+
        } elseif ($targetVersion->getMajor() >= 12) {
            return '8.1'; // TYPO3 12 requires PHP 8.1+
        } else {
            return '8.0'; // TYPO3 11 requires PHP 8.0+
        }
    }

    /**
     * Get file patterns to skip during analysis.
     *
     * @return array<string>
     */
    private function getSkipPatterns(Extension $extension): array
    {
        $skipPatterns = [
            // Common directories to skip
            '*/vendor/*',
            '*/node_modules/*',
            '*/public/*',
            '*/.Build/*',

            // Documentation
            '*/Documentation/*',
            '*/doc/*',

            // Configuration files that might contain legacy patterns intentionally
            '*/Configuration/TCA/Overrides/*',
        ];

        // Only skip test directories for non-test extensions
        // This allows test fixtures to be processed
        if ('test_extension' !== $extension->getKey()) {
            $skipPatterns[] = '*/Tests/*';
            $skipPatterns[] = '*/tests/*';
        }

        // Add extension-specific skip patterns
        if ($extension->isSystemExtension()) {
            // System extensions might have legacy code that should be ignored
            $skipPatterns[] = '*/Migrations/*';
        }

        return $skipPatterns;
    }

    /**
     * Get extension path for analysis.
     * Note: This is a fallback - the actual extension path should be passed from the analyzer.
     */
    private function getExtensionPath(Extension $extension): string
    {
        // For system extensions, we want to analyze the actual extension path
        // For third-party extensions, use the extension directory

        $composerName = $extension->getComposerName();
        if ($composerName && str_contains($composerName, 'typo3/cms-')) {
            // System extension - analyze vendor path
            return 'vendor/' . $composerName;
        }

        // Regular extension - use extension key path
        return 'extensions/' . $extension->getKey();
    }

    /**
     * Ensure temporary directory exists.
     */
    private function ensureTempDirectoryExists(): void
    {
        if (!$this->filesystem->exists($this->tempDirectory)) {
            try {
                $this->filesystem->mkdir($this->tempDirectory, 0o755);
            } catch (\Exception $e) {
                throw new AnalyzerException("Failed to create temp directory: {$this->tempDirectory}", 'RectorConfigGenerator', $e);
            }
        }
    }
}
