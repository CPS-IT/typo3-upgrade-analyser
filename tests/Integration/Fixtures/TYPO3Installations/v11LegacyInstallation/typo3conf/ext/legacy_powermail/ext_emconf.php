<?php

declare(strict_types=1);

$EM_CONF['legacy_powermail'] = [
    'title' => 'Legacy Powermail',
    'description' => 'Legacy version of powermail extension',
    'category' => 'plugin',
    'version' => '7.5.0',
    'state' => 'stable',
    'clearcacheonload' => true,
    'author' => 'Legacy Author',
    'author_email' => 'legacy@example.com',
    'author_company' => 'Legacy Company',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-11.5.99',
            'php' => '7.4.0-8.2.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
