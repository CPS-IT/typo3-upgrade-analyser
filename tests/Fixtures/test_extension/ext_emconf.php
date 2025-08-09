<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Test Extension',
    'description' => 'Test extension for Rector analysis',
    'category' => 'misc',
    'version' => '1.0.0',
    'state' => 'stable',
    'author' => 'Test Author',
    'author_email' => 'test@example.com',
    'constraints' => [
        'depends' => [
            'typo3' => '12.0.0-12.99.99',
        ],
    ],
];
