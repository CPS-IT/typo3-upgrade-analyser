# Configuration Parsing Framework - Feature Plan

|-----------------------|-------------------------------------------------|
| **Status**           | partially implemented |
| **Priority**         | low |
| **Dependencies**     | Core Analyzer System, External Tool Integration |

## Feature Overview

The Configuration Parsing Framework is a critical component of the TYPO3 Upgrade Analyzer that enables safe parsing and analysis of TYPO3 configuration files without requiring TYPO3 to be loaded or operational. This framework operates as a standalone service that can interpret various TYPO3 configuration formats, extract meaningful data, and provide structured access to configuration information for analysis purposes.

### Business Value
- **Standalone Operation**: Parse TYPO3 configurations without TYPO3 dependencies
- **Security**: Safe parsing without executing potentially dangerous PHP code
- **Comprehensive Coverage**: Support for all major TYPO3 configuration formats
- **Version Agnostic**: Handle configuration changes across TYPO3 versions
- **Analysis Foundation**: Provide structured configuration data for upgrade analysis

### Key Challenges Addressed
- **PHP Code Execution**: Safely parse PHP configuration files without execution
- **Format Diversity**: Handle multiple configuration formats (PHP, YAML, TypoScript, XML)
- **Version Variations**: Support configuration format changes across TYPO3 versions
- **Complex Structures**: Parse nested and hierarchical configuration structures
- **Error Recovery**: Handle malformed or incomplete configuration files gracefully

## Technical Requirements

### Configuration File Types to Support

#### 1. Core TYPO3 Configuration Files
```
typo3conf/
├── LocalConfiguration.php          # Main TYPO3 configuration (v6.2+)
├── AdditionalConfiguration.php     # Additional configuration overrides
├── PackageStates.php              # Extension activation states
└── ENABLE_INSTALL_TOOL            # Install tool state file

config/system/                      # Modern configuration location (Composer mode)
├── settings.php                    # Main configuration
└── additional.php                  # Additional configuration
```

#### 2. Extension Configuration Files
```
typo3conf/ext/extension_key/
├── ext_localconf.php               # Extension bootstrap configuration
├── ext_tables.php                  # Database table definitions
├── Configuration/
│   ├── Services.yaml               # Service definitions (modern)
│   ├── Services.php                # Service definitions (legacy)
│   ├── TCA/                        # Table Configuration Array
│   │   ├── tx_table.php
│   │   └── Overrides/
│   ├── TypoScript/                 # TypoScript configuration
│   │   ├── setup.txt
│   │   ├── constants.txt
│   │   └── Static/
│   ├── FlexForms/                  # FlexForm definitions
│   │   └── flexform.xml
│   └── PageTS/                     # Page TSconfig
└── ext_emconf.php                  # Extension metadata
```

#### 3. Site and Multi-Site Configuration
```
config/sites/
├── main/
│   ├── config.yaml                 # Site configuration
│   └── csp.yaml                    # Content Security Policy
└── secondary/
    └── config.yaml
```

#### 4. TypoScript Files
```
typo3conf/ext/extension/
├── Configuration/TypoScript/
│   ├── setup.typoscript            # Modern TypoScript files
│   ├── constants.typoscript
│   ├── setup.txt                   # Legacy TypoScript files
│   └── constants.txt
```

### Parsing Requirements

#### 1. PHP Configuration Files
- **Safe Parsing**: Parse PHP arrays without executing code
- **Syntax Handling**: Handle complex PHP syntax and constructs
- **Variable Resolution**: Resolve PHP constants and variables where possible
- **Conditional Logic**: Handle conditional configuration blocks
- **Include Resolution**: Track file inclusions and dependencies

#### 2. YAML Configuration Files
- **Schema Validation**: Validate against known TYPO3 YAML schemas
- **Environment Variables**: Resolve environment variable placeholders
- **Multi-Document Support**: Handle YAML files with multiple documents
- **Type Coercion**: Proper type conversion for configuration values

#### 3. TypoScript Files
- **Syntax Parsing**: Handle TypoScript's unique syntax
- **Import Resolution**: Resolve @import statements
- **Condition Evaluation**: Parse conditional TypoScript blocks
- **Constant Substitution**: Handle TypoScript constants
- **Object Path Resolution**: Resolve TypoScript object hierarchies

#### 4. XML Configuration Files
- **Schema Validation**: Validate against TYPO3 XML schemas
- **FlexForm Parsing**: Extract FlexForm structure and constraints
- **Namespace Handling**: Proper XML namespace resolution
- **XPath Support**: Enable XPath-based configuration queries

## Implementation Strategy

### Architecture Overview
The framework follows a layered architecture with clear separation of concerns:

1. **Parser Abstraction Layer**: Common interfaces for all parser types
2. **Format-Specific Parsers**: Specialized parsers for each configuration format
3. **AST Processing Layer**: Abstract Syntax Tree processing for PHP files
4. **Configuration Assembly**: Merge and validate parsed configurations
5. **Context Integration**: Populate AnalysisContext with configuration data

### Safe Parsing Strategy

#### PHP File Parsing without Execution
```php
// Instead of dangerous require/include:
// require $configFile; // DANGEROUS - executes arbitrary code

// Use AST parsing:
$parser = new PhpParser\Parser\Php7(new PhpParser\Lexer());
$ast = $parser->parse(file_get_contents($configFile));

// Extract return arrays safely:
$visitor = new ReturnArrayExtractor();
$traverser = new PhpParser\NodeTraverser();
$traverser->addVisitor($visitor);
$traverser->traverse($ast);

$config = $visitor->getExtractedArray();
```

#### Multi-Format Parser Factory
```php
class ConfigurationParserFactory
{
    public function createParser(string $filePath): ConfigurationParserInterface
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        return match($extension) {
            'php' => new PhpConfigurationParser(),
            'yaml', 'yml' => new YamlConfigurationParser(),
            'xml' => new XmlConfigurationParser(),
            'txt', 'typoscript' => new TypoScriptParser(),
            default => throw new UnsupportedFormatException()
        };
    }
}
```

### Error Handling and Recovery

#### Graceful Degradation Strategy
```php
class SafeConfigurationParser
{
    public function parse(string $filePath): ParseResult
    {
        try {
            $parser = $this->parserFactory->createParser($filePath);
            $config = $parser->parse($filePath);

            return ParseResult::success($config);
        } catch (ParseException $e) {
            $this->logger->warning('Configuration parse failed', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);

            // Attempt partial parsing or fallback strategies
            return $this->attemptPartialParsing($filePath, $e);
        }
    }
}
```

## Detailed File Structure

### Domain Layer

#### Configuration Entities
```php
// src/Domain/Entity/Configuration.php
class Configuration
{
    private string $scope;              // 'global', 'extension', 'site'
    private string $sourceFile;
    private array $data;
    private array $metadata;
    private ConfigurationSchema $schema;
    private array $parseErrors;

    public function __construct(
        string $scope,
        string $sourceFile,
        array $data,
        ConfigurationSchema $schema
    );

    public function get(string $path, mixed $default = null): mixed;
    public function has(string $path): bool;
    public function set(string $path, mixed $value): void;
    public function merge(Configuration $other): Configuration;

    public function getArrayPath(string $path): array;
    public function getDatabaseConfiguration(): DatabaseConfiguration;
    public function getMailConfiguration(): MailConfiguration;
    public function getCacheConfiguration(): CacheConfiguration;

    public function isValid(): bool;
    public function getValidationErrors(): array;
    public function getParseErrors(): array;
}

// src/Domain/Entity/ExtensionConfiguration.php
class ExtensionConfiguration extends Configuration
{
    private string $extensionKey;
    private array $services;
    private array $tcaOverrides;
    private array $hooks;
    private array $xclasses;

    public function getExtensionKey(): string;
    public function getServices(): array;
    public function getTcaOverrides(): array;
    public function getHooks(): array;
    public function getXclasses(): array;

    public function hasService(string $serviceType): bool;
    public function hasTcaOverride(string $tableName): bool;
    public function usesHooks(): bool;
    public function usesXclasses(): bool;
}

// src/Domain/Entity/SiteConfiguration.php
class SiteConfiguration extends Configuration
{
    private string $siteIdentifier;
    private array $languages;
    private array $routes;
    private array $errorHandling;

    public function getSiteIdentifier(): string;
    public function getLanguages(): array;
    public function getDefaultLanguage(): Language;
    public function getRoutes(): array;
    public function getErrorHandling(): array;

    public function isMultilingual(): bool;
    public function hasCustomRouting(): bool;
}
```

#### Value Objects
```php
// src/Domain/ValueObject/ConfigurationKey.php
class ConfigurationKey
{
    private array $segments;

    public function __construct(string $path)
    {
        $this->segments = explode('.', $path);
    }

    public function getSegments(): array;
    public function toString(): string;
    public function getParent(): ?ConfigurationKey;
    public function getChild(string $segment): ConfigurationKey;
}

// src/Domain/ValueObject/ParseResult.php
class ParseResult
{
    private bool $success;
    private mixed $data;
    private array $errors;
    private array $warnings;
    private ParseMetadata $metadata;

    public static function success(mixed $data, ParseMetadata $metadata = null): self;
    public static function failure(array $errors): self;
    public static function partial(mixed $data, array $warnings): self;

    public function isSuccess(): bool;
    public function getData(): mixed;
    public function getErrors(): array;
    public function getWarnings(): array;
    public function hasWarnings(): bool;
}

// src/Domain/ValueObject/ConfigurationSchema.php
class ConfigurationSchema
{
    private array $requiredKeys;
    private array $optionalKeys;
    private array $typeConstraints;
    private array $valueConstraints;

    public function validate(array $data): ValidationResult;
    public function getRequiredKeys(): array;
    public function getOptionalKeys(): array;
    public function isValidType(string $key, mixed $value): bool;
}
```

### Infrastructure Layer

#### Core Parser Interfaces
```php
// src/Infrastructure/Parser/ConfigurationParserInterface.php
interface ConfigurationParserInterface
{
    public function parse(string $filePath): ParseResult;
    public function supports(string $filePath): bool;
    public function canParseContent(string $content): bool;
    public function getRequiredExtensions(): array;
    public function getPriority(): int;
}

// src/Infrastructure/Parser/AbstractConfigurationParser.php
abstract class AbstractConfigurationParser implements ConfigurationParserInterface
{
    protected LoggerInterface $logger;
    protected ConfigurationSchema $schema;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    abstract protected function doParse(string $content): array;

    final public function parse(string $filePath): ParseResult
    {
        if (!$this->supports($filePath)) {
            throw new UnsupportedFileException($filePath);
        }

        try {
            $content = $this->readFile($filePath);
            $data = $this->doParse($content);

            $metadata = new ParseMetadata($filePath, filesize($filePath));
            return ParseResult::success($data, $metadata);

        } catch (ParseException $e) {
            $this->logParseError($filePath, $e);
            return ParseResult::failure([$e->getMessage()]);
        }
    }

    protected function readFile(string $filePath): string;
    protected function logParseError(string $filePath, Throwable $error): void;
}
```

#### PHP Configuration Parsers
```php
// src/Infrastructure/Parser/Php/PhpConfigurationParser.php
class PhpConfigurationParser extends AbstractConfigurationParser
{
    private Parser $phpParser;
    private ReturnArrayExtractor $arrayExtractor;

    public function __construct(
        LoggerInterface $logger,
        Parser $phpParser,
        ReturnArrayExtractor $arrayExtractor
    ) {
        parent::__construct($logger);
        $this->phpParser = $phpParser;
        $this->arrayExtractor = $arrayExtractor;
    }

    protected function doParse(string $content): array
    {
        try {
            $ast = $this->phpParser->parse($content);

            $traverser = new NodeTraverser();
            $traverser->addVisitor($this->arrayExtractor);
            $traverser->traverse($ast);

            return $this->arrayExtractor->getExtractedArray();

        } catch (Error $e) {
            throw new PhpParseException('Failed to parse PHP file: ' . $e->getMessage(), 0, $e);
        }
    }

    public function supports(string $filePath): bool
    {
        return pathinfo($filePath, PATHINFO_EXTENSION) === 'php';
    }
}

// src/Infrastructure/Parser/Php/ReturnArrayExtractor.php
class ReturnArrayExtractor extends NodeVisitorAbstract
{
    private array $extractedArray = [];
    private array $constants = [];

    public function enterNode(Node $node)
    {
        // Extract return statements with arrays
        if ($node instanceof Node\Stmt\Return_ && $node->expr instanceof Node\Expr\Array_) {
            $this->extractedArray = $this->evaluateArrayNode($node->expr);
        }

        // Track defined constants
        if ($node instanceof Node\Stmt\Const_) {
            foreach ($node->consts as $const) {
                $this->constants[$const->name->name] = $this->evaluateNode($const->value);
            }
        }

        return null;
    }

    private function evaluateArrayNode(Node\Expr\Array_ $arrayNode): array
    {
        $result = [];

        foreach ($arrayNode->items as $item) {
            if ($item === null) continue;

            $key = $item->key ? $this->evaluateNode($item->key) : null;
            $value = $this->evaluateNode($item->value);

            if ($key === null) {
                $result[] = $value;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function evaluateNode(Node $node): mixed
    {
        return match(get_class($node)) {
            Node\Scalar\String_::class => $node->value,
            Node\Scalar\LNumber::class => $node->value,
            Node\Scalar\DNumber::class => $node->value,
            Node\Expr\ConstFetch::class => $this->resolveConstant($node->name->toString()),
            Node\Expr\Array_::class => $this->evaluateArrayNode($node),
            default => null, // Unsupported node type
        };
    }

    public function getExtractedArray(): array
    {
        return $this->extractedArray;
    }
}

// src/Infrastructure/Parser/Php/LocalConfigurationParser.php
class LocalConfigurationParser extends PhpConfigurationParser
{
    public function supports(string $filePath): bool
    {
        return parent::supports($filePath) &&
               (str_contains($filePath, 'LocalConfiguration.php') ||
                str_contains($filePath, 'settings.php'));
    }

    protected function doParse(string $content): array
    {
        $config = parent::doParse($content);

        // Validate TYPO3 configuration structure
        if (!isset($config['TYPO3_CONF_VARS'])) {
            throw new InvalidConfigurationException('Missing TYPO3_CONF_VARS array');
        }

        return $config['TYPO3_CONF_VARS'];
    }
}

// src/Infrastructure/Parser/Php/PackageStatesParser.php
class PackageStatesParser extends PhpConfigurationParser
{
    public function supports(string $filePath): bool
    {
        return parent::supports($filePath) &&
               str_contains($filePath, 'PackageStates.php');
    }

    protected function doParse(string $content): array
    {
        $config = parent::doParse($content);

        if (!isset($config['packages'])) {
            throw new InvalidConfigurationException('Missing packages array in PackageStates.php');
        }

        return $this->normalizePackageStates($config['packages']);
    }

    private function normalizePackageStates(array $packages): array
    {
        $normalized = [];

        foreach ($packages as $packageKey => $packageConfig) {
            $normalized[$packageKey] = [
                'state' => $packageConfig['state'] ?? 'inactive',
                'packagePath' => $packageConfig['packagePath'] ?? '',
                'classesPath' => $packageConfig['classesPath'] ?? 'Classes/',
            ];
        }

        return $normalized;
    }
}
```

#### YAML Configuration Parsers
```php
// src/Infrastructure/Parser/Yaml/YamlConfigurationParser.php
class YamlConfigurationParser extends AbstractConfigurationParser
{
    private YamlParser $yamlParser;
    private EnvironmentVariableResolver $envResolver;

    public function __construct(
        LoggerInterface $logger,
        YamlParser $yamlParser,
        EnvironmentVariableResolver $envResolver
    ) {
        parent::__construct($logger);
        $this->yamlParser = $yamlParser;
        $this->envResolver = $envResolver;
    }

    protected function doParse(string $content): array
    {
        try {
            // Resolve environment variables first
            $resolvedContent = $this->envResolver->resolve($content);

            $data = $this->yamlParser->parse($resolvedContent);

            if (!is_array($data)) {
                throw new InvalidYamlException('YAML file must contain an array/object structure');
            }

            return $data;

        } catch (ParseException $e) {
            throw new YamlParseException('Failed to parse YAML: ' . $e->getMessage(), 0, $e);
        }
    }

    public function supports(string $filePath): bool
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        return in_array($extension, ['yaml', 'yml'], true);
    }
}

// src/Infrastructure/Parser/Yaml/ServicesYamlParser.php
class ServicesYamlParser extends YamlConfigurationParser
{
    public function supports(string $filePath): bool
    {
        return parent::supports($filePath) &&
               str_contains($filePath, 'Services.y');
    }

    protected function doParse(string $content): array
    {
        $data = parent::doParse($content);

        return $this->normalizeServicesConfiguration($data);
    }

    private function normalizeServicesConfiguration(array $data): array
    {
        // Normalize Symfony DI configuration format
        return [
            'services' => $data['services'] ?? [],
            'parameters' => $data['parameters'] ?? [],
            'imports' => $data['imports'] ?? [],
        ];
    }
}

// src/Infrastructure/Parser/Yaml/SiteConfigurationParser.php
class SiteConfigurationParser extends YamlConfigurationParser
{
    public function supports(string $filePath): bool
    {
        return parent::supports($filePath) &&
               str_contains($filePath, 'config/sites/') &&
               str_contains($filePath, 'config.yaml');
    }

    protected function doParse(string $content): array
    {
        $data = parent::doParse($content);

        return $this->validateSiteConfiguration($data);
    }

    private function validateSiteConfiguration(array $data): array
    {
        $required = ['rootPageId', 'base'];

        foreach ($required as $key) {
            if (!isset($data[$key])) {
                throw new InvalidSiteConfigurationException("Missing required key: $key");
            }
        }

        return $data;
    }
}
```

#### TypoScript Parsers
```php
// src/Infrastructure/Parser/TypoScript/TypoScriptParser.php
class TypoScriptParser extends AbstractConfigurationParser
{
    private TypoScriptLexer $lexer;
    private TypoScriptAstBuilder $astBuilder;
    private ImportResolver $importResolver;

    public function __construct(
        LoggerInterface $logger,
        TypoScriptLexer $lexer,
        TypoScriptAstBuilder $astBuilder,
        ImportResolver $importResolver
    ) {
        parent::__construct($logger);
        $this->lexer = $lexer;
        $this->astBuilder = $astBuilder;
        $this->importResolver = $importResolver;
    }

    protected function doParse(string $content): array
    {
        try {
            // Resolve imports first
            $resolvedContent = $this->importResolver->resolve($content);

            // Tokenize TypoScript
            $tokens = $this->lexer->tokenize($resolvedContent);

            // Build AST
            $ast = $this->astBuilder->build($tokens);

            // Convert AST to array structure
            return $this->astToArray($ast);

        } catch (TypoScriptException $e) {
            throw new TypoScriptParseException('TypoScript parse error: ' . $e->getMessage(), 0, $e);
        }
    }

    public function supports(string $filePath): bool
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        return in_array($extension, ['txt', 'typoscript', 'ts'], true);
    }

    private function astToArray(TypoScriptAstNode $ast): array
    {
        $result = [];

        foreach ($ast->getChildren() as $child) {
            $path = $child->getPath();
            $value = $child->getValue();

            $this->setNestedValue($result, $path, $value);
        }

        return $result;
    }

    private function setNestedValue(array &$array, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }

        $current = $value;
    }
}

// src/Infrastructure/Parser/TypoScript/TypoScriptLexer.php
class TypoScriptLexer
{
    private const TOKEN_PATTERNS = [
        'ASSIGNMENT' => '/^([a-zA-Z0-9_.]+)\s*=\s*(.*)$/',
        'COPY' => '/^([a-zA-Z0-9_.]+)\s*<\s*([a-zA-Z0-9_.]+)$/',
        'REFERENCE' => '/^([a-zA-Z0-9_.]+)\s*=<\s*([a-zA-Z0-9_.]+)$/',
        'CLEAR' => '/^([a-zA-Z0-9_.]+)\s*>$/',
        'CONDITION' => '/^\[([^\]]+)\]$/',
        'COMMENT' => '/^(#|\/\/).*$/',
        'IMPORT' => '/^@import\s+[\'"]([^\'"]+)[\'"]$/',
    ];

    public function tokenize(string $content): array
    {
        $tokens = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            $token = $this->parseLineToToken($line, $lineNumber + 1);
            if ($token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    private function parseLineToToken(string $line, int $lineNumber): ?TypoScriptToken
    {
        foreach (self::TOKEN_PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                return new TypoScriptToken($type, $matches, $lineNumber);
            }
        }

        // Unknown line - could be multi-line value continuation
        return new TypoScriptToken('UNKNOWN', [$line], $lineNumber);
    }
}
```

#### XML Configuration Parsers
```php
// src/Infrastructure/Parser/Xml/XmlConfigurationParser.php
class XmlConfigurationParser extends AbstractConfigurationParser
{
    private DOMXPath $xpath;
    private XmlValidator $validator;

    public function __construct(
        LoggerInterface $logger,
        XmlValidator $validator
    ) {
        parent::__construct($logger);
        $this->validator = $validator;
    }

    protected function doParse(string $content): array
    {
        $dom = new DOMDocument();

        // Disable external entity loading for security
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;

        if (!$dom->loadXML($content, LIBXML_NOCDATA | LIBXML_NONET)) {
            throw new XmlParseException('Invalid XML content');
        }

        // Validate against schema if available
        $this->validator->validate($dom);

        $this->xpath = new DOMXPath($dom);

        return $this->domToArray($dom->documentElement);
    }

    public function supports(string $filePath): bool
    {
        return pathinfo($filePath, PATHINFO_EXTENSION) === 'xml';
    }

    private function domToArray(DOMElement $element): array
    {
        $result = [];

        // Handle attributes
        if ($element->hasAttributes()) {
            foreach ($element->attributes as $attr) {
                $result['@' . $attr->name] = $attr->value;
            }
        }

        // Handle child elements
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $name = $child->nodeName;
                $value = $this->domToArray($child);

                if (isset($result[$name])) {
                    if (!is_array($result[$name]) || !isset($result[$name][0])) {
                        $result[$name] = [$result[$name]];
                    }
                    $result[$name][] = $value;
                } else {
                    $result[$name] = $value;
                }
            } elseif ($child instanceof DOMText) {
                $text = trim($child->textContent);
                if (!empty($text)) {
                    if (empty($result)) {
                        return $text;
                    }
                    $result['#text'] = $text;
                }
            }
        }

        return $result;
    }
}

// src/Infrastructure/Parser/Xml/FlexFormParser.php
class FlexFormParser extends XmlConfigurationParser
{
    public function supports(string $filePath): bool
    {
        return parent::supports($filePath) &&
               (str_contains($filePath, 'flexform') ||
                str_contains($filePath, 'FlexForm'));
    }

    protected function doParse(string $content): array
    {
        $data = parent::doParse($content);

        return $this->normalizeFlexFormStructure($data);
    }

    private function normalizeFlexFormStructure(array $data): array
    {
        // Extract FlexForm data structure
        $result = [
            'sheets' => [],
            'fields' => [],
            'meta' => []
        ];

        if (isset($data['T3DataStructure'])) {
            $structure = $data['T3DataStructure'];

            // Process sheets
            if (isset($structure['sheets'])) {
                foreach ($structure['sheets'] as $sheetKey => $sheet) {
                    $result['sheets'][$sheetKey] = $this->processFlexFormSheet($sheet);
                }
            }

            // Process root level fields
            if (isset($structure['ROOT'])) {
                $result['fields'] = $this->processFlexFormElements($structure['ROOT']);
            }
        }

        return $result;
    }

    private function processFlexFormSheet(array $sheet): array
    {
        return [
            'title' => $sheet['title'] ?? '',
            'elements' => $this->processFlexFormElements($sheet['ROOT'] ?? [])
        ];
    }

    private function processFlexFormElements(array $root): array
    {
        $elements = [];

        if (isset($root['el'])) {
            foreach ($root['el'] as $fieldKey => $field) {
                $elements[$fieldKey] = [
                    'type' => $field['TCEforms']['config']['type'] ?? 'input',
                    'label' => $field['TCEforms']['label'] ?? $fieldKey,
                    'config' => $field['TCEforms']['config'] ?? []
                ];
            }
        }

        return $elements;
    }
}
```

### Application Layer

#### Configuration Service
```php
// src/Application/Service/ConfigurationService.php
class ConfigurationService
{
    public function __construct(
        private readonly ConfigurationParserFactory $parserFactory,
        private readonly ConfigurationRepository $repository,
        private readonly ConfigurationValidator $validator,
        private readonly LoggerInterface $logger
    ) {}

    public function parseInstallationConfiguration(Installation $installation): InstallationConfiguration
    {
        $config = new InstallationConfiguration($installation);

        // Parse main configuration
        $this->parseMainConfiguration($installation, $config);

        // Parse extension configurations
        $this->parseExtensionConfigurations($installation, $config);

        // Parse site configurations
        $this->parseSiteConfigurations($installation, $config);

        // Validate complete configuration
        $this->validator->validate($config);

        return $config;
    }

    private function parseMainConfiguration(Installation $installation, InstallationConfiguration $config): void
    {
        $configPaths = [
            $installation->getPath() . '/typo3conf/LocalConfiguration.php',
            $installation->getPath() . '/config/system/settings.php',
        ];

        foreach ($configPaths as $path) {
            if (file_exists($path)) {
                try {
                    $parser = $this->parserFactory->createParser($path);
                    $result = $parser->parse($path);

                    if ($result->isSuccess()) {
                        $config->setMainConfiguration(new Configuration('global', $path, $result->getData()));
                        break;
                    }
                } catch (Exception $e) {
                    $this->logger->warning('Failed to parse main configuration', [
                        'file' => $path,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    private function parseExtensionConfigurations(Installation $installation, InstallationConfiguration $config): void
    {
        foreach ($installation->getExtensions() as $extension) {
            $extensionConfig = $this->parseExtensionConfiguration($extension);
            if ($extensionConfig) {
                $config->addExtensionConfiguration($extensionConfig);
            }
        }
    }

    private function parseExtensionConfiguration(Extension $extension): ?ExtensionConfiguration
    {
        $configFiles = [
            $extension->getPath() . '/ext_localconf.php',
            $extension->getPath() . '/Configuration/Services.yaml',
            $extension->getPath() . '/Configuration/Services.php',
        ];

        $parsedData = [];

        foreach ($configFiles as $file) {
            if (file_exists($file)) {
                try {
                    $parser = $this->parserFactory->createParser($file);
                    $result = $parser->parse($file);

                    if ($result->isSuccess()) {
                        $parsedData = array_merge($parsedData, $result->getData());
                    }
                } catch (Exception $e) {
                    $this->logger->warning('Failed to parse extension configuration', [
                        'extension' => $extension->getKey(),
                        'file' => $file,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return empty($parsedData) ? null : new ExtensionConfiguration($extension->getKey(), $parsedData);
    }
}

// src/Application/Service/ConfigurationAnalysisService.php
class ConfigurationAnalysisService
{
    public function __construct(
        private readonly ConfigurationService $configService,
        private readonly ConfigurationCompatibilityChecker $compatibilityChecker
    ) {}

    public function analyzeForUpgrade(Installation $installation, Version $targetVersion): ConfigurationAnalysisResult
    {
        $config = $this->configService->parseInstallationConfiguration($installation);
        $issues = [];

        // Check main configuration compatibility
        $mainConfigIssues = $this->compatibilityChecker->checkMainConfiguration(
            $config->getMainConfiguration(),
            $installation->getVersion(),
            $targetVersion
        );
        $issues = array_merge($issues, $mainConfigIssues);

        // Check extension configuration compatibility
        foreach ($config->getExtensionConfigurations() as $extConfig) {
            $extIssues = $this->compatibilityChecker->checkExtensionConfiguration(
                $extConfig,
                $installation->getVersion(),
                $targetVersion
            );
            $issues = array_merge($issues, $extIssues);
        }

        return new ConfigurationAnalysisResult($config, $issues);
    }
}
```

#### Integration with Analysis Context
```php
// src/Domain/ValueObject/AnalysisContext.php (Enhanced)
class AnalysisContext
{
    private Version $currentVersion;
    private Version $targetVersion;
    private InstallationConfiguration $configuration;
    private array $analysisOptions;

    public function __construct(
        Version $currentVersion,
        Version $targetVersion,
        InstallationConfiguration $configuration = null
    ) {
        $this->currentVersion = $currentVersion;
        $this->targetVersion = $targetVersion;
        $this->configuration = $configuration ?? new InstallationConfiguration();
    }

    public function getConfiguration(): InstallationConfiguration;
    public function getDatabaseConfiguration(): DatabaseConfiguration;
    public function getCacheConfiguration(): CacheConfiguration;
    public function getExtensionConfiguration(string $extensionKey): ?ExtensionConfiguration;

    public function hasFeatureEnabled(string $feature): bool;
    public function getConfigurationValue(string $path, mixed $default = null): mixed;
    public function isExtensionLoaded(string $extensionKey): bool;
}
```

## Testing Strategy

### Unit Testing

#### Parser Testing
```php
// tests/Unit/Infrastructure/Parser/Php/LocalConfigurationParserTest.php
class LocalConfigurationParserTest extends TestCase
{
    private LocalConfigurationParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LocalConfigurationParser(
            $this->createMock(LoggerInterface::class),
            new PhpParser\Parser\Php7(new PhpParser\Lexer()),
            new ReturnArrayExtractor()
        );
    }

    public function testParsesValidLocalConfiguration(): void
    {
        // Arrange
        $configContent = '<?php
        return [
            "TYPO3_CONF_VARS" => [
                "DB" => [
                    "Connections" => [
                        "Default" => [
                            "driver" => "mysqli",
                            "host" => "localhost"
                        ]
                    ]
                ]
            ]
        ];';

        $tempFile = $this->createTempFile($configContent);

        // Act
        $result = $this->parser->parse($tempFile);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('DB', $result->getData());
        $this->assertEquals('mysqli', $result->getData()['DB']['Connections']['Default']['driver']);
    }

    public function testHandlesMalformedPhpConfiguration(): void
    {
        // Test error handling for syntax errors
        $configContent = '<?php return [invalid syntax;';
        $tempFile = $this->createTempFile($configContent);

        $result = $this->parser->parse($tempFile);

        $this->assertFalse($result->isSuccess());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testHandlesComplexPhpStructures(): void
    {
        // Test parsing of complex PHP configurations with constants, concatenation, etc.
    }
}

// tests/Unit/Infrastructure/Parser/Yaml/ServicesYamlParserTest.php
class ServicesYamlParserTest extends TestCase
{
    public function testParsesServicesYaml(): void
    {
        $yamlContent = '
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  MyVendor\MyExtension\:
    resource: "../Classes/*"

  MyVendor\MyExtension\Service\MyService:
    public: true
    arguments:
      - "@logger"
';

        $tempFile = $this->createTempFile($yamlContent);
        $result = $this->parser->parse($tempFile);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('services', $result->getData());
    }
}
```

#### Configuration Service Testing
```php
// tests/Unit/Application/Service/ConfigurationServiceTest.php
class ConfigurationServiceTest extends TestCase
{
    public function testParsesCompleteInstallationConfiguration(): void
    {
        // Arrange
        $installation = $this->createTestInstallation([
            'main_config' => $this->createLocalConfiguration(),
            'extensions' => [
                'news' => $this->createExtensionWithConfig(),
                'powermail' => $this->createExtensionWithConfig(),
            ]
        ]);

        // Act
        $config = $this->configurationService->parseInstallationConfiguration($installation);

        // Assert
        $this->assertInstanceOf(InstallationConfiguration::class, $config);
        $this->assertNotNull($config->getMainConfiguration());
        $this->assertCount(2, $config->getExtensionConfigurations());
    }
}
```

### Integration Testing

#### End-to-End Configuration Parsing
```php
// tests/Integration/ConfigurationParsingIntegrationTest.php
class ConfigurationParsingIntegrationTest extends TestCase
{
    public function testParseRealTypo3Installation(): void
    {
        // Test with real TYPO3 installation fixtures
        $installationPath = $this->getTestInstallationPath('typo3-v12-composer');

        $installation = $this->installationDiscovery->discover($installationPath);
        $config = $this->configurationService->parseInstallationConfiguration($installation);

        $this->assertInstanceOf(InstallationConfiguration::class, $config);
        $this->assertTrue($config->isValid());
    }

    public function testHandlesMultipleConfigurationFormats(): void
    {
        // Test installation with mixed configuration formats
    }
}
```

### Test Fixtures
```php
// tests/Fixtures/ConfigurationFixtureBuilder.php
class ConfigurationFixtureBuilder
{
    public function createLocalConfiguration(array $overrides = []): string
    {
        $config = array_merge([
            'TYPO3_CONF_VARS' => [
                'DB' => [
                    'Connections' => [
                        'Default' => [
                            'driver' => 'mysqli',
                            'host' => 'localhost',
                            'dbname' => 'typo3_test',
                        ]
                    ]
                ],
                'SYS' => [
                    'sitename' => 'Test Site',
                    'encryptionKey' => 'test-key',
                ]
            ]
        ], $overrides);

        return '<?php return ' . var_export($config, true) . ';';
    }

    public function createServicesYaml(array $services = []): string
    {
        $config = [
            'services' => array_merge([
                '_defaults' => [
                    'autowire' => true,
                    'autoconfigure' => true,
                ]
            ], $services)
        ];

        return Yaml::dump($config, 4);
    }
}
```

## Integration Points

### With Existing Components

#### Installation Discovery System
- Uses discovered Installation entities as input for configuration parsing
- Leverages extension metadata to locate configuration files
- Validates discovered installations through configuration parsing

#### Analysis Framework
- Provides parsed configuration data to all analyzers
- Populates AnalysisContext with configuration information
- Enables configuration-aware analysis strategies

#### Command System
- Enhanced commands can access parsed configuration data
- New configuration-specific commands for validation and inspection
- Interactive configuration analysis capabilities

### With Future Components

#### Report Generation
- Configuration analysis results included in upgrade reports
- Visual representation of configuration changes required
- Configuration migration recommendations

#### Migration Tools Integration
- Parsed configuration data guides automated migrations
- Configuration compatibility checking informs migration strategy
- Backup and rollback capabilities for configuration changes

## Performance Considerations

### Optimization Strategies

#### Lazy Loading
```php
class InstallationConfiguration
{
    private array $extensionConfigurations = [];
    private array $loadedExtensions = [];

    public function getExtensionConfiguration(string $extensionKey): ?ExtensionConfiguration
    {
        if (!isset($this->loadedExtensions[$extensionKey])) {
            $this->loadedExtensions[$extensionKey] = $this->loadExtensionConfiguration($extensionKey);
        }

        return $this->loadedExtensions[$extensionKey];
    }
}
```

#### Caching Strategy
```php
class CachingConfigurationService
{
    public function parseInstallationConfiguration(Installation $installation): InstallationConfiguration
    {
        $cacheKey = $this->generateCacheKey($installation);

        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        $config = $this->doParseConfiguration($installation);
        $this->cache->set($cacheKey, $config, $this->getCacheTtl());

        return $config;
    }
}
```

#### Memory Management
- Stream-based parsing for large configuration files
- Configurable memory limits with graceful degradation
- Garbage collection between parsing operations

### Security Considerations

#### Safe PHP Parsing
- Never use `eval()`, `require()`, or `include()` on configuration files
- Use AST parsing exclusively for PHP configuration files
- Validate and sanitize all extracted configuration values
- Implement resource limits to prevent DoS attacks

#### Input Validation
```php
class SecureConfigurationParser
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const MAX_NESTING_DEPTH = 50;

    protected function readFile(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new InvalidFileException('File does not exist or is not a regular file');
        }

        if (filesize($filePath) > self::MAX_FILE_SIZE) {
            throw new FileTooLargeException('Configuration file exceeds maximum size limit');
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new FileReadException('Failed to read configuration file');
        }

        return $content;
    }
}
```

## Success Criteria

### Functional Requirements
- [ ] Parse 95%+ of TYPO3 configuration files correctly
- [ ] Support all major configuration formats (PHP, YAML, TypoScript, XML)
- [ ] Handle configuration format variations across TYPO3 versions
- [ ] Provide meaningful error messages for parsing failures
- [ ] Enable safe parsing without code execution

### Performance Requirements
- [ ] Parse typical installation configuration in <2 seconds
- [ ] Memory usage under 128MB for standard installations
- [ ] Support configurations up to 10MB per file
- [ ] Efficient handling of installations with 100+ extensions

### Security Requirements
- [ ] Zero code execution during parsing process
- [ ] Proper handling of malicious configuration files
- [ ] Resource limits prevent DoS attacks
- [ ] Input validation for all parsed data

### Quality Requirements
- [ ] 95%+ test coverage for all parser components
- [ ] Support for real-world TYPO3 configuration patterns
- [ ] Comprehensive error handling and recovery
- [ ] Clear separation of parsing and business logic

## Conclusion

The Configuration Parsing Framework provides a comprehensive, secure, and performant solution for analyzing TYPO3 configurations without requiring TYPO3 to be loaded. By implementing safe parsing strategies using Abstract Syntax Trees for PHP files and established libraries for other formats, the framework enables deep configuration analysis while maintaining security and reliability.

The modular architecture allows for easy extension to support new configuration formats and TYPO3 versions, while the comprehensive testing strategy ensures compatibility across different scenarios. Integration with the AnalysisContext provides seamless access to parsed configuration data for all components in the TYPO3 Upgrade Analyzer system.

This framework serves as a critical foundation that enables sophisticated upgrade analysis capabilities, making it possible to provide accurate recommendations for TYPO3 upgrade projects based on actual configuration analysis rather than assumptions.
