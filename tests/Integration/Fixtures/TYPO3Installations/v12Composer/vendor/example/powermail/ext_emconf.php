<?php

declare(strict_types=1);

$EM_CONF['powermail'] = [
    'title' => 'Powermail Extension',
    'description' => 'Example powermail extension for testing',
    'category' => 'plugin',
    'version' => '10.0.0',
    'state' => 'stable',
    'author' => 'Test Author',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.3.99',
        ],
    ],
];
