<?php

declare(strict_types=1);

$EM_CONF['custom_tools'] = [
    'title' => 'Custom Tools',
    'description' => 'Local extension without composer metadata for testing',
    'category' => 'misc',
    'version' => '0.1.0',
    'state' => 'experimental',
    'author' => 'Local Developer',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.3.99',
        ],
    ],
];