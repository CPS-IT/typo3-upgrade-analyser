<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Application\Command;

use CPSIT\UpgradeAnalyzer\Domain\Entity\DiscoveryResult;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\AnalyzerInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'analyze',
    description: 'Analyze a TYPO3 installation for upgrade readiness',
)]
class AnalyzeCommand extends Command
{
    /**
     * @param iterable<AnalyzerInterface> $analyzers
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ExtensionDiscoveryServiceInterface $extensionDiscovery,
        private readonly InstallationDiscoveryServiceInterface $installationDiscovery,
        private readonly ConfigurationServiceInterface $configService,
        private readonly ReportService $reportService,
        private readonly iterable $analyzers = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to configuration file',
                ConfigurationService::DEFAULT_CONFIG_PATH,
            )
            ->addOption(
                'analyzers',
                'a',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Specific analyzers to run (run all if not specified)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configPath = $input->getOption('config');

        $io->title('TYPO3 Upgrade Analyzer');

        // Validate config file exists
        if (!file_exists($configPath)) {
            $io->error(\sprintf('Configuration file does not exist: %s', $configPath));

            return Command::FAILURE;
        }

        try {
            // Use ConfigurationService with custom config path if provided
            $configService = ConfigurationService::DEFAULT_CONFIG_PATH !== $configPath
                ? $this->configService->withConfigPath($configPath)
                : $this->configService;

            // Get settings from configuration
            $installationPath = $configService->getInstallationPath();
            $targetVersion = $configService->getTargetVersion();

            if (!$installationPath) {
                $io->error('No installation path specified in configuration file');

                return Command::FAILURE;
            }

            $io->section(\sprintf('Analyzing TYPO3 installation at: %s', $installationPath));
            $io->note(\sprintf('Target TYPO3 version: %s', $targetVersion));

            // Validate installation path exists
            if (!is_dir($installationPath)) {
                $io->error(\sprintf('Installation path does not exist: %s', $installationPath));

                return Command::FAILURE;
            }

            $this->logger->info('Starting TYPO3 upgrade analysis', [
                'installation_path' => $installationPath,
                'target_version' => $targetVersion,
                'config_path' => $configPath,
            ]);

            $io->progressStart(3);

            // Phase 1: Discovery
            $io->text('Phase 1: Discovering installation and extensions...');
            [$installation, $extensions, $extensionResult] = $this->executeDiscoveryPhase($installationPath, $configService, $io);
            $io->progressAdvance();

            // Phase 2: Analysis
            $io->text('Phase 2: Running analyzers...');
            $analysisResults = $this->executeAnalysisPhase($installation, $extensions, $targetVersion, $input->getOption('analyzers'), $io);
            $io->progressAdvance();

            // Phase 3: Reporting
            $io->text('Phase 3: Generating reports...');
            $this->executeReportingPhase($configService, $installation, $extensions, $extensionResult, $analysisResults, $io);
            $io->progressAdvance();

            $io->progressFinish();

            $io->success('Analysis completed successfully!');

            $outputDir = $configService->get('reporting.output_directory', 'var/reports/');
            $io->note(\sprintf('Reports generated in: %s', $outputDir));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(\sprintf('Analysis failed: %s', $e->getMessage()));
            $this->logger->error('Analysis failed', ['exception' => $e]);

            return Command::FAILURE;
        }
    }

    /**
     * Phase 1: Discover installation and extensions.
     *
     * @return array{0: \CPSIT\UpgradeAnalyzer\Domain\Entity\Installation|null, 1: array<\CPSIT\UpgradeAnalyzer\Domain\Entity\Extension>, 2: mixed}
     */
    private function executeDiscoveryPhase(string $installationPath, ConfigurationServiceInterface $configService, SymfonyStyle $io): array
    {
        // Discover installation
        $io->text('Discovering TYPO3 installation...');
        $installationResult = $this->installationDiscovery->discoverInstallation($installationPath);

        $installation = null;
        $customPaths = null;

        if ($installationResult->isSuccessful()) {
            $installation = $installationResult->getInstallation();
            $customPaths = $installation?->getMetadata()?->getCustomPaths();
            $io->text(\sprintf('Installation discovered: TYPO3 %s', $installation?->getVersion()->toString() ?? 'unknown'));
        } else {
            $io->comment(\sprintf('Installation discovery failed: %s', $installationResult->getErrorMessage()));
            $io->text('Proceeding with extension discovery using default paths...');
        }

        // Discover extensions
        $io->text('Discovering extensions...');
        $extensionResult = $this->extensionDiscovery->discoverExtensions($installationPath, $customPaths);

        if (!$extensionResult->isSuccessful()) {
            throw new \RuntimeException(\sprintf('Extension discovery failed: %s', $extensionResult->getErrorMessage()));
        }

        $extensions = $extensionResult->getExtensions();
        $io->text(\sprintf('Discovered %d extensions', \count($extensions)));

        // Apply extension filter if configured
        $extensionFilter = $configService->get('analysis.extensionFilter');
        $this->logger->debug('Extension filter check', [
            'filter_value' => $extensionFilter,
            'is_array' => \is_array($extensionFilter),
            'is_empty' => empty($extensionFilter),
        ]);

        if (\is_array($extensionFilter) && !empty($extensionFilter)) {
            $extensions = array_filter($extensions, function ($extension) use ($extensionFilter) {
                return \in_array($extension->getKey(), $extensionFilter, true);
            });
            $io->text(\sprintf('Filtered to %d extensions (filter applied)', \count($extensions)));
            $this->logger->info('Extension filter applied', [
                'filter' => $extensionFilter,
                'filtered_count' => \count($extensions),
            ]);
        } else {
            $this->logger->debug('No extension filter applied - filter is null, not an array, or empty');
        }

        $this->logger->info('Discovery phase completed', [
            'installation_discovered' => null !== $installation,
            'extensions_count' => \count($extensions),
            'successful_methods' => $extensionResult->getSuccessfulMethods(),
        ]);

        return [$installation, $extensions, $extensionResult];
    }

    /**
     * Phase 2: Run analyzers on discovered extensions.
     *
     * @param array<\CPSIT\UpgradeAnalyzer\Domain\Entity\Extension> $extensions
     * @param array<string>|null                                    $requestedAnalyzers
     *
     * @return array<string, array<\CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult>>
     */
    private function executeAnalysisPhase(?\CPSIT\UpgradeAnalyzer\Domain\Entity\Installation $installation, array $extensions, string $targetVersion, ?array $requestedAnalyzers, SymfonyStyle $io): array
    {
        if (empty($extensions)) {
            $io->warning('No extensions found to analyze');

            return [];
        }

        // Filter analyzers if specific ones were requested
        $analyzersToRun = $this->getAnalyzersToRun($requestedAnalyzers);

        if (empty($analyzersToRun)) {
            $io->warning('No analyzers available to run');
            $io->text(\sprintf('Total analyzers configured: %d', \count(iterator_to_array($this->analyzers))));

            return [];
        }

        // Get custom paths from installation metadata
        $customPaths = $installation?->getMetadata()?->getCustomPaths() ?? [];

        // Get extensions available in target version from config
        $extensionAvailableInTargetVersion = $this->configService->get('analysis.extensionAvailableInTargetVersion', []);

        $context = new AnalysisContext(
            $installation?->getVersion() ?? Version::fromString('12.4'), // Use actual detected version
            Version::fromString($targetVersion),
            [], // phpVersions
            [
                'installation_path' => $installation?->getPath() ?? '',
                'custom_paths' => $customPaths,
                'extensionAvailableInTargetVersion' => $extensionAvailableInTargetVersion,
            ],
        );
        $results = [];

        $io->text(\sprintf('Running %d analyzers on %d extensions...', \count($analyzersToRun), \count($extensions)));

        foreach ($analyzersToRun as $analyzer) {
            $analyzerName = $analyzer->getName();
            $io->text(\sprintf('Running analyzer: %s', $analyzerName));

            if (!$analyzer->hasRequiredTools()) {
                $io->comment(\sprintf('Skipping %s - required tools not available', $analyzerName));
                continue;
            }

            $results[$analyzerName] = [];

            foreach ($extensions as $extension) {
                if (!$analyzer->supports($extension)) {
                    continue;
                }

                // Skip rector and fractor analysis for extensions available in target version
                if (\in_array($analyzerName, ['typo3_rector', 'fractor'], true)) {
                    // Check manual configuration list
                    $inManualList = \in_array($extension->getKey(), $extensionAvailableInTargetVersion, true);

                    // Check if version_availability analyzer already ran and found the extension available in TER
                    // Only trust TER for TYPO3 compatibility - Packagist/Git may have incorrect constraints
                    $isAvailableInTer = false;
                    if (isset($results['version_availability'])) {
                        foreach ($results['version_availability'] as $versionResult) {
                            if ($versionResult->getExtension()->getKey() === $extension->getKey()) {
                                $isAvailableInTer = $versionResult->getMetric('ter_available') === true;
                                break;
                            }
                        }
                    }

                    if ($inManualList || $isAvailableInTer) {
                        $this->logger->debug('Skipping analyzer for extension available in target version', [
                            'analyzer' => $analyzerName,
                            'extension' => $extension->getKey(),
                            'reason' => $inManualList ? 'manual_config' : 'ter_available',
                        ]);
                        continue;
                    }
                }

                try {
                    $result = $analyzer->analyze($extension, $context);
                    $results[$analyzerName][] = $result;
                } catch (\Exception $e) {
                    $this->logger->warning('Analyzer failed for extension', [
                        'analyzer' => $analyzerName,
                        'extension' => $extension->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $io->text(\sprintf('Completed %s: analyzed %d extensions', $analyzerName, \count($results[$analyzerName])));
        }

        $totalResults = array_sum(array_map('count', $results));
        $this->logger->info('Analysis phase completed', [
            'analyzers_run' => \count($analyzersToRun),
            'total_results' => $totalResults,
        ]);

        return $results;
    }

    /**
     * Phase 3: Generate reports from analysis results.
     *
     * @param array<string, array<\CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult>> $analysisResults
     */
    private function executeReportingPhase(
        ConfigurationServiceInterface $configService,
        ?\CPSIT\UpgradeAnalyzer\Domain\Entity\Installation $installation,
        array $extensions,
        mixed $extensionResult,
        array $analysisResults,
        SymfonyStyle $io,
    ): void {
        $outputDir = $configService->get('reporting.output_directory', 'var/reports/');
        $formats = $configService->get('reporting.formats', ['markdown']);

        if (!$installation) {
            $io->warning('No installation data available - generating basic report');
            $this->generateSummaryReport($outputDir, null, $extensions, null, $analysisResults);

            return;
        }

        $io->text(\sprintf('Generating detailed reports in %d format(s)...', \count($formats)));

        // Convert analysis results to a flat array for the report service
        $allResults = [];
        foreach ($analysisResults as $analyzerResults) {
            $allResults = array_merge($allResults, $analyzerResults);
        }

        // Create discovery results for the report service
        $discoveryResults = [
            new DiscoveryResult(
                'discovery',
                'installation',
                'Installation Discovery',
                [
                    'version' => $installation->getVersion()->toString(),
                    'type' => $installation->getType(),
                    'path' => $installation->getPath(),
                ],
            ),
            new DiscoveryResult(
                'discovery',
                'extensions',
                'Extension Discovery',
                [
                    'count' => \count($extensions),
                    'successful_methods' => $extensionResult->getSuccessfulMethods(),
                ],
            ),
        ];

        // Combine all results for the report service
        $combinedResults = array_merge($discoveryResults, $allResults);

        try {
            // Get extensions available in target version from config
            $extensionAvailableInTargetVersion = $configService->get('analysis.extensionAvailableInTargetVersion', []);

            // Get client-report config
            $clientReport = $configService->get('client-report', []);

            // Get extension configuration (for estimated-hours overrides, etc.)
            $extensionConfiguration = $clientReport['extension'] ?? [];

            // Get estimated hours configuration for effort estimation
            $estimatedHours = $clientReport['estimated-hours'] ?? [];

            // Get hourly rate for cost calculation
            $hourlyRate = $clientReport['hourly-rate'] ?? 960;

            // Generate detailed reports using the ReportService
            $reportResults = $this->reportService->generateReport(
                $installation,
                $extensions,
                $combinedResults,
                $formats,
                $outputDir,
                $configService->getTargetVersion(),
                $extensionAvailableInTargetVersion,
                $extensionConfiguration,
                $estimatedHours,
                $hourlyRate,
            );

            // Log report generation results
            foreach ($reportResults as $reportResult) {
                if ($reportResult->isSuccessful()) {
                    $this->logger->info('Report generated successfully', [
                        'format' => $reportResult->getValue('format'),
                        'path' => $reportResult->getValue('output_path'),
                        'size' => $reportResult->getValue('file_size'),
                    ]);
                } else {
                    $this->logger->error('Report generation failed', [
                        'format' => $reportResult->getValue('format'),
                        'error' => $reportResult->getError(),
                    ]);
                }
            }

            // Also generate a simple summary for backwards compatibility
            $this->generateSummaryReport($outputDir, $installation, $extensions, $extensionResult, $analysisResults);
        } catch (\Exception $e) {
            $io->warning('Detailed report generation failed, falling back to summary report');
            $this->logger->error('Report service failed', ['error' => $e->getMessage()]);
            $this->generateSummaryReport($outputDir, $installation, $extensions, $extensionResult, $analysisResults);
        }

        $this->logger->info('Reporting phase completed', [
            'output_directory' => $outputDir,
            'formats' => $formats,
            'extensions_count' => \count($extensions),
            'analysis_results_count' => array_sum(array_map('count', $analysisResults)),
        ]);
    }

    /**
     * Get analyzers to run based on configuration settings and command-line options.
     *
     * @param array<string>|null $requestedAnalyzers
     *
     * @return array<AnalyzerInterface>
     */
    private function getAnalyzersToRun(?array $requestedAnalyzers): array
    {
        $allAnalyzers = iterator_to_array($this->analyzers);

        // First filter by configuration - only include analyzers that are enabled in config
        $enabledAnalyzers = [];
        foreach ($allAnalyzers as $analyzer) {
            $analyzerName = $analyzer->getName();
            $configKey = "analysis.analyzers.{$analyzerName}.enabled";
            $isEnabled = $this->configService->get($configKey, true); // Default to true for backwards compatibility

            if ($isEnabled) {
                $enabledAnalyzers[] = $analyzer;
            } else {
                $this->logger->debug('Skipping disabled analyzer', ['analyzer' => $analyzerName]);
            }
        }

        // Then filter by command-line option if specified
        if (null === $requestedAnalyzers || empty($requestedAnalyzers)) {
            return $enabledAnalyzers;
        }

        $analyzersToRun = [];
        foreach ($enabledAnalyzers as $analyzer) {
            if (\in_array($analyzer->getName(), $requestedAnalyzers, true)) {
                $analyzersToRun[] = $analyzer;
            }
        }

        return $analyzersToRun;
    }

    /**
     * Generate a simple summary report.
     *
     * @param array<\CPSIT\UpgradeAnalyzer\Domain\Entity\Extension>                     $extensions
     * @param array<string, array<\CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult>> $analysisResults
     */
    private function generateSummaryReport(
        string $outputDir,
        ?\CPSIT\UpgradeAnalyzer\Domain\Entity\Installation $installation,
        array $extensions,
        mixed $extensionResult,
        array $analysisResults,
    ): void {
        $reportPath = $outputDir . '/analysis-summary.md';

        $content = "# TYPO3 Upgrade Analysis Report\n\n";
        $content .= 'Generated on: ' . date('Y-m-d H:i:s') . "\n\n";

        if ($installation) {
            $content .= "## Installation Information\n\n";
            $content .= '- **Path**: ' . $installation->getPath() . "\n";
            $content .= '- **TYPO3 Version**: ' . $installation->getVersion()->toString() . "\n";
            $content .= '- **Installation Type**: ' . $installation->getType() . "\n\n";
        }

        $content .= "## Extensions Overview\n\n";
        $content .= 'Total extensions found: ' . \count($extensions) . "\n\n";

        if (!empty($analysisResults)) {
            $content .= "## Analysis Results\n\n";
            foreach ($analysisResults as $analyzerName => $results) {
                $content .= '### ' . ucfirst(str_replace('_', ' ', $analyzerName)) . "\n\n";
                $content .= 'Analyzed extensions: ' . \count($results) . "\n\n";
            }
        }

        file_put_contents($reportPath, $content);
    }
}
