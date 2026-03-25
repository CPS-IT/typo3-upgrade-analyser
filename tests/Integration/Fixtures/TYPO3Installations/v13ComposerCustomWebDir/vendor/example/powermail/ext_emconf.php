<?php

declare(strict_types=1);

$EM_CONF['powermail'] = [
    'title' => 'Powermail Extension',
    'description' => 'Example powermail extension for testing',
    'category' => 'plugin',
    'version' => '11.0.0',
    'state' => 'stable',
    'author' => 'Test Author',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'php' => '8.2.0-8.3.99',
        ],
    ],
];
