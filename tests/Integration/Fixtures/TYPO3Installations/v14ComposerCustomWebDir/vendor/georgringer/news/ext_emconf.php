<?php

declare(strict_types=1);

$EM_CONF['news'] = [
    'title' => 'Versatile News Extension',
    'description' => 'Georg Ringer news extension for testing',
    'category' => 'plugin',
    'version' => '12.0.0',
    'state' => 'stable',
    'author' => 'Georg Ringer',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.0.99',
            'php' => '8.3.0-8.3.99',
        ],
    ],
];
