<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Page rendering component for TYPO3 Handlebars',
    'description' => 'Bravo Handlebars Content Extension for TYPO3',
    'category' => 'plugin',
    'author' => 'CPS IT',
    'author_email' => 'info@cps-it.de',
    'state' => 'stable',
    'version' => '1.3.1',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
