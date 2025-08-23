<?php

declare(strict_types=1);

$EM_CONF['example_news'] = [
    'title' => 'News Extension',
    'description' => 'Example news extension for testing',
    'category' => 'plugin',
    'version' => '11.0.2',
    'state' => 'stable',
    'author' => 'Test Author',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.3.99',
        ],
    ],
];
