<?php

declare(strict_types=1);

namespace MyVendor\TestExtension\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Service class with more TYPO3 12 deprecated patterns
 */
class TestService
{
    /**
     * Method with deprecated database patterns
     */
    public function getDatabaseData(): array
    {
        // DEPRECATED: Direct connection access
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        
        // DEPRECATED: Query builder execute() method
        $queryBuilder = $connection->createQueryBuilder();
        $result = $queryBuilder
            ->select('uid', 'header', 'bodytext')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(1)))
            ->execute(); // Should be executeQuery()
            
        return $result->fetchAll();
    }
    
    /**
     * Method with deprecated utility patterns
     */
    public function processData(string $input): string
    {
        // DEPRECATED: Various GeneralUtility patterns that should be injected
        $configuration = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class);
        $context = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Context\Context::class);
        
        // DEPRECATED: Direct TSFE access
        $typoScript = $GLOBALS['TSFE']->tmpl->setup;
        
        // DEPRECATED: Old file handling
        $tempFile = GeneralUtility::tempnam('test_');
        
        // DEPRECATED: Old utility methods
        $cleaned = GeneralUtility::removeXSS($input);
        
        return $cleaned;
    }
    
    /**
     * Method with deprecated frontend patterns
     */
    public function getFrontendData(): array
    {
        // DEPRECATED: Direct TSFE access patterns
        /** @var TypoScriptFrontendController $tsfe */
        $tsfe = $GLOBALS['TSFE'];
        
        return [
            'id' => $tsfe->id,
            'type' => $tsfe->type,
            'rootLine' => $tsfe->rootLine,
            'page' => $tsfe->page
        ];
    }
}