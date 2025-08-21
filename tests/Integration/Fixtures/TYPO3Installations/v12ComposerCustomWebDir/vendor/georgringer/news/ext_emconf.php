<?php

declare(strict_types=1);

$EM_CONF['news'] = [
    'title' => 'Versatile News Extension',
    'description' => 'Georg Ringer news extension for testing',
    'category' => 'plugin',
    'version' => '11.0.5',
    'state' => 'stable',
    'author' => 'Georg Ringer',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.3.99',
        ],
    ],
];