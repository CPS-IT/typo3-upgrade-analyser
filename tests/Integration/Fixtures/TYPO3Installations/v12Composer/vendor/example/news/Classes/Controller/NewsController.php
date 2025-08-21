<?php

declare(strict_types=1);

namespace Example\News\Controller;

use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Example\News\Domain\Model\News;

class NewsController extends ActionController
{
    public function listAction(): void
    {
        $news = new News();
        $news->setTitle('Test News');
        $news->setBodytext('This is test content for the news article.');

        $this->view->assign('news', [$news]);
    }

    public function showAction(News $news): void
    {
        $this->view->assign('news', $news);
    }
}
