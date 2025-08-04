<?php

declare(strict_types=1);

$EM_CONF['local_extension'] = [
    'title' => 'Local Extension',
    'description' => 'A local extension for testing purposes',
    'category' => 'fe',
    'version' => '1.0.0',
    'state' => 'stable',
    'clearcacheonload' => true,
    'author' => 'Test Author',
    'author_email' => 'test@example.com',
    'author_company' => 'Test Company',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
