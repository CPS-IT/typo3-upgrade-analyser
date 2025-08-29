<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'CPS Short Number',
    'description' => 'Builds links to pages and extension records with a tiny url',
    'category' => 'plugin',
    'author' => 'CPS IT',
    'author_email' => 'info@cps-it.de',
    'state' => 'stable',
    'version' => '3.2.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
