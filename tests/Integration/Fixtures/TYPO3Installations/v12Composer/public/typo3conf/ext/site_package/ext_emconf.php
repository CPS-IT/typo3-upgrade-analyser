<?php

declare(strict_types=1);

$EM_CONF['site_package'] = [
    'title' => 'Site Package',
    'description' => 'Local site package extension for testing',
    'category' => 'templates',
    'version' => '1.0.0',
    'state' => 'stable',
    'author' => 'Local Developer',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.3.99',
        ],
    ],
];