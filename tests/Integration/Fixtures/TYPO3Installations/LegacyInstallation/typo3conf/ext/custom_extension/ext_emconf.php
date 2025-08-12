<?php

declare(strict_types=1);

$EM_CONF['custom_extension'] = [
    'title' => 'Custom Extension',
    'description' => 'A custom extension developed in-house',
    'category' => 'fe',
    'version' => '3.2.1',
    'state' => 'stable',
    'clearcacheonload' => true,
    'author' => 'Custom Developer',
    'author_email' => 'dev@custom.com',
    'author_company' => 'Custom Company',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-11.5.99',
            'php' => '7.4.0-8.2.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
