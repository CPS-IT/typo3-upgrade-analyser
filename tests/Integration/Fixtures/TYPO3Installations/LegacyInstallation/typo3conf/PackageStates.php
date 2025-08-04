<?php

declare(strict_types=1);

// PackageStates.php for legacy TYPO3 installation
return [
    'packages' => [
        'core' => [
            'packagePath' => 'typo3/sysext/core/',
            'classesPath' => 'Classes/',
            'suggestions' => [],
            'state' => 'active',
        ],
        'extbase' => [
            'packagePath' => 'typo3/sysext/extbase/',
            'classesPath' => 'Classes/',
            'suggestions' => [],
            'state' => 'active',
        ],
        'frontend' => [
            'packagePath' => 'typo3/sysext/frontend/',
            'classesPath' => 'Classes/',
            'suggestions' => [],
            'state' => 'active',
        ],
        'backend' => [
            'packagePath' => 'typo3/sysext/backend/',
            'classesPath' => 'Classes/',
            'suggestions' => [],
            'state' => 'active',
        ],
        'legacy_news' => [
            'packagePath' => 'typo3conf/ext/legacy_news/',
            'classesPath' => 'Classes/',
            'suggestions' => [],
            'state' => 'active',
        ],
        'legacy_powermail' => [
            'packagePath' => 'typo3conf/ext/legacy_powermail/',
            'classesPath' => 'Classes/',
            'suggestions' => [],
            'state' => 'active',
        ],
        'custom_extension' => [
            'packagePath' => 'typo3conf/ext/custom_extension/',
            'classesPath' => 'Classes/',
            'suggestions' => [],
            'state' => 'active',
        ],
    ],
    'version' => 5,
];
