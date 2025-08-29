<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Apache Tika for TYPO3',
    'description' => 'Apache Tika for TYPO3 - Document Text Extraction',
    'category' => 'plugin',
    'author' => 'Apache Solr for TYPO3 Contributors',
    'author_email' => 'solr-team@typo3.org',
    'state' => 'stable',
    'version' => '12.0.3',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
