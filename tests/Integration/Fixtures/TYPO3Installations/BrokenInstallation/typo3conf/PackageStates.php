<?php
# This is intentionally broken PHP for testing error handling
return [
    'packages' => [
        'core' => [
            'packagePath' => 'typo3/sysext/core/',
            'classesPath' => 'Classes/',
            'suggestions' => [],
            'state' => 'active',
        ],
        // Missing closing bracket to create syntax error
        'broken_extension' => [
            'packagePath' => 'typo3conf/ext/broken_extension/',
            'classesPath' => 'Classes/',
            // Missing quotes and bracket to create broken structure
            state => 'active
        ,
    ],
    'version' => 5,
;
