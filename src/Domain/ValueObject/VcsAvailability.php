<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Domain\ValueObject;

/**
 * Represents the availability status of an extension in a VCS-sourced repository.
 *
 * TER and Packagist sources use plain booleans (definitively available or not).
 * VCS resolution has a third state — Unknown — for cases where connectivity or
 * authentication prevented a definitive answer. This enum formalises that distinction.
 *
 * String-backed so Twig templates can compare against string values and JSON
 * serialisation works without additional conversion.
 */
enum VcsAvailability: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}
