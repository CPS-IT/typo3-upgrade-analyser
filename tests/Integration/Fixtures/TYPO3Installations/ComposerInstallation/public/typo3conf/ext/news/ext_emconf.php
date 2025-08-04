<?php

declare(strict_types=1);

$EM_CONF['news'] = [
    'title' => 'News',
    'description' => 'Versatile news extension for TYPO3',
    'category' => 'plugin',
    'version' => '11.0.2',
    'state' => 'stable',
    'clearcacheonload' => true,
    'author' => 'Georg Ringer',
    'author_email' => 'mail@ringer.it',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.3.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'dd_googlesitemap' => '',
            'realurl' => '',
        ],
    ],
];
