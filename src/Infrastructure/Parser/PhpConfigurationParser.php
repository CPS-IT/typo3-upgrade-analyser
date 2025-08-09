<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Parser;

use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\Exception\PhpParseException;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;

/**
 * Safe PHP configuration parser using AST.
 *
 * Parses PHP configuration files like LocalConfiguration.php and
 * AdditionalConfiguration.php without executing code, using abstract
 * syntax tree parsing to extract configuration values safely.
 */
final class PhpConfigurationParser extends AbstractConfigurationParser
{
    private Parser $parser;
    private NodeTraverser $traverser;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);

        // Initialize PHP parser
        $parserFactory = new ParserFactory();
        $this->parser = $parserFactory->createForNewestSupportedVersion();

        // Initialize node traverser
        $this->traverser = new NodeTraverser();
    }

    public function getFormat(): string
    {
        return 'php';
    }

    public function getName(): string
    {
        return 'PHP Configuration Parser';
    }

    public function getSupportedExtensions(): array
    {
        return ['php'];
    }

    public function getPriority(): int
    {
        return 80; // High priority for PHP files
    }

    public function getRequiredDependencies(): array
    {
        return ['nikic/php-parser'];
    }

    public function isReady(): bool
    {
        if (!class_exists(ParserFactory::class)) {
            throw new \RuntimeException('PHP-Parser library is required but not available');
        }

        return parent::isReady();
    }

    protected function supportsSpecific(string $filePath): bool
    {
        // Check if it's a known TYPO3 configuration file
        $fileName = basename($filePath);
        $supportedFiles = [
            'LocalConfiguration.php',
            'AdditionalConfiguration.php',
            'PackageStates.php',
            'ext_localconf.php',
            'ext_tables.php',
        ];

        if (\in_array($fileName, $supportedFiles, true)) {
            return true;
        }

        // Check if file contains PHP configuration patterns
        return $this->looksLikeConfigurationFile($filePath);
    }

    protected function doParse(string $content, string $sourcePath): array
    {
        try {
            // Parse PHP code into AST
            $ast = $this->parser->parse($content);

            if (null === $ast) {
                throw PhpParseException::syntaxError('Failed to parse PHP content', $sourcePath, 'php');
            }

            // Extract configuration data from AST
            $extractor = new ConfigurationExtractor($sourcePath);
            $this->traverser->addVisitor($extractor);
            $this->traverser->traverse($ast);
            $this->traverser->removeVisitor($extractor);

            $configData = $extractor->getConfiguration();

            $this->logger->debug('PHP configuration parsed successfully', [
                'source' => $sourcePath,
                'keys_found' => \count($configData),
                'extraction_method' => $extractor->getExtractionMethod(),
            ]);

            return $configData;
        } catch (Error $e) {
            throw PhpParseException::fromAstErrors([$e->getMessage()], $sourcePath, $e->getStartLine(), $e->hasColumnInfo() ? $e->getStartColumn($content) : null);
        }
    }

    protected function validateParsedData(array $data, string $sourcePath): array
    {
        $errors = [];
        $warnings = [];

        // Check for common TYPO3 configuration structure
        $fileName = basename($sourcePath);

        if ('LocalConfiguration.php' === $fileName
            || (str_starts_with($fileName, 'LocalConfiguration') && str_ends_with($fileName, '.php'))) {
            $this->validateLocalConfiguration($data, $errors, $warnings);
        } elseif ('PackageStates.php' === $fileName
                 || (str_starts_with($fileName, 'PackageStates') && str_ends_with($fileName, '.php'))) {
            $this->validatePackageStates($data, $errors, $warnings);
        } elseif (str_ends_with($fileName, 'ext_localconf.php')
                 || (str_starts_with($fileName, 'ext_localconf') && str_ends_with($fileName, '.php'))) {
            $this->validateExtensionConfiguration($data, $errors, $warnings, 'localconf');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check if file looks like a TYPO3 configuration file.
     *
     * @param string $filePath File path to check
     *
     * @return bool True if file looks like configuration
     */
    private function looksLikeConfigurationFile(string $filePath): bool
    {
        if (!$this->isFileAccessible($filePath)) {
            return false;
        }

        // Read first few lines to check for configuration patterns
        $handle = fopen($filePath, 'r');
        if (false === $handle) {
            return false;
        }

        $lines = [];
        for ($i = 0; $i < 20 && !feof($handle); ++$i) {
            $line = fgets($handle);
            if (false !== $line) {
                $lines[] = trim($line);
            }
        }
        fclose($handle);

        $content = implode(' ', $lines);

        // Look for TYPO3 configuration patterns
        $patterns = [
            '/\$GLOBALS\s*\[\s*[\'"]TYPO3_CONF_VARS[\'"]\s*\]/',
            '/\$TYPO3_CONF_VARS/',
            '/\$packageStates/',
            '/return\s*\[/',
            '/\$GLOBALS\s*\[\s*[\'"]T3_VAR[\'"]\s*\]/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate LocalConfiguration.php structure.
     *
     * @param array<string, mixed> $data     Configuration data
     * @param array<string>        $errors   Error array to populate
     * @param array<string>        $warnings Warning array to populate
     */
    private function validateLocalConfiguration(array $data, array &$errors, array &$warnings): void
    {
        $requiredSections = ['DB', 'SYS', 'MAIL'];
        $recommendedSections = ['BE', 'FE', 'GFX', 'EXT'];

        foreach ($requiredSections as $section) {
            if (!isset($data[$section])) {
                $errors[] = "Missing required configuration section: {$section}";
            }
        }

        foreach ($recommendedSections as $section) {
            if (!isset($data[$section])) {
                $warnings[] = "Missing recommended configuration section: {$section}";
            }
        }

        // Validate database configuration
        if (isset($data['DB']['Connections']['Default'])) {
            $dbConfig = $data['DB']['Connections']['Default'];
            $requiredDbKeys = ['driver', 'host', 'dbname'];

            foreach ($requiredDbKeys as $key) {
                if (!isset($dbConfig[$key])) {
                    $errors[] = "Missing required database configuration: DB.Connections.Default.{$key}";
                }
            }
        } else {
            $errors[] = 'Missing database configuration: DB.Connections.Default';
        }
    }

    /**
     * Validate PackageStates.php structure.
     *
     * @param array<string, mixed> $data     Configuration data
     * @param array<string>        $errors   Error array to populate
     * @param array<string>        $warnings Warning array to populate
     */
    private function validatePackageStates(array $data, array &$errors, array &$warnings): void
    {
        if (!isset($data['packages'])) {
            $errors[] = 'Missing packages configuration in PackageStates.php';

            return;
        }

        $packages = $data['packages'];
        if (!\is_array($packages)) {
            $errors[] = 'Packages configuration must be an array';

            return;
        }

        // Check for required system extensions
        $requiredExtensions = ['core', 'backend', 'frontend', 'extbase', 'fluid'];

        foreach ($requiredExtensions as $extension) {
            if (!isset($packages[$extension])) {
                $errors[] = "Missing required system extension: {$extension}";
            } elseif (!isset($packages[$extension]['state']) || 'active' !== $packages[$extension]['state']) {
                $errors[] = "Required system extension not active: {$extension}";
            }
        }

        // Check package structure
        foreach ($packages as $packageKey => $packageConfig) {
            if (!\is_array($packageConfig)) {
                $warnings[] = "Invalid package configuration for: {$packageKey}";
                continue;
            }

            if (!isset($packageConfig['packagePath'])) {
                $warnings[] = "Missing package path for: {$packageKey}";
            }
        }
    }

    /**
     * Validate extension configuration files.
     *
     * @param array<string, mixed> $data     Configuration data
     * @param array<string>        $errors   Error array to populate
     * @param array<string>        $warnings Warning array to populate
     * @param string               $type     Configuration type (localconf, tables)
     */
    private function validateExtensionConfiguration(array $data, array &$errors, array &$warnings, string $type): void
    {
        // Extension configuration files often don't return data structures
        // but contain procedural code, so validation is limited

        if (empty($data)) {
            $warnings[] = "Extension {$type} file contains no extractable configuration data";
        }

        // Check for common problematic patterns if we have any data
        if (!empty($data)) {
            foreach ($data as $key => $value) {
                if (\is_string($key) && str_starts_with($key, '$GLOBALS')) {
                    // This is normal for extension configuration
                    continue;
                }

                if ([] === $value) {
                    $warnings[] = "Empty configuration array found: {$key}";
                }
            }
        }
    }
}

/**
 * AST visitor to extract configuration data from PHP files.
 */
class ConfigurationExtractor extends NodeVisitorAbstract
{
    private array $configuration = [];
    private string $sourcePath;
    private string $extractionMethod = 'ast_traversal';

    public function __construct(string $sourcePath)
    {
        $this->sourcePath = $sourcePath;
    }

    public function enterNode(Node $node): void
    {
        // Extract from return statements (common in TYPO3 config files)
        if ($node instanceof Node\Stmt\Return_ && $node->expr instanceof Node\Expr\Array_) {
            $this->extractFromArrayNode($node->expr, $this->configuration);
            $this->extractionMethod = 'return_statement';

            return;
        }

        // Extract from global variable assignments
        if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Assign) {
            $this->extractFromAssignment($node->expr);
        }

        // Extract from array assignments to $GLOBALS
        if ($node instanceof Node\Expr\ArrayDimFetch) {
            $this->extractFromGlobalsAccess($node);
        }
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getExtractionMethod(): string
    {
        return $this->extractionMethod;
    }

    /**
     * Extract configuration from array node.
     *
     * @param Node\Expr\Array_     $arrayNode Array node
     * @param array<string, mixed> $target    Target array to populate
     */
    private function extractFromArrayNode(Node\Expr\Array_ $arrayNode, array &$target): void
    {
        foreach ($arrayNode->items as $item) {
            if (null === $item || null === $item->key) {
                continue;
            }

            $key = $this->extractValue($item->key);
            $value = $this->extractValue($item->value);

            if (null !== $key) {
                $target[$key] = $value;
            }
        }
    }

    /**
     * Extract configuration from assignment expression.
     *
     * @param Node\Expr\Assign $assignNode Assignment node
     */
    private function extractFromAssignment(Node\Expr\Assign $assignNode): void
    {
        // Handle $TYPO3_CONF_VARS assignments
        if ($assignNode->var instanceof Node\Expr\Variable
            && 'TYPO3_CONF_VARS' === $assignNode->var->name) {
            $value = $this->extractValue($assignNode->expr);
            if (\is_array($value)) {
                $this->configuration = array_merge($this->configuration, $value);
                $this->extractionMethod = 'variable_assignment';
            }
        }
    }

    /**
     * Extract configuration from GLOBALS access.
     *
     * @param Node\Expr\ArrayDimFetch $node Array access node
     */
    private function extractFromGlobalsAccess(Node\Expr\ArrayDimFetch $node): void
    {
        // Handle $GLOBALS['TYPO3_CONF_VARS'] patterns
        if ($node->var instanceof Node\Expr\Variable
            && 'GLOBALS' === $node->var->name
            && $node->dim instanceof Node\Scalar\String_
            && 'TYPO3_CONF_VARS' === $node->dim->value) {
            $this->extractionMethod = 'globals_access';
        }
    }

    /**
     * Extract value from AST node.
     *
     * @param Node|null $node AST node
     *
     * @return mixed Extracted value
     */
    private function extractValue(?Node $node): string|int|float|bool|array|null
    {
        if (null === $node) {
            return null;
        }

        // String literals
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        // Integer literals
        if ($node instanceof Node\Scalar\LNumber) {
            return $node->value;
        }

        // Float literals
        if ($node instanceof Node\Scalar\DNumber) {
            return $node->value;
        }

        // Boolean literals
        if ($node instanceof Node\Expr\ConstFetch) {
            $name = strtolower($node->name->toString());
            if ('true' === $name) {
                return true;
            }
            if ('false' === $name) {
                return false;
            }
            if ('null' === $name) {
                return null;
            }
        }

        // Array literals
        if ($node instanceof Node\Expr\Array_) {
            $result = [];
            $this->extractFromArrayNode($node, $result);

            return $result;
        }

        // For other node types, return a placeholder
        return \sprintf('[%s]', \get_class($node));
    }
}
