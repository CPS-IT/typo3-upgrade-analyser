<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use Psr\Log\LoggerInterface;

/**
 * Early validation for path resolution requests.
 * Provides comprehensive validation with detailed error reporting.
 */
final class PathResolutionValidator
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function validate(PathResolutionRequest $request): ValidationResult
    {
        $errors = [];
        $warnings = [];

        $this->logger->debug('Validating path resolution request', [
            'path_type' => $request->pathType->value,
            'installation_type' => $request->installationType->value,
            'installation_path' => $request->installationPath,
        ]);

        // Validate installation path
        $pathValidation = $this->validateInstallationPath($request->installationPath);
        $errors = array_merge($errors, $pathValidation['errors']);
        $warnings = array_merge($warnings, $pathValidation['warnings']);

        // Validate path type specific requirements
        $typeValidation = $this->validatePathTypeRequirements($request);
        $errors = array_merge($errors, $typeValidation['errors']);
        $warnings = array_merge($warnings, $typeValidation['warnings']);

        // Validate configuration consistency
        $configValidation = $this->validateConfiguration($request);
        $errors = array_merge($errors, $configValidation['errors']);
        $warnings = array_merge($warnings, $configValidation['warnings']);

        // Apply custom validation rules
        if (!empty($request->validationRules)) {
            $customValidation = $this->applyCustomValidationRules($request);
            $errors = array_merge($errors, $customValidation['errors']);
            $warnings = array_merge($warnings, $customValidation['warnings']);
        }

        $isValid = empty($errors);

        $this->logger->debug('Path resolution request validation completed', [
            'is_valid' => $isValid,
            'errors_count' => \count($errors),
            'warnings_count' => \count($warnings),
        ]);

        return new ValidationResult($isValid, $errors, $warnings);
    }

    private function validateInstallationPath(string $installationPath): array
    {
        $errors = [];
        $warnings = [];

        // Check if path exists
        if (!file_exists($installationPath)) {
            $errors[] = "Installation path does not exist: {$installationPath}";

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        // Check if path is readable
        if (!is_readable($installationPath)) {
            $errors[] = "Installation path is not readable: {$installationPath}";
        }

        // Check if path is a directory
        if (!is_dir($installationPath)) {
            $warnings[] = "Installation path is not a directory: {$installationPath}";
        }

        // Check for common TYPO3 indicators
        if (!$this->hasTypo3Indicators($installationPath)) {
            $warnings[] = "Path does not appear to be a TYPO3 installation: {$installationPath}";
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function validatePathTypeRequirements(PathResolutionRequest $request): array
    {
        $errors = [];
        $warnings = [];

        $requiredRules = $request->pathType->getRequiredValidationRules();

        foreach ($requiredRules as $rule) {
            switch ($rule) {
                case 'extension_identifier_required':
                    if (!$request->extensionIdentifier) {
                        $errors[] = "Extension identifier is required for path type: {$request->pathType->value}";
                    }
                    break;

                case 'directory_exists':
                    if (!is_dir($request->installationPath)) {
                        $errors[] = "Installation path must be a directory for path type: {$request->pathType->value}";
                    }
                    break;

                case 'file_exists':
                    if (!is_file($request->installationPath)) {
                        $errors[] = "Installation path must be a file for path type: {$request->pathType->value}";
                    }
                    break;

                case 'readable':
                    if (!is_readable($request->installationPath)) {
                        $errors[] = "Installation path must be readable for path type: {$request->pathType->value}";
                    }
                    break;

                case 'exists':
                    if (!file_exists($request->installationPath)) {
                        $errors[] = "Installation path must exist for path type: {$request->pathType->value}";
                    }
                    break;
            }
        }

        // Check path type and installation type compatibility
        if (!$request->pathType->isCompatibleWith($request->installationType)) {
            $errors[] = "Path type '{$request->pathType->value}' is not compatible with installation type '{$request->installationType->value}'";
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function validateConfiguration(PathResolutionRequest $request): array
    {
        $errors = [];
        $warnings = [];

        // Validate max depth
        if ($request->pathConfiguration->maxDepth < 1) {
            $errors[] = 'Max depth must be at least 1';
        } elseif ($request->pathConfiguration->maxDepth > 50) {
            $warnings[] = "Max depth is very high ({$request->pathConfiguration->maxDepth}), this may impact performance";
        }

        // Validate search directories
        foreach ($request->pathConfiguration->searchDirectories as $dir) {
            if (empty($dir)) {
                $warnings[] = 'Empty search directory found in configuration';
            }
        }

        // Validate exclude patterns
        foreach ($request->pathConfiguration->excludePatterns as $pattern) {
            if (empty($pattern)) {
                $warnings[] = 'Empty exclude pattern found in configuration';
            }
        }

        // Check for conflicting options
        if (!$request->pathConfiguration->validateExists && $request->pathConfiguration->followSymlinks) {
            $warnings[] = 'Following symlinks without validating existence may lead to unexpected results';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function applyCustomValidationRules(PathResolutionRequest $request): array
    {
        $errors = [];
        $warnings = [];

        foreach ($request->validationRules as $rule => $parameters) {
            $result = $this->executeCustomRule($rule, $parameters, $request);
            if ($result) {
                $errors = array_merge($errors, $result['errors'] ?? []);
                $warnings = array_merge($warnings, $result['warnings'] ?? []);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function executeCustomRule(string $rule, array $parameters, PathResolutionRequest $request): ?array
    {
        // Custom validation rule implementations
        return match ($rule) {
            'min_path_length' => $this->validateMinPathLength($request->installationPath, $parameters['length'] ?? 5),
            'required_subdirs' => $this->validateRequiredSubdirectories($request->installationPath, $parameters['dirs'] ?? []),
            'forbidden_paths' => $this->validateForbiddenPaths($request->installationPath, $parameters['paths'] ?? []),
            default => null,
        };
    }

    private function validateMinPathLength(string $path, int $minLength): array
    {
        $errors = [];

        if (\strlen($path) < $minLength) {
            $errors[] = "Installation path is too short (minimum {$minLength} characters required)";
        }

        return ['errors' => $errors, 'warnings' => []];
    }

    private function validateRequiredSubdirectories(string $installationPath, array $requiredDirs): array
    {
        $errors = [];

        foreach ($requiredDirs as $dir) {
            if (!is_dir($installationPath . '/' . $dir)) {
                $errors[] = "Required subdirectory not found: {$dir}";
            }
        }

        return ['errors' => $errors, 'warnings' => []];
    }

    private function validateForbiddenPaths(string $installationPath, array $forbiddenPaths): array
    {
        $errors = [];

        foreach ($forbiddenPaths as $forbiddenPath) {
            if (str_contains($installationPath, $forbiddenPath)) {
                $errors[] = "Installation path contains forbidden path segment: {$forbiddenPath}";
            }
        }

        return ['errors' => $errors, 'warnings' => []];
    }

    private function hasTypo3Indicators(string $path): bool
    {
        $indicators = [
            'typo3conf',
            'typo3',
            'fileadmin',
            'public/typo3',
            'web/typo3',
            'composer.json', // For Composer installations
        ];

        foreach ($indicators as $indicator) {
            if (file_exists($path . '/' . $indicator)) {
                return true;
            }
        }

        return false;
    }
}
