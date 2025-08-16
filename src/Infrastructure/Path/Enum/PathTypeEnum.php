<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum;

/**
 * Enumeration of supported path types with validation capabilities.
 */
enum PathTypeEnum: string
{
    case EXTENSION = 'extension';
    case TYPO3CONF_DIR = 'typo3conf_dir';
    case VENDOR_DIR = 'vendor_dir';
    case WEB_DIR = 'web_dir';
    case LOCAL_CONFIGURATION = 'local_configuration';
    case PACKAGE_STATES = 'package_states';
    case COMPOSER_INSTALLED = 'composer_installed';
    case TEMPLATE_DIR = 'template_dir';
    case CACHE_DIR = 'cache_dir';
    case LOG_DIR = 'log_dir';
    case TYPOSCRIPT_DIR = 'typoscript_dir';
    case SYSTEM_EXTENSION = 'system_extension';

    /**
     * Get compatible installation types for this path type.
     */
    public function getCompatibleInstallationTypes(): array
    {
        return match ($this) {
            self::EXTENSION, self::TYPO3CONF_DIR, self::WEB_DIR => InstallationTypeEnum::cases(),
            self::VENDOR_DIR, self::COMPOSER_INSTALLED => [
                InstallationTypeEnum::COMPOSER_STANDARD,
                InstallationTypeEnum::COMPOSER_CUSTOM,
                InstallationTypeEnum::CUSTOM,
            ],
            self::SYSTEM_EXTENSION => [
                InstallationTypeEnum::COMPOSER_STANDARD,
                InstallationTypeEnum::LEGACY_SOURCE,
                InstallationTypeEnum::CUSTOM,
            ],
            default => InstallationTypeEnum::cases(),
        };
    }

    /**
     * Check if this path type is compatible with an installation type.
     */
    public function isCompatibleWith(InstallationTypeEnum $installationType): bool
    {
        return match ($this) {
            self::EXTENSION, self::TYPO3CONF_DIR, self::WEB_DIR => true,
            self::VENDOR_DIR, self::COMPOSER_INSTALLED => InstallationTypeEnum::LEGACY_SOURCE !== $installationType,
            self::SYSTEM_EXTENSION => InstallationTypeEnum::DOCKER_CONTAINER !== $installationType,
            default => true,
        };
    }

    /**
     * Get required validation rules for this path type.
     */
    public function getRequiredValidationRules(): array
    {
        return match ($this) {
            self::EXTENSION => ['extension_identifier_required', 'directory_exists'],
            self::LOCAL_CONFIGURATION, self::PACKAGE_STATES => ['file_exists', 'readable'],
            self::VENDOR_DIR, self::WEB_DIR => ['directory_exists', 'readable'],
            default => ['exists'],
        };
    }

    /**
     * Get default fallback strategies for this path type.
     */
    public function getDefaultFallbackStrategies(): array
    {
        return match ($this) {
            self::EXTENSION => [
                'ComposerExtensionPathStrategy',
                'LegacyExtensionPathStrategy',
                'CustomExtensionPathStrategy',
            ],
            self::VENDOR_DIR => [
                'ComposerVendorDirStrategy',
                'LegacyVendorDirStrategy',
            ],
            default => ['GenericPathResolutionStrategy'],
        };
    }
}
