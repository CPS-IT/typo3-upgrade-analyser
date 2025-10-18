<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\Infrastructure\Reporting;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportFileManager;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\TemplateRenderer;
use CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTestCase;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;

/**
 * Integration tests for Rector findings detail page generation flow.
 *
 * Tests the complete flow from data preparation through template rendering
 * to file writing for Rector detail pages.
 *
 * @group integration
 */
class RectorDetailPageIntegrationTest extends AbstractIntegrationTestCase
{
    private TemplateRenderer $templateRenderer;
    private ReportFileManager $fileManager;
    private string $tempOutputDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary directory for output files
        $this->tempOutputDir = sys_get_temp_dir() . '/rector-integration-test-' . uniqid();
        mkdir($this->tempOutputDir, 0o755, true);

        // Create Twig environment with test templates
        $twigLoader = new ArrayLoader([
            'html/rector-findings-detail.html.twig' => $this->getHtmlTemplate(),
            'md/rector-findings-detail.md.twig' => $this->getMarkdownTemplate(),
        ]);

        $twig = new TwigEnvironment($twigLoader);

        $this->templateRenderer = new TemplateRenderer($twig);
        $this->fileManager = new ReportFileManager();
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->tempOutputDir)) {
            $this->removeDirectory($this->tempOutputDir);
        }

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testCompleteRectorDetailPageGenerationFlow(): void
    {
        // Arrange - Create realistic test data
        $context = $this->createTestReportContext();

        // Act - Complete flow: template rendering + file writing
        $htmlDetailPages = $this->templateRenderer->renderRectorFindingsDetailPages($context, 'html');
        $markdownDetailPages = $this->templateRenderer->renderRectorFindingsDetailPages($context, 'markdown');

        $htmlFiles = $this->fileManager->writeRectorDetailPages($htmlDetailPages, $this->tempOutputDir);
        $markdownFiles = $this->fileManager->writeRectorDetailPages($markdownDetailPages, $this->tempOutputDir);

        // Assert - Verify complete flow results
        $this->assertCount(2, $htmlDetailPages, 'Should generate HTML pages for 2 extensions with findings');
        $this->assertCount(2, $markdownDetailPages, 'Should generate Markdown pages for 2 extensions with findings');
        $this->assertCount(2, $htmlFiles, 'Should write 2 HTML files');
        $this->assertCount(2, $markdownFiles, 'Should write 2 Markdown files');

        // Verify file structure
        $this->assertTrue(is_dir($this->tempOutputDir . '/rector-findings'));
        $this->assertTrue(file_exists($this->tempOutputDir . '/rector-findings/news.html'));
        $this->assertTrue(file_exists($this->tempOutputDir . '/rector-findings/tt_address.html'));
        $this->assertTrue(file_exists($this->tempOutputDir . '/rector-findings/news.md'));
        $this->assertTrue(file_exists($this->tempOutputDir . '/rector-findings/tt_address.md'));

        // Verify file content quality
        $htmlContent = file_get_contents($this->tempOutputDir . '/rector-findings/news.html');
        $this->assertNotFalse($htmlContent, 'HTML file should be readable');
        $this->assertStringContainsString('<h1>Rector Findings for news</h1>', $htmlContent);
        $this->assertStringContainsString('Classes/Domain/Model/News.php', $htmlContent);
        $this->assertStringContainsString('Use of deprecated method', $htmlContent);

        $markdownContent = file_get_contents($this->tempOutputDir . '/rector-findings/news.md');
        $this->assertNotFalse($markdownContent, 'Markdown file should be readable');
        $this->assertStringContainsString('# Rector Findings for news', $markdownContent);
        $this->assertStringContainsString('**File:** Classes/Domain/Model/News.php', $markdownContent);
    }

    public function testIntegrationWithCompleteReportGeneration(): void
    {
        // Arrange
        $context = $this->createTestReportContext();

        $mainReport = [
            'content' => '<html><body>Main Report</body></html>',
            'filename' => 'analysis-report.html',
        ];

        $extensionReports = [
            [
                'content' => '<html><body>News Extension Report</body></html>',
                'filename' => 'news.html',
                'extension' => 'news',
            ],
            [
                'content' => '<html><body>TT Address Extension Report</body></html>',
                'filename' => 'tt_address.html',
                'extension' => 'tt_address',
            ],
        ];

        // Act - Generate Rector detail pages and write all files together
        $rectorDetailPages = $this->templateRenderer->renderRectorFindingsDetailPages($context, 'html');

        $allFiles = $this->fileManager->writeReportFilesWithRectorPages(
            $mainReport,
            $extensionReports,
            $rectorDetailPages,
            $this->tempOutputDir,
        );

        // Assert - Verify complete file structure
        $this->assertCount(5, $allFiles); // 1 main + 2 extensions + 2 rector pages

        // Verify file types
        $filesByType = [];
        foreach ($allFiles as $file) {
            $filesByType[$file['type']][] = $file;
        }

        $this->assertArrayHasKey('main_report', $filesByType);
        $this->assertArrayHasKey('extension_report', $filesByType);
        $this->assertArrayHasKey('rector_detail_page', $filesByType);

        $this->assertCount(1, $filesByType['main_report']);
        $this->assertCount(2, $filesByType['extension_report']);
        $this->assertCount(2, $filesByType['rector_detail_page']);

        // Verify directory structure
        $this->assertTrue(is_dir($this->tempOutputDir . '/extensions'));
        $this->assertTrue(is_dir($this->tempOutputDir . '/rector-findings'));

        // Verify all expected files exist
        $this->assertTrue(file_exists($this->tempOutputDir . '/analysis-report.html'));
        $this->assertTrue(file_exists($this->tempOutputDir . '/extensions/news.html'));
        $this->assertTrue(file_exists($this->tempOutputDir . '/extensions/tt_address.html'));
        $this->assertTrue(file_exists($this->tempOutputDir . '/rector-findings/news.html'));
        $this->assertTrue(file_exists($this->tempOutputDir . '/rector-findings/tt_address.html'));
    }

    public function testNoRectorFindingsSkipsPageGeneration(): void
    {
        // Arrange - Context with no detailed findings
        $context = $this->createTestReportContextWithoutFindings();

        // Act
        $htmlDetailPages = $this->templateRenderer->renderRectorFindingsDetailPages($context, 'html');
        $files = $this->fileManager->writeRectorDetailPages($htmlDetailPages, $this->tempOutputDir);

        // Assert
        $this->assertEmpty($htmlDetailPages);
        $this->assertEmpty($files);
        $this->assertFalse(is_dir($this->tempOutputDir . '/rector-findings'));
    }

    public function testJsonFormatSkipsRectorDetailPages(): void
    {
        // Arrange
        $context = $this->createTestReportContext();

        // Act
        $jsonDetailPages = $this->templateRenderer->renderRectorFindingsDetailPages($context, 'json');

        // Assert
        $this->assertEmpty($jsonDetailPages, 'JSON format should skip Rector detail page generation');
    }

    public function testMixedFindingsGeneratesCorrectPages(): void
    {
        // Arrange - Some extensions with findings, some without
        $context = $this->createMixedFindingsContext();

        // Act
        $htmlDetailPages = $this->templateRenderer->renderRectorFindingsDetailPages($context, 'html');
        $files = $this->fileManager->writeRectorDetailPages($htmlDetailPages, $this->tempOutputDir);

        // Assert - Should only generate pages for extensions with findings
        $this->assertCount(1, $htmlDetailPages); // Only 'news' has findings
        $this->assertCount(1, $files);
        $this->assertSame('news', $htmlDetailPages[0]['extension']);

        // Verify only the expected file was created
        $this->assertTrue(file_exists($this->tempOutputDir . '/rector-findings/news.html'));
        $this->assertFalse(file_exists($this->tempOutputDir . '/rector-findings/tt_address.html'));
        $this->assertFalse(file_exists($this->tempOutputDir . '/rector-findings/powermail.html'));
    }

    public function testLargeDatasetPerformance(): void
    {
        // Arrange - Large dataset with multiple extensions
        $context = $this->createLargeDatasetContext();

        // Act - Measure execution time
        $startTime = microtime(true);

        $htmlDetailPages = $this->templateRenderer->renderRectorFindingsDetailPages($context, 'html');
        $files = $this->fileManager->writeRectorDetailPages($htmlDetailPages, $this->tempOutputDir);

        $executionTime = microtime(true) - $startTime;

        // Assert - Performance and correctness
        $this->assertLessThan(2.0, $executionTime, 'Large dataset processing should complete within 2 seconds');
        $this->assertCount(5, $htmlDetailPages, 'Should generate pages for 5 extensions with findings');
        $this->assertCount(5, $files);

        // Verify directory structure handles many files
        $this->assertTrue(is_dir($this->tempOutputDir . '/rector-findings'));
        $createdFiles = glob($this->tempOutputDir . '/rector-findings/*.html');
        $this->assertNotFalse($createdFiles, 'Glob pattern should return valid result');
        $this->assertCount(5, $createdFiles);
    }

    private function createTestReportContext(): array
    {
        $newsExtension = new Extension('news', 'News System', new Version('11.3.0'), 'ter');
        $ttAddressExtension = new Extension('tt_address', 'Address List', new Version('7.1.0'), 'ter');
        $powermailExtension = new Extension('powermail', 'Powermail', new Version('10.9.0'), 'ter');

        return [
            'installation' => new Installation('/var/www/html', new Version('11.5.35')),
            'target_version' => '12.4.0',
            'generated_at' => '2023-10-17T10:30:00+00:00',
            'extension_data' => [
                [
                    'extension' => $newsExtension,
                    'rector_analysis' => [
                        'detailed_findings' => [
                            [
                                'file' => 'Classes/Domain/Model/News.php',
                                'line' => 45,
                                'message' => 'Use of deprecated method \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance()',
                                'rule' => 'GeneralUtilityMakeInstanceToContainerRule',
                            ],
                            [
                                'file' => 'Classes/Controller/NewsController.php',
                                'line' => 123,
                                'message' => 'Property $objectManager is deprecated',
                                'rule' => 'ObjectManagerDeprecationRule',
                            ],
                        ],
                        'summary' => 'Found 2 issues requiring attention',
                        'score' => 75,
                    ],
                ],
                [
                    'extension' => $ttAddressExtension,
                    'rector_analysis' => [
                        'detailed_findings' => [
                            [
                                'file' => 'Classes/Domain/Repository/AddressRepository.php',
                                'line' => 67,
                                'message' => 'Use of deprecated annotation @inject',
                                'rule' => 'InjectAnnotationRule',
                            ],
                        ],
                        'summary' => 'Found 1 issue',
                        'score' => 85,
                    ],
                ],
                [
                    'extension' => $powermailExtension,
                    'rector_analysis' => [
                        'detailed_findings' => [], // No detailed findings
                        'summary' => 'No issues found',
                        'score' => 100,
                    ],
                ],
            ],
        ];
    }

    private function createTestReportContextWithoutFindings(): array
    {
        $newsExtension = new Extension('news', 'News System', new Version('11.3.0'), 'ter');
        $ttAddressExtension = new Extension('tt_address', 'Address List', new Version('7.1.0'), 'ter');

        return [
            'installation' => new Installation('/var/www/html', new Version('11.5.35')),
            'target_version' => '12.4.0',
            'generated_at' => '2023-10-17T10:30:00+00:00',
            'extension_data' => [
                [
                    'extension' => $newsExtension,
                    'rector_analysis' => [
                        'detailed_findings' => [],
                        'summary' => 'No issues found',
                        'score' => 100,
                    ],
                ],
                [
                    'extension' => $ttAddressExtension,
                    'rector_analysis' => null, // No rector analysis
                ],
            ],
        ];
    }

    private function createMixedFindingsContext(): array
    {
        $newsExtension = new Extension('news', 'News System', new Version('11.3.0'), 'ter');
        $ttAddressExtension = new Extension('tt_address', 'Address List', new Version('7.1.0'), 'ter');
        $powermailExtension = new Extension('powermail', 'Powermail', new Version('10.9.0'), 'ter');

        return [
            'installation' => new Installation('/var/www/html', new Version('11.5.35')),
            'target_version' => '12.4.0',
            'generated_at' => '2023-10-17T10:30:00+00:00',
            'extension_data' => [
                [
                    'extension' => $newsExtension,
                    'rector_analysis' => [
                        'detailed_findings' => [
                            [
                                'file' => 'Classes/Domain/Model/News.php',
                                'line' => 45,
                                'message' => 'Use of deprecated method',
                                'rule' => 'DeprecationRule',
                            ],
                        ],
                        'summary' => 'Found 1 issue',
                        'score' => 85,
                    ],
                ],
                [
                    'extension' => $ttAddressExtension,
                    'rector_analysis' => [
                        'detailed_findings' => [], // Empty findings
                        'summary' => 'No issues found',
                        'score' => 100,
                    ],
                ],
                [
                    'extension' => $powermailExtension,
                    'rector_analysis' => null, // No rector analysis
                ],
            ],
        ];
    }

    private function createLargeDatasetContext(): array
    {
        $extensions = [];
        $extensionData = [];

        for ($i = 1; $i <= 10; ++$i) {
            $extension = new Extension("ext_{$i}", "Extension {$i}", new Version('1.0.0'), 'ter');
            $extensions[] = $extension;

            // Only half of extensions have detailed findings
            if ($i <= 5) {
                $findings = [];
                for ($j = 1; $j <= ($i * 2); ++$j) {
                    $findings[] = [
                        'file' => "Classes/Controller/Controller{$j}.php",
                        'line' => 10 + $j,
                        'message' => "Issue {$j} in extension {$i}",
                        'rule' => "Rule{$j}",
                    ];
                }

                $extensionData[] = [
                    'extension' => $extension,
                    'rector_analysis' => [
                        'detailed_findings' => $findings,
                        'summary' => "Found {$j} issues",
                        'score' => max(50, 100 - ($j * 5)),
                    ],
                ];
            } else {
                $extensionData[] = [
                    'extension' => $extension,
                    'rector_analysis' => [
                        'detailed_findings' => [],
                        'summary' => 'No issues found',
                        'score' => 100,
                    ],
                ];
            }
        }

        return [
            'installation' => new Installation('/var/www/html', new Version('11.5.35')),
            'target_version' => '12.4.0',
            'generated_at' => '2023-10-17T10:30:00+00:00',
            'extension_data' => $extensionData,
        ];
    }

    private function getHtmlTemplate(): string
    {
        return <<<'TWIG'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Rector Findings for {{ extension_key }}</title>
            </head>
            <body>
                <h1>Rector Findings for {{ extension_key }}</h1>

                <div class="extension-info">
                    <h2>Extension: {{ extension.title }}</h2>
                    <p><strong>Key:</strong> {{ extension.key }}</p>
                    <p><strong>Version:</strong> {{ extension.version }}</p>
                </div>

                <div class="analysis-summary">
                    <h3>Analysis Summary</h3>
                    <p><strong>Score:</strong> {{ rector_analysis.score }}/100</p>
                    <p><strong>Summary:</strong> {{ rector_analysis.summary }}</p>
                </div>

                <div class="detailed-findings">
                    <h3>Detailed Findings</h3>
                    {% if detailed_findings is empty %}
                        <p>No detailed findings available.</p>
                    {% else %}
                        {% for finding in detailed_findings %}
                            <div class="finding">
                                <h4>{{ finding.rule }}</h4>
                                <p><strong>File:</strong> {{ finding.file }}</p>
                                <p><strong>Line:</strong> {{ finding.line }}</p>
                                <p><strong>Message:</strong> {{ finding.message }}</p>
                            </div>
                        {% endfor %}
                    {% endif %}
                </div>

                <footer>
                    <p>Generated at: {{ generated_at }}</p>
                </footer>
            </body>
            </html>
            TWIG;
    }

    private function getMarkdownTemplate(): string
    {
        return <<<'TWIG'
            # Rector Findings for {{ extension_key }}

            ## Extension Information

            - **Title:** {{ extension.title }}
            - **Key:** {{ extension.key }}
            - **Version:** {{ extension.version }}
            - **Type:** {{ extension.type }}

            ## Analysis Summary

            - **Score:** {{ rector_analysis.score }}/100
            - **Summary:** {{ rector_analysis.summary }}

            ## Detailed Findings

            {% if detailed_findings is empty %}
            No detailed findings available.
            {% else %}
            {% for finding in detailed_findings %}
            ### {{ finding.rule }}

            **File:** {{ finding.file }}
            **Line:** {{ finding.line }}
            **Message:** {{ finding.message }}

            ---

            {% endfor %}
            {% endif %}

            *Generated at: {{ generated_at }}*
            TWIG;
    }
}
