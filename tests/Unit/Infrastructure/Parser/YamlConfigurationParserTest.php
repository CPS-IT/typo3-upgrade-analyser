<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Parser;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\YamlConfigurationParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception\YamlParseException;

/**
 * Test case for YamlConfigurationParser
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Parser\YamlConfigurationParser
 */
class YamlConfigurationParserTest extends TestCase
{
    private MockObject&LoggerInterface $logger;
    private YamlConfigurationParser $parser;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->parser = new YamlConfigurationParser($this->logger);
        $this->fixturesPath = __DIR__ . '/../../../../tests/Fixtures/Configuration';
    }

    public function testGetFormat(): void
    {
        self::assertSame('yaml', $this->parser->getFormat());
    }

    public function testGetName(): void
    {
        self::assertSame('YAML Configuration Parser', $this->parser->getName());
    }

    public function testGetSupportedExtensions(): void
    {
        self::assertSame(['yaml', 'yml'], $this->parser->getSupportedExtensions());
    }

    public function testGetPriority(): void
    {
        self::assertSame(70, $this->parser->getPriority());
    }

    public function testGetRequiredDependencies(): void
    {
        self::assertSame(['symfony/yaml'], $this->parser->getRequiredDependencies());
    }

    public function testIsReady(): void
    {
        self::assertTrue($this->parser->isReady());
    }

    public function testSupportsWithKnownTYPO3Files(): void
    {
        $knownFiles = [
            '/path/to/Services.yaml',
            '/path/to/services.yaml',
            '/path/to/config.yaml',
            '/path/to/site.yaml',
        ];

        foreach ($knownFiles as $file) {
            self::assertTrue($this->parser->supports($file), "Should support {$file}");
        }
    }

    public function testSupportsWithTYPO3ConfigurationPaths(): void
    {
        $configPaths = [
            '/var/www/config/sites/main/config.yaml',
            '/var/www/config/system/settings.yaml',
            '/var/www/ext/my_ext/Configuration/Services.yaml',
            '/var/www/ext/my_ext/Resources/Private/Language/locallang.yaml',
        ];

        foreach ($configPaths as $path) {
            self::assertTrue($this->parser->supports($path), "Should support path {$path}");
        }
    }

    public function testSupportsWithUnsupportedExtension(): void
    {
        self::assertFalse($this->parser->supports('/path/to/file.php'));
        self::assertFalse($this->parser->supports('/path/to/file.json'));
        self::assertFalse($this->parser->supports('/path/to/file.txt'));
    }

    public function testParseFileWithValidServices(): void
    {
        $servicesFile = $this->fixturesPath . '/Services.yaml';

        $result = $this->parser->parseFile($servicesFile);

        self::assertTrue($result->isSuccessful());
        self::assertSame('yaml', $result->getFormat());
        self::assertSame($servicesFile, $result->getSourcePath());

        $data = $result->getData();
        self::assertIsArray($data);
        self::assertArrayHasKey('services', $data);
        self::assertArrayHasKey('parameters', $data);
        self::assertArrayHasKey('when@dev', $data);
        self::assertArrayHasKey('when@prod', $data);

        // Test services section
        $services = $data['services'];
        self::assertArrayHasKey('_defaults', $services);
        self::assertArrayHasKey('App\\', $services);
        self::assertTrue($services['_defaults']['autowire']);
        self::assertTrue($services['_defaults']['autoconfigure']);
    }

    public function testParseFileWithValidSiteConfiguration(): void
    {
        $siteConfigFile = $this->fixturesPath . '/SiteConfiguration.yaml';

        $result = $this->parser->parseFile($siteConfigFile);

        self::assertTrue($result->isSuccessful());
        $data = $result->getData();

        self::assertArrayHasKey('rootPageId', $data);
        self::assertArrayHasKey('base', $data);
        self::assertArrayHasKey('languages', $data);
        self::assertArrayHasKey('errorHandling', $data);
        self::assertArrayHasKey('routes', $data);
        self::assertArrayHasKey('routeEnhancers', $data);

        self::assertSame(1, $data['rootPageId']);
        self::assertSame('https://example.com/', $data['base']);
        self::assertIsArray($data['languages']);
        self::assertCount(2, $data['languages']); // English and German
    }

    public function testParseFileWithInvalidYaml(): void
    {
        $invalidFile = $this->fixturesPath . '/InvalidSyntax.yaml';

        $result = $this->parser->parseFile($invalidFile);

        self::assertFalse($result->isSuccessful());
        self::assertTrue($result->hasErrors());
        self::assertStringContainsString('parsing', strtolower($result->getFirstError()));
    }

    public function testParseContentWithValidYaml(): void
    {
        $content = <<<'YAML'
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  App\Service\TestService:
    arguments:
      $param1: '@service.dependency'
      $param2: '%parameter.value%'
    tags:
      - { name: 'app.service', priority: 100 }

parameters:
  app.environment: 'test'
  app.debug: true
YAML;

        $result = $this->parser->parseContent($content, '/test/services.yaml');

        self::assertTrue($result->isSuccessful());
        $data = $result->getData();

        self::assertArrayHasKey('services', $data);
        self::assertArrayHasKey('parameters', $data);

        $services = $data['services'];
        self::assertTrue($services['_defaults']['autowire']);
        self::assertFalse($services['_defaults']['public']);

        $testService = $services['App\\Service\\TestService'];
        self::assertSame('@service.dependency', $testService['arguments']['$param1']);
        self::assertSame('%parameter.value%', $testService['arguments']['$param2']);

        $parameters = $data['parameters'];
        self::assertSame('test', $parameters['app.environment']);
        self::assertTrue($parameters['app.debug']);
    }

    public function testParseContentWithSiteConfiguration(): void
    {
        $content = <<<'YAML'
rootPageId: 1
base: 'https://example.com/'
languages:
  -
    title: English
    enabled: true
    languageId: 0
    base: /
    locale: en_US.UTF-8
    iso-639-1: en
  -
    title: German
    enabled: true
    languageId: 1
    base: /de/
    locale: de_DE.UTF-8
    iso-639-1: de

errorHandling:
  - errorCode: 404
    errorHandler: Page
    errorContentSource: 't3://page?uid=404'

routes:
  - route: robots.txt
    type: staticText
    content: |
      User-agent: *
      Disallow: /typo3/
YAML;

        $result = $this->parser->parseContent($content, '/test/site.yaml');

        self::assertTrue($result->isSuccessful());
        $data = $result->getData();

        self::assertSame(1, $data['rootPageId']);
        self::assertSame('https://example.com/', $data['base']);
        self::assertCount(2, $data['languages']);
        self::assertSame('English', $data['languages'][0]['title']);
        self::assertSame('German', $data['languages'][1]['title']);
        self::assertCount(1, $data['errorHandling']);
        self::assertSame(404, $data['errorHandling'][0]['errorCode']);
    }

    public function testParseContentWithEmptyContent(): void
    {
        $result = $this->parser->parseContent('   ', '/test/empty.yaml');

        self::assertTrue($result->isSuccessful());
        self::assertSame([], $result->getData());
        self::assertTrue($result->hasWarnings());
        self::assertStringContainsString('empty', $result->getFirstWarning());
    }

    public function testParseContentWithOnlyComments(): void
    {
        $content = <<<'YAML'
# This is a comment file
# Another comment
# Yet another comment
YAML;

        $result = $this->parser->parseContent($content, '/test/comments.yaml');

        self::assertTrue($result->isSuccessful());
        self::assertSame([], $result->getData());
    }

    public function testParseContentWithInvalidYamlSyntax(): void
    {
        $content = <<<'YAML'
services:
  _defaults:
    autowire: true
   autoconfigure: true  # Wrong indentation
YAML;

        $this->expectException(YamlParseException::class);
        $this->expectExceptionMessage('parsing');

        $this->parser->parseContent($content, '/test/invalid.yaml');
    }

    public function testParseContentWithNonArrayRoot(): void
    {
        $content = 'simple string value';

        $this->expectException(YamlParseException::class);
        $this->expectExceptionMessage('YAML root must be an array');

        $this->parser->parseContent($content, '/test/string.yaml');
    }

    public function testValidateServicesYamlWithValidData(): void
    {
        $servicesFile = $this->fixturesPath . '/Services.yaml';

        $result = $this->parser->parseFile($servicesFile);

        self::assertTrue($result->isSuccessful());
        self::assertFalse($result->hasErrors());
    }

    public function testValidateServicesYamlWithMissingServicesSection(): void
    {
        $content = <<<'YAML'
parameters:
  app.debug: true
# Missing services section
YAML;

        $tempFile = tempnam(sys_get_temp_dir(), 'Services_') . '.yaml';
        file_put_contents($tempFile, $content);

        try {
            $result = $this->parser->parseFile($tempFile);

            self::assertTrue($result->isSuccessful());
            self::assertTrue($result->hasWarnings());
            self::assertStringContainsString('does not contain services section', $result->getFirstWarning());
        } finally {
            unlink($tempFile);
        }
    }

    public function testValidateServicesYamlWithInvalidServiceStructure(): void
    {
        $content = <<<'YAML'
services:
  _defaults:
    autowire: true

  App\Service\TestService:
    arguments: "not an array"  # Should be array
    tags: "not an array"       # Should be array
YAML;

        $tempFile = tempnam(sys_get_temp_dir(), 'Services_') . '.yaml';
        file_put_contents($tempFile, $content);

        try {
            $result = $this->parser->parseFile($tempFile);

            self::assertFalse($result->isSuccessful());
            self::assertTrue($result->hasErrors());

            $errors = $result->getErrors();
            $argumentErrors = array_filter($errors, fn($error) => str_contains($error, 'arguments must be an array'));
            $tagErrors = array_filter($errors, fn($error) => str_contains($error, 'tags must be an array'));

            self::assertNotEmpty($argumentErrors);
            self::assertNotEmpty($tagErrors);
        } finally {
            unlink($tempFile);
        }
    }

    public function testValidateSiteConfigurationWithValidData(): void
    {
        $siteConfigFile = $this->fixturesPath . '/SiteConfiguration.yaml';

        $result = $this->parser->parseFile($siteConfigFile);

        self::assertTrue($result->isSuccessful());
        self::assertFalse($result->hasErrors());
    }

    public function testValidateSiteConfigurationWithMissingRequiredKeys(): void
    {
        $content = <<<'YAML'
languages:
  - title: English
    enabled: true
# Missing rootPageId and base
YAML;

        $tempFile = tempnam(sys_get_temp_dir(), 'site_') . '.yaml';
        file_put_contents($tempFile, $content);

        try {
            $result = $this->parser->parseFile($tempFile);

            self::assertFalse($result->isSuccessful());
            self::assertTrue($result->hasErrors());

            $errors = $result->getErrors();
            $missingRootPageIdErrors = array_filter($errors, fn($error) => str_contains($error, 'rootPageId'));
            $missingBaseErrors = array_filter($errors, fn($error) => str_contains($error, 'base'));

            self::assertNotEmpty($missingRootPageIdErrors);
            self::assertNotEmpty($missingBaseErrors);
        } finally {
            unlink($tempFile);
        }
    }

    public function testValidateSiteConfigurationWithInvalidValues(): void
    {
        $content = <<<'YAML'
rootPageId: "not_a_number"
base: "not_a_url"
languages: "not_an_array"
YAML;

        $tempFile = tempnam(sys_get_temp_dir(), 'site_') . '.yaml';
        file_put_contents($tempFile, $content);

        try {
            $result = $this->parser->parseFile($tempFile);

            self::assertFalse($result->isSuccessful());
            self::assertTrue($result->hasErrors());

            $errors = $result->getErrors();
            self::assertCount(3, $errors);

            $rootPageIdErrors = array_filter($errors, fn($error) => str_contains($error, 'rootPageId must be a positive integer'));
            $baseErrors = array_filter($errors, fn($error) => str_contains($error, 'base must be a valid URL'));
            $languagesErrors = array_filter($errors, fn($error) => str_contains($error, 'languages must be an array'));

            self::assertNotEmpty($rootPageIdErrors);
            self::assertNotEmpty($baseErrors);
            self::assertNotEmpty($languagesErrors);
        } finally {
            unlink($tempFile);
        }
    }

    public function testValidateLanguageFileStructure(): void
    {
        $content = <<<'YAML'
label.key.1: "First label"
label.key.2: "Second label"
module.title: "Module Title"
LLL:EXT:extension/Resources/Private/Language/locallang.xlf:key: "External reference"
YAML;

        $tempFile = tempnam(sys_get_temp_dir(), 'language_') . '.yaml';
        $languageFile = str_replace(sys_get_temp_dir(), sys_get_temp_dir() . '/Resources/Private/Language', $tempFile);
        file_put_contents($tempFile, $content);

        try {
            $result = $this->parser->parseFile($tempFile);

            self::assertTrue($result->isSuccessful());
            self::assertFalse($result->hasErrors());
        } finally {
            unlink($tempFile);
        }
    }

    public function testPostProcessingWithBooleanNormalization(): void
    {
        $content = <<<'YAML'
services:
  App\Service\TestService:
    public: "true"
    shared: "false"
    lazy: "1"
    autoconfigure: "0"
    autowire: "yes"
    synthetic: "no"
YAML;

        $result = $this->parser->parseContent($content, '/test/boolean.yaml');

        self::assertTrue($result->isSuccessful());
        $data = $result->getData();

        $service = $data['services']['App\\Service\\TestService'];
        self::assertTrue($service['public']);
        self::assertFalse($service['shared']);
        self::assertTrue($service['lazy']);
        self::assertFalse($service['autoconfigure']);
        self::assertTrue($service['autowire']);
        self::assertFalse($service['synthetic']);
    }

    public function testYamlStructureValidationWithDeepNesting(): void
    {
        $content = <<<'YAML'
level1:
  level2:
    level3:
      level4:
        level5:
          level6:
            level7:
              level8:
                level9:
                  level10:
                    level11:
                      level12:
                        deep_value: "very deep"
YAML;

        $this->parser->setParserOptions(['max_nesting_depth' => 5]);

        $result = $this->parser->parseContent($content, '/test/deep.yaml');

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->hasWarnings());
        self::assertStringContainsString('deeply nested', $result->getFirstWarning());
    }

    public function testYamlStructureValidationWithEmptySections(): void
    {
        $content = <<<'YAML'
services:
  _defaults:
    autowire: true

empty_section: {}
another_empty: []
valid_section:
  key: value
YAML;

        $result = $this->parser->parseContent($content, '/test/empty_sections.yaml');

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->hasWarnings());

        $warnings = $result->getWarnings();
        $emptySectionWarnings = array_filter($warnings, fn($warning) => str_contains($warning, 'Empty configuration section'));
        self::assertCount(2, $emptySectionWarnings);
    }

    public function testYamlStructureValidationWithCircularReferences(): void
    {
        $content = <<<'YAML'
self_reference: "This contains self_reference in the value"
normal_key: "normal value"
another_ref: "This mentions another_ref"
YAML;

        $result = $this->parser->parseContent($content, '/test/circular.yaml');

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->hasWarnings());

        $warnings = $result->getWarnings();
        $circularWarnings = array_filter($warnings, fn($warning) => str_contains($warning, 'circular reference'));
        self::assertCount(2, $circularWarnings);
    }

    public function testParserOptionsManagement(): void
    {
        $options = [
            'yaml_flags' => \Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP,
            'max_nesting_depth' => 15,
        ];

        $this->parser->setParserOptions($options);
        self::assertSame($options, $this->parser->getParserOptions());
    }

    public function testLoggerIntegration(): void
    {
        $content = <<<'YAML'
test: value
YAML;

        $debugCalls = [];
        $this->logger->expects(self::atLeast(2))
            ->method('debug')
            ->willReturnCallback(function ($message, $context) use (&$debugCalls) {
                $debugCalls[] = ['message' => $message, 'context' => $context];
            });

        $result = $this->parser->parseContent($content, '/test/log.yaml');
        self::assertTrue($result->isSuccessful());

        // Additional assertions to ensure test is not risky
        self::assertSame(['test' => 'value'], $result->getData());
        self::assertSame('yaml', $result->getFormat());

        // Verify that the expected debug call was made
        $yamlDebugFound = false;
        foreach ($debugCalls as $call) {
            if (str_contains($call['message'], 'YAML configuration parsed successfully') &&
                isset($call['context']['keys_found'], $call['context']['has_nested_data'])) {
                $yamlDebugFound = true;
                break;
            }
        }
        self::assertTrue($yamlDebugFound, 'Expected YAML configuration debug log not found');
    }

    public function testComplexYamlStructureParsing(): void
    {
        $content = <<<'YAML'
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false
    bind:
      $projectDir: '%kernel.project_dir%'
      $environment: '%kernel.environment%'

  App\:
    resource: '../src/*'
    exclude: '../src/{DependencyInjection,Entity,Migrations,Tests,Kernel.php}'

  App\Controller\:
    resource: '../src/Controller'
    tags: ['controller.service_arguments']

  App\Service\DatabaseService:
    arguments:
      $connectionPool: '@TYPO3\CMS\Core\Database\ConnectionPool'
      $settings:
        timeout: 30
        retry_attempts: 3
        cache_enabled: true

  App\EventListener\UserLoginListener:
    tags:
      - name: event.listener
        event: 'TYPO3\CMS\Core\Authentication\Event\BeforeUserLoginEvent'
        method: onBeforeUserLogin
        priority: 100

parameters:
  locale: 'en'
  app.version: '2.1.0'
  database.options:
    charset: 'utf8mb4'
    collate: 'utf8mb4_unicode_ci'
  cache.settings:
    default_ttl: 3600
    enabled: true
    adapters:
      - 'cache.adapter.redis'
      - 'cache.adapter.filesystem'

when@dev:
  services:
    App\Service\DebugService:
      public: true
      arguments:
        $debugMode: true

when@prod:
  parameters:
    cache.settings:
      default_ttl: 7200
      enabled: true
YAML;

        $result = $this->parser->parseContent($content, '/test/complex.yaml');

        self::assertTrue($result->isSuccessful());
        self::assertFalse($result->hasErrors());

        $data = $result->getData();

        // Test complex service configuration
        self::assertTrue($data['services']['_defaults']['autowire']);
        self::assertSame('%kernel.project_dir%', $data['services']['_defaults']['bind']['$projectDir']);

        // Test nested service arguments
        $dbService = $data['services']['App\\Service\\DatabaseService'];
        self::assertSame('@TYPO3\\CMS\\Core\\Database\\ConnectionPool', $dbService['arguments']['$connectionPool']);
        self::assertSame(30, $dbService['arguments']['$settings']['timeout']);
        self::assertTrue($dbService['arguments']['$settings']['cache_enabled']);

        // Test event listener tags
        $eventListener = $data['services']['App\\EventListener\\UserLoginListener'];
        self::assertSame('event.listener', $eventListener['tags'][0]['name']);
        self::assertSame(100, $eventListener['tags'][0]['priority']);

        // Test parameters
        self::assertSame('2.1.0', $data['parameters']['app.version']);
        self::assertSame('utf8mb4_unicode_ci', $data['parameters']['database.options']['collate']);
        self::assertCount(2, $data['parameters']['cache.settings']['adapters']);

        // Test environment-specific configuration
        self::assertTrue($data['when@dev']['services']['App\\Service\\DebugService']['public']);
        self::assertSame(7200, $data['when@prod']['parameters']['cache.settings']['default_ttl']);
    }

    /**
     * @dataProvider typo3YamlPatternsProvider
     */
    public function testLooksLikeTypo3YamlFilePatterns(string $content, bool $expectedResult): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pattern_test_') . '.yaml';
        file_put_contents($tempFile, $content);

        try {
            $result = $this->parser->supports($tempFile);
            self::assertSame($expectedResult, $result);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public function typo3YamlPatternsProvider(): array
    {
        return [
            'services section' => [
                'services: {}',
                true
            ],
            'base URL pattern' => [
                'base: https://example.com/',
                true
            ],
            'rootPageId pattern' => [
                'rootPageId: 1',
                true
            ],
            'languages section' => [
                'languages: []',
                true
            ],
            'routes section' => [
                'routes: []',
                true
            ],
            'errorHandling section' => [
                'errorHandling: []',
                true
            ],
            'class with namespace' => [
                'class: App\\Service\\MyService',
                true
            ],
            '_defaults section' => [
                '_defaults: {}',
                true
            ],
            'regular YAML' => [
                'simple: value',
                false
            ],
            'empty file' => [
                '',
                false
            ],
        ];
    }
}
