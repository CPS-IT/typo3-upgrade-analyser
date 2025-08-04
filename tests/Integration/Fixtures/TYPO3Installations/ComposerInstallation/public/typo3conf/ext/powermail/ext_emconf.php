<?php

declare(strict_types=1);

$EM_CONF['powermail'] = [
    'title' => 'Powermail',
    'description' => 'Powermail is a well-known, flexible and powerful form extension for TYPO3',
    'category' => 'plugin',
    'version' => '10.0.1',
    'state' => 'stable',
    'clearcacheonload' => true,
    'author' => 'Alex Kellner',
    'author_email' => 'alexander.kellner@in2code.de',
    'author_company' => 'in2code.de',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
