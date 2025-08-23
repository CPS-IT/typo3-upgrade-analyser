<?php

declare(strict_types=1);

$EM_CONF['site_package'] = [
    'title' => 'Site Package',
    'description' => 'Local site package extension for TYPO3 v11 testing',
    'category' => 'templates',
    'version' => '1.0.0',
    'state' => 'stable',
    'author' => 'Local Developer',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-11.5.99',
            'php' => '8.0.0-8.2.99',
        ],
    ],
];
