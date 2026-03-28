<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor;

use a9f\FractorTypoScript\Configuration\TypoScriptProcessorOption;
use a9f\FractorXml\Configuration\XmlProcessorOption;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerException;
use Helmich\TypoScriptParser\Parser\Printer\PrettyPrinterConfiguration;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Generates Fractor configuration files for analysis.
 */
readonly class FractorConfigGenerator
{
    public function __construct(
        private FractorRuleRegistry $ruleRegistry,
        private string $tempDirectory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
        $this->ensureTempDirectoryExists();
    }

    /**
     * Generate Fractor configuration for extension analysis.
     *
     * @param array<string, mixed> $options Custom options to override defaults
     */
    public function generateConfig(Extension $extension, AnalysisContext $context, string $extensionPath, array $options = []): string
    {
        $sets = $this->selectSetsForVersion(
            $context->getCurrentVersion(),
            $context->getTargetVersion(),
        );

        $fractorOptions = [
            TypoScriptProcessorOption::INDENT_SIZE => $options['typoscript']['indent_size'] ?? 2,
            TypoScriptProcessorOption::INDENT_CHARACTER => ($options['typoscript']['indent_character'] ?? 'space') === 'space'
                ? PrettyPrinterConfiguration::INDENTATION_STYLE_SPACES
                : PrettyPrinterConfiguration::INDENTATION_STYLE_TABS,
            TypoScriptProcessorOption::ADD_CLOSING_GLOBAL => $options['typoscript']['add_closing_global'] ?? true,
            TypoScriptProcessorOption::INCLUDE_EMPTY_LINE_BREAKS => $options['typoscript']['include_empty_line_breaks'] ?? true,
            TypoScriptProcessorOption::INDENT_CONDITIONS => $options['typoscript']['indent_conditions'] ?? true,
            XmlProcessorOption::INDENT_SIZE => $options['xml']['indent_size'] ?? 2,
            XmlProcessorOption::INDENT_CHARACTER => ($options['xml']['indent_character'] ?? 'space') === 'space' ? 'space' : 'tab',
        ];

        $configArray = $this->buildConfigArray(
            $sets,
            $extensionPath,
            [
                'skip_files' => $this->getSkipPatterns($extension),
                'parallel' => true,
                'cache_directory' => $this->tempDirectory . '/cache',
                'options' => $fractorOptions,
            ],
        );

        return $this->writeConfigFile($configArray, $extension->getKey());
    }

    /**
     * Select appropriate sets for version upgrade.
     *
     * @return array<string>
     */
    private function selectSetsForVersion(Version $currentVersion, Version $targetVersion): array
    {
        return $this->ruleRegistry->getSetsForVersionUpgrade($currentVersion, $targetVersion);
    }

    /**
     * Build configuration array for Fractor.
     *
     * @param array<string>        $sets
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function buildConfigArray(array $sets, string $targetPath, array $config): array
    {
        $fractorConfig = [
            'paths' => [$targetPath],
            'sets' => $sets,
        ];

        // Add skip patterns if provided
        if (!empty($config['skip_files'])) {
            $fractorConfig['skip'] = $config['skip_files'];
        }

        // Add cache directory if provided
        if (!empty($config['cache_directory'])) {
            $fractorConfig['cache_directory'] = $config['cache_directory'];
        }

        if (!empty($config['options'])) {
            $fractorConfig['options'] = $config['options'];
        }

        return $fractorConfig;
    }

    /**
     * Write configuration to file.
     *
     * @param array<string, mixed> $config
     */
    private function writeConfigFile(array $config, string $identifier): string
    {
        $configContent = $this->generateConfigFileContent($config);
        $fileName = 'fractor_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier) . '.php';
        $filePath = $this->tempDirectory . '/' . $fileName;

        try {
            $this->filesystem->dumpFile($filePath, $configContent);
        } catch (\Exception $e) {
            throw new AnalyzerException("Failed to write Fractor config file: {$e->getMessage()}", 'FractorConfigGenerator', $e);
        }

        return $filePath;
    }

    private function generateConfigFileContent(array $config): string
    {
        $content = "<?php\n\n";
        $content .= "declare(strict_types=1);\n\n";

        $content .= "use a9f\\Fractor\\Configuration\\FractorConfiguration;\n";
        $content .= "use a9f\\FractorTypoScript\\Configuration\\TypoScriptProcessorOption;\n";
        $content .= "use a9f\\Typo3Fractor\\Set\\Typo3LevelSetList;\n";
        $content .= "use Helmich\\TypoScriptParser\\Parser\\Printer\\PrettyPrinterConfiguration;\n";

        $content .= "return FractorConfiguration::configure()\n";

        // Add paths
        if (!empty($config['paths'])) {
            $paths = var_export($config['paths'], true);
            $content .= "    ->withPaths({$paths})\n\n";
        }

        // Add sets
        if (!empty($config['sets'])) {
            $content .= "    ->withSets([\n";
            foreach ($config['sets'] as $set) {
                $setPath = var_export($set, true);
                $content .= "        {$setPath},\n";
            }
            $content .= "    ])\n\n";
        }

        // Add skip patterns
        if (!empty($config['skip'])) {
            $skip = var_export($config['skip'], true);
            $content .= "    ->withSkip({$skip})\n\n";
        }

        $options = var_export($config['options'], true);
        $content .= "    ->withOptions({$options});\n";

        return $content;
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
            '*/.idea/*',
            '*/vendor/*',
            '*/node_modules/*',
            '*/var/*',
            '*/public/*',
            '*/.Build/*',
            '*/Resources/Public/*',

            // Documentation
            '*/Documentation/*',
            '*/doc/*',
        ];

        // Only skip test directories for non-test extensions
        // This allows test fixtures to be processed
        if ('test_extension' !== $extension->getKey()) {
            $skipPatterns[] = '*/Tests/*';
            $skipPatterns[] = '*/tests/*';
        }

        return $skipPatterns;
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
                throw new AnalyzerException("Failed to create temp directory: {$this->tempDirectory}", 'FractorConfigGenerator', $e);
            }
        }
    }

    /**
     * Clean up generated configuration files.
     */
    public function cleanup(): void
    {
        if ($this->filesystem->exists($this->tempDirectory)) {
            // Remove only config files, keep cache directory
            $pattern = $this->tempDirectory . '/fractor_*.php';
            $files = glob($pattern);
            if (false === $files) {
                return;
            }
            foreach ($files as $file) {
                $this->filesystem->remove($file);
            }
        }
    }
}
