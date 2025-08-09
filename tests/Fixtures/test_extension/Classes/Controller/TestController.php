<?php

declare(strict_types=1);

namespace MyVendor\TestExtension\Controller;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Test controller with TYPO3 12 deprecated code patterns that should be detected by Rector
 */
class TestController extends ActionController
{
    /**
     * This method contains several deprecated patterns for TYPO3 12->13 upgrade
     */
    public function listAction(): string
    {
        // DEPRECATED: GeneralUtility::makeInstance() should be replaced with dependency injection
        $context = GeneralUtility::makeInstance(Context::class);
        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);

        // DEPRECATED: Direct database access patterns
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        // DEPRECATED: Old query builder patterns
        $result = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0))
            )
            ->execute(); // execute() is deprecated, should use executeQuery()

        // DEPRECATED: Old result handling
        while ($row = $result->fetch()) {
            // Process row
        }

        // DEPRECATED: Direct configuration access
        $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['test_extension'];

        // DEPRECATED: Old array access patterns
        $pageId = (int)$GLOBALS['TSFE']->id;

        return '';
    }

    /**
     * Another method with deprecated patterns
     */
    public function detailAction(): void
    {
        // DEPRECATED: Old template rendering
        $this->view->assign('data', [
            'title' => 'Test',
            'content' => 'Content'
        ]);

        // DEPRECATED: Direct file operations
        $file = GeneralUtility::getFileAbsFileName('EXT:test_extension/Resources/Private/Templates/Test.html');

        // DEPRECATED: Old utility calls
        $hash = GeneralUtility::shortMD5($file);
    }
}
