<?php

declare(strict_types=1);

$EM_CONF['news'] = [
    'title' => 'News',
    'description' => 'Local news extension in typo3conf/ext',
    'category' => 'plugin',
    'version' => '9.3.1',
    'state' => 'stable',
    'author' => 'Local Developer',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-11.5.99',
            'php' => '8.0.0-8.2.99',
        ],
    ],
];
