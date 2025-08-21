<?php

declare(strict_types=1);

namespace GeorgRinger\News\Controller;

use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class NewsController extends ActionController
{
    public function listAction(): string
    {
        return 'GeorgRinger News List';
    }
}