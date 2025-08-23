<?php

declare(strict_types=1);

$EM_CONF['custom_tools'] = [
    'title' => 'Custom Tools',
    'description' => 'Local extension without composer metadata for TYPO3 v11 testing',
    'category' => 'misc',
    'version' => '0.1.0',
    'state' => 'experimental',
    'author' => 'Local Developer',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-11.5.99',
            'php' => '8.0.0-8.2.99',
        ],
    ],
];
