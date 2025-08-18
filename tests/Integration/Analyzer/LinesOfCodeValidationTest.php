<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\Analyzer;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\LinesOfCodeAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Cache\MultiLayerPathResolutionCache;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Recovery\ErrorRecoveryManager;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ExtensionPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PathResolutionStrategyRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation\PathResolutionValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Validation tests for LinesOfCodeAnalyzer focusing on realistic extension sizes
 * to verify PathResolutionService integration eliminates code duplication while
 * maintaining accuracy for large extensions like news (~20k lines) and powermail (~27k lines).
 */
final class LinesOfCodeValidationTest extends TestCase
{
    private LinesOfCodeAnalyzer $analyzer;
    private string $testInstallationPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up complete PathResolutionService
        $logger = new NullLogger();
        $strategy = new ExtensionPathResolutionStrategy($logger);
        $strategyRegistry = new PathResolutionStrategyRegistry($logger, [$strategy]);
        $validator = new PathResolutionValidator($logger);
        $cache = new MultiLayerPathResolutionCache($logger);
        $errorRecoveryManager = new ErrorRecoveryManager($logger);

        $pathResolutionService = new PathResolutionService(
            $strategyRegistry,
            $validator,
            $cache,
            $errorRecoveryManager,
            $logger,
        );

        $cacheService = $this->createMock(CacheService::class);
        $this->analyzer = new LinesOfCodeAnalyzer($cacheService, $logger, $pathResolutionService);

        // Create test installation with realistic extension structure
        $this->testInstallationPath = sys_get_temp_dir() . '/typo3-lines-validation-' . uniqid();
        $this->createRealisticTestExtensions($this->testInstallationPath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testInstallationPath)) {
            $this->removeDirectory($this->testInstallationPath);
        }
        parent::tearDown();
    }

    public function testSmallExtensionLinesOfCodeAccuracy(): void
    {
        // Test small extension (similar to typical local extensions)
        $extension = new Extension('small_extension', 'Small Extension', new Version('1.0.0'), 'local');
        $context = $this->createContext();

        $result = $this->analyzer->analyze($extension, $context);

        $this->assertTrue($result->isSuccessful());

        $totalLines = $result->getMetric('total_lines');
        $phpFiles = $result->getMetric('php_files');
        $codeLines = $result->getMetric('code_lines');

        // Small extension expectations
        $this->assertGreaterThan(0, $totalLines);
        $this->assertLessThan(1000, $totalLines); // Small extension should be under 1k lines
        $this->assertGreaterThan(0, $phpFiles);
        $this->assertGreaterThan(0, $codeLines);

        // Risk score should be low for small extensions
        $this->assertLessThanOrEqual(2.0, $result->getRiskScore());
    }

    public function testMediumExtensionLinesOfCodeAccuracy(): void
    {
        // Test medium extension (similar to many third-party extensions)
        $extension = new Extension('medium_extension', 'Medium Extension', new Version('2.1.0'), 'third_party');
        $context = $this->createContext();

        $result = $this->analyzer->analyze($extension, $context);

        $this->assertTrue($result->isSuccessful());

        $totalLines = $result->getMetric('total_lines');
        $phpFiles = $result->getMetric('php_files');
        $codeLines = $result->getMetric('code_lines');

        // Medium extension expectations
        $this->assertGreaterThanOrEqual(1000, $totalLines);
        $this->assertLessThan(10000, $totalLines); // Medium extension 1k-10k lines
        $this->assertGreaterThanOrEqual(10, $phpFiles);
        $this->assertGreaterThan(500, $codeLines);

        // Risk score should be moderate
        $this->assertGreaterThanOrEqual(1.0, $result->getRiskScore());
        $this->assertLessThanOrEqual(5.0, $result->getRiskScore());
    }

    public function testLargeExtensionLinesOfCodeAccuracy(): void
    {
        // Test large extension (similar to news extension ~20k lines)
        $extension = new Extension('large_extension', 'Large Extension', new Version('10.0.0'), 'third_party');
        $context = $this->createContext();

        $result = $this->analyzer->analyze($extension, $context);

        $this->assertTrue($result->isSuccessful());

        $totalLines = $result->getMetric('total_lines');
        $phpFiles = $result->getMetric('php_files');
        $codeLines = $result->getMetric('code_lines');
        $classes = $result->getMetric('classes');
        $methods = $result->getMetric('methods');

        // Large extension expectations (news-like size)
        $this->assertGreaterThanOrEqual(8000, $totalLines); // Should be at least 8k lines (realistic for large)
        $this->assertLessThan(15000, $totalLines); // But less than 15k for this test case
        $this->assertGreaterThanOrEqual(40, $phpFiles);
        $this->assertGreaterThan(8000, $codeLines);
        $this->assertGreaterThan(50, $classes);
        $this->assertGreaterThan(200, $methods);

        // Risk score should be high for large extensions
        $this->assertGreaterThanOrEqual(3.0, $result->getRiskScore());
        $this->assertLessThanOrEqual(8.0, $result->getRiskScore());

        // Should include recommendations about large codebase
        $recommendations = $result->getRecommendations();
        $this->assertNotEmpty($recommendations);
        $this->assertStringContainsString('Large codebase', $recommendations[0]);
    }

    public function testVeryLargeExtensionLinesOfCodeAccuracy(): void
    {
        // Test very large extension (similar to powermail extension ~27k lines)
        $extension = new Extension('very_large_extension', 'Very Large Extension', new Version('8.5.0'), 'third_party');
        $context = $this->createContext();

        $result = $this->analyzer->analyze($extension, $context);

        $this->assertTrue($result->isSuccessful());

        $totalLines = $result->getMetric('total_lines');
        $phpFiles = $result->getMetric('php_files');
        $codeLines = $result->getMetric('code_lines');
        $averageFileSize = $result->getMetric('average_file_size');

        // Very large extension expectations (powermail-like size)
        $this->assertGreaterThanOrEqual(15000, $totalLines); // Should be at least 15k lines (realistic very large)
        $this->assertLessThan(30000, $totalLines); // But manageable for test execution
        $this->assertGreaterThanOrEqual(70, $phpFiles);
        $this->assertGreaterThan(15000, $codeLines);
        $this->assertGreaterThan(100, $averageFileSize);

        // Risk score should be very high
        $this->assertGreaterThanOrEqual(4.0, $result->getRiskScore());
        $this->assertLessThanOrEqual(10.0, $result->getRiskScore());

        // Should include specific recommendations for very large codebases
        $recommendations = $result->getRecommendations();
        $this->assertNotEmpty($recommendations);
        $this->assertStringContainsString('extensive testing', $recommendations[0]);
    }

    public function testPathResolutionServiceEliminatesCodeDuplication(): void
    {
        // This test validates that PathResolutionService integration
        // eliminates the need for duplicated path resolution logic
        $extension = new Extension('test_path_resolution', 'Test Extension', new Version('1.0.0'), 'local');
        $context = $this->createCustomPathContext();

        $result = $this->analyzer->analyze($extension, $context);

        // The key validation is that the analyzer successfully finds extensions
        // through PathResolutionService regardless of custom path configuration
        $this->assertTrue($result->isSuccessful());
        $this->assertGreaterThan(0, $result->getMetric('total_lines'));

        // Verify that custom paths are properly handled
        $this->assertIsInt($result->getMetric('php_files'));
        $this->assertGreaterThanOrEqual(0, $result->getRiskScore());
    }

    private function createContext(): AnalysisContext
    {
        return new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => $this->testInstallationPath],
        );
    }

    private function createCustomPathContext(): AnalysisContext
    {
        return new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            [
                'installation_path' => $this->testInstallationPath,
                'custom_paths' => [
                    'web-dir' => 'app/web',
                    'vendor-dir' => 'app/vendor',
                    'typo3conf-dir' => 'app/web/typo3conf',
                ],
            ],
        );
    }

    private function createRealisticTestExtensions(string $path): void
    {
        mkdir($path, 0o755, true);
        mkdir($path . '/public/typo3conf/ext', 0o755, true);
        mkdir($path . '/app/web/typo3conf/ext', 0o755, true);

        // Create small extension
        $this->createExtensionWithSize($path . '/public/typo3conf/ext/small_extension', 'small');

        // Create medium extension
        $this->createExtensionWithSize($path . '/public/typo3conf/ext/medium_extension', 'medium');

        // Create large extension (news-like)
        $this->createExtensionWithSize($path . '/public/typo3conf/ext/large_extension', 'large');

        // Create very large extension (powermail-like)
        $this->createExtensionWithSize($path . '/public/typo3conf/ext/very_large_extension', 'very_large');

        // Create test extension for path resolution
        $this->createExtensionWithSize($path . '/app/web/typo3conf/ext/test_path_resolution', 'small');

        // Create composer.json
        file_put_contents($path . '/composer.json', json_encode([
            'name' => 'test/realistic-typo3-installation',
            'require' => ['typo3/cms-core' => '^12.0'],
        ], JSON_PRETTY_PRINT));
    }

    private function createExtensionWithSize(string $path, string $size): void
    {
        mkdir($path, 0o755, true);
        mkdir($path . '/Classes', 0o755, true);
        mkdir($path . '/Classes/Controller', 0o755, true);
        mkdir($path . '/Classes/Domain/Model', 0o755, true);
        mkdir($path . '/Classes/Domain/Repository', 0o755, true);
        mkdir($path . '/Classes/Service', 0o755, true);
        mkdir($path . '/Classes/ViewHelpers', 0o755, true);
        mkdir($path . '/Configuration', 0o755, true);

        // ext_emconf.php
        file_put_contents($path . '/ext_emconf.php', $this->generateExtEmconf());

        // Generate files based on size
        switch ($size) {
            case 'small':
                $this->generateSmallExtensionFiles($path);
                break;
            case 'medium':
                $this->generateMediumExtensionFiles($path);
                break;
            case 'large':
                $this->generateLargeExtensionFiles($path);
                break;
            case 'very_large':
                $this->generateVeryLargeExtensionFiles($path);
                break;
        }
    }

    private function generateExtEmconf(): string
    {
        return '<?php
$EM_CONF[$_EXTKEY] = [
    \'title\' => \'Test Extension\',
    \'description\' => \'Generated test extension for LOC analysis\',
    \'category\' => \'plugin\',
    \'version\' => \'1.0.0\',
    \'state\' => \'stable\',
    \'author\' => \'Test\',
    \'author_email\' => \'test@example.com\',
    \'constraints\' => [
        \'depends\' => [
            \'typo3\' => \'12.0.0-12.99.99\',
        ],
    ],
];
';
    }

    private function generateSmallExtensionFiles(string $path): void
    {
        // Generate ~500-800 lines total
        for ($i = 1; $i <= 3; ++$i) {
            file_put_contents($path . "/Classes/Controller/Controller{$i}.php", $this->generateControllerClass($i, 50));
        }

        for ($i = 1; $i <= 2; ++$i) {
            file_put_contents($path . "/Classes/Domain/Model/Model{$i}.php", $this->generateModelClass($i, 30));
        }

        file_put_contents($path . '/Classes/Service/TestService.php', $this->generateServiceClass(100));
    }

    private function generateMediumExtensionFiles(string $path): void
    {
        // Generate ~3000-7000 lines total
        for ($i = 1; $i <= 8; ++$i) {
            file_put_contents($path . "/Classes/Controller/Controller{$i}.php", $this->generateControllerClass($i, 150));
        }

        for ($i = 1; $i <= 6; ++$i) {
            file_put_contents($path . "/Classes/Domain/Model/Model{$i}.php", $this->generateModelClass($i, 80));
        }

        for ($i = 1; $i <= 4; ++$i) {
            file_put_contents($path . "/Classes/Service/Service{$i}.php", $this->generateServiceClass(200));
        }

        for ($i = 1; $i <= 5; ++$i) {
            file_put_contents($path . "/Classes/ViewHelpers/ViewHelper{$i}.php", $this->generateViewHelperClass($i, 60));
        }
    }

    private function generateLargeExtensionFiles(string $path): void
    {
        // Generate ~18000-22000 lines total (news-like)
        for ($i = 1; $i <= 15; ++$i) {
            file_put_contents($path . "/Classes/Controller/Controller{$i}.php", $this->generateControllerClass($i, 250));
        }

        for ($i = 1; $i <= 12; ++$i) {
            file_put_contents($path . "/Classes/Domain/Model/Model{$i}.php", $this->generateModelClass($i, 150));
        }

        for ($i = 1; $i <= 8; ++$i) {
            file_put_contents($path . "/Classes/Service/Service{$i}.php", $this->generateServiceClass(300));
        }

        for ($i = 1; $i <= 10; ++$i) {
            file_put_contents($path . "/Classes/ViewHelpers/ViewHelper{$i}.php", $this->generateViewHelperClass($i, 100));
        }

        // Additional utility classes
        for ($i = 1; $i <= 8; ++$i) {
            file_put_contents($path . "/Classes/Utility/Utility{$i}.php", $this->generateUtilityClass($i, 180));
        }
    }

    private function generateVeryLargeExtensionFiles(string $path): void
    {
        // Generate ~27000-32000 lines total (powermail-like)
        for ($i = 1; $i <= 20; ++$i) {
            file_put_contents($path . "/Classes/Controller/Controller{$i}.php", $this->generateControllerClass($i, 300));
        }

        for ($i = 1; $i <= 15; ++$i) {
            file_put_contents($path . "/Classes/Domain/Model/Model{$i}.php", $this->generateModelClass($i, 200));
        }

        for ($i = 1; $i <= 12; ++$i) {
            file_put_contents($path . "/Classes/Service/Service{$i}.php", $this->generateServiceClass(400));
        }

        for ($i = 1; $i <= 15; ++$i) {
            file_put_contents($path . "/Classes/ViewHelpers/ViewHelper{$i}.php", $this->generateViewHelperClass($i, 120));
        }

        // Additional complex classes
        mkdir($path . '/Classes/Utility', 0o755, true);
        for ($i = 1; $i <= 10; ++$i) {
            file_put_contents($path . "/Classes/Utility/Utility{$i}.php", $this->generateUtilityClass($i, 250));
        }
    }

    private function generateControllerClass(int $number, int $targetLines): string
    {
        $methods = [];
        $methodsNeeded = max(1, \intval($targetLines / 25));

        for ($i = 1; $i <= $methodsNeeded; ++$i) {
            $methods[] = "
    /**
     * Action method {$i} for controller functionality
     *
     * @param string \$param{$i} Input parameter
     * @return string|void Result of action
     */
    public function action{$i}Action(string \$param{$i} = ''): void
    {
        // Business logic implementation
        \$data = [
            'param' => \$param{$i},
            'timestamp' => time(),
            'random' => rand(1, 1000)
        ];

        \$this->view->assignMultiple(\$data);

        // Additional processing
        if (!empty(\$param{$i})) {
            \$this->addFlashMessage('Success message for action {$i}');
        }
    }";
        }

        return '<?php

declare(strict_types=1);

namespace MyVendor\\TestExtension\\Controller;

use TYPO3\\CMS\\Extbase\\Mvc\\Controller\\ActionController;

/**
 * Test Controller ' . $number . ' for LOC analysis
 * Generated class with realistic TYPO3 controller patterns
 */
class Controller' . $number . ' extends ActionController
{' . implode('', $methods) . '
}
';
    }

    private function generateModelClass(int $number, int $targetLines): string
    {
        $properties = [];
        $methods = [];
        $propertiesNeeded = max(1, \intval($targetLines / 15));

        for ($i = 1; $i <= $propertiesNeeded; ++$i) {
            $properties[] = "
    /**
     * @var string Property {$i} description
     */
    protected string \$property{$i} = '';";

            $methods[] = "
    /**
     * Get property{$i}
     *
     * @return string
     */
    public function getProperty{$i}(): string
    {
        return \$this->property{$i};
    }

    /**
     * Set property{$i}
     *
     * @param string \$property{$i}
     * @return void
     */
    public function setProperty{$i}(string \$property{$i}): void
    {
        \$this->property{$i} = \$property{$i};
    }";
        }

        return '<?php

declare(strict_types=1);

namespace MyVendor\\TestExtension\\Domain\\Model;

use TYPO3\\CMS\\Extbase\\DomainObject\\AbstractEntity;

/**
 * Test Model ' . $number . ' for LOC analysis
 * Generated domain model with properties and accessors
 */
class Model' . $number . ' extends AbstractEntity
{' . implode('', $properties) . implode('', $methods) . '
}
';
    }

    private function generateServiceClass(int $targetLines): string
    {
        $methods = [];
        $methodsNeeded = max(1, \intval($targetLines / 30));

        for ($i = 1; $i <= $methodsNeeded; ++$i) {
            $methods[] = "
    /**
     * Service method {$i} with business logic
     *
     * @param array \$data Input data for processing
     * @return array Processed result
     */
    public function processData{$i}(array \$data): array
    {
        \$result = [];

        foreach (\$data as \$key => \$value) {
            // Complex processing logic
            if (is_string(\$value)) {
                \$result[\$key] = strtoupper(\$value);
            } elseif (is_numeric(\$value)) {
                \$result[\$key] = \$value * 2;
            } else {
                \$result[\$key] = \$value;
            }
        }

        // Additional validation
        \$result['processed_at'] = date('Y-m-d H:i:s');
        \$result['method'] = 'processData{$i}';

        return \$result;
    }";
        }

        return '<?php

declare(strict_types=1);

namespace MyVendor\\TestExtension\\Service;

/**
 * Test Service for LOC analysis
 * Generated service class with business logic methods
 */
class TestService
{' . implode('', $methods) . '
}
';
    }

    private function generateViewHelperClass(int $number, int $targetLines): string
    {
        $renderLines = max(5, \intval($targetLines / 8));
        $renderContent = '';

        for ($i = 1; $i <= $renderLines; ++$i) {
            $renderContent .= "        // Render logic step {$i}\n";
            $renderContent .= "        \$output .= '<div class=\"step-{$i}\">' . htmlspecialchars(\$this->arguments['content'] ?? '') . '</div>';\n";
        }

        return '<?php

declare(strict_types=1);

namespace MyVendor\\TestExtension\\ViewHelpers;

use TYPO3\\CMS\\Fluid\\Core\\ViewHelper\\AbstractViewHelper;

/**
 * Test ViewHelper ' . $number . ' for LOC analysis
 * Generated ViewHelper with render method
 */
class ViewHelper' . $number . ' extends AbstractViewHelper
{
    /**
     * Initialize arguments for this ViewHelper
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument(\'content\', \'string\', \'Content to render\', false, \'\');
        $this->registerArgument(\'class\', \'string\', \'CSS class\', false, \'default\');
    }

    /**
     * Render the ViewHelper output
     *
     * @return string Rendered content
     */
    public function render(): string
    {
        $output = \'\';
' . $renderContent . '
        return $output;
    }
}
';
    }

    private function generateUtilityClass(int $number, int $targetLines): string
    {
        $methods = [];
        $methodsNeeded = max(1, \intval($targetLines / 25));

        for ($i = 1; $i <= $methodsNeeded; ++$i) {
            $methods[] = "
    /**
     * Utility method {$i} for various operations
     *
     * @param mixed \$input Input parameter
     * @return mixed Processed output
     */
    public static function utilityMethod{$i}(\$input)
    {
        // Complex utility logic
        if (is_array(\$input)) {
            return array_map(function(\$item) {
                return is_string(\$item) ? trim(\$item) : \$item;
            }, \$input);
        }

        if (is_string(\$input)) {
            return htmlspecialchars(trim(\$input), ENT_QUOTES, 'UTF-8');
        }

        return \$input;
    }";
        }

        return '<?php

declare(strict_types=1);

namespace MyVendor\\TestExtension\\Utility;

/**
 * Test Utility ' . $number . ' for LOC analysis
 * Generated utility class with static methods
 */
class Utility' . $number . '
{' . implode('', $methods) . '
}
';
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
}
