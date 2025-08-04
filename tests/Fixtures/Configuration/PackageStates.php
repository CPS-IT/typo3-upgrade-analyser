<?php

declare(strict_types=1);

/**
 * Example TYPO3 PackageStates.php file for testing.
 */
return [
    'packages' => [
        'core' => [
            'composerName' => 'typo3/cms-core',
            'state' => 'active',
            'packagePath' => 'typo3/sysext/core/',
            'suggestions' => [],
        ],
        'backend' => [
            'composerName' => 'typo3/cms-backend',
            'state' => 'active',
            'packagePath' => 'typo3/sysext/backend/',
            'suggestions' => [],
        ],
        'frontend' => [
            'composerName' => 'typo3/cms-frontend',
            'state' => 'active',
            'packagePath' => 'typo3/sysext/frontend/',
            'suggestions' => [],
        ],
        'extbase' => [
            'composerName' => 'typo3/cms-extbase',
            'state' => 'active',
            'packagePath' => 'typo3/sysext/extbase/',
            'suggestions' => [],
        ],
        'fluid' => [
            'composerName' => 'typo3/cms-fluid',
            'state' => 'active',
            'packagePath' => 'typo3/sysext/fluid/',
            'suggestions' => [],
        ],
        'install' => [
            'composerName' => 'typo3/cms-install',
            'state' => 'active',
            'packagePath' => 'typo3/sysext/install/',
            'suggestions' => [],
        ],
        'news' => [
            'composerName' => 'georgringer/news',
            'state' => 'active',
            'packagePath' => 'typo3conf/ext/news/',
            'suggestions' => [],
        ],
        'powermail' => [
            'composerName' => 'in2code/powermail',
            'state' => 'active',
            'packagePath' => 'typo3conf/ext/powermail/',
            'suggestions' => [],
        ],
        'custom_extension' => [
            'composerName' => 'vendor/custom-extension',
            'state' => 'inactive',
            'packagePath' => 'typo3conf/ext/custom_extension/',
            'suggestions' => [],
        ],
    ],
    'version' => 5,
];
