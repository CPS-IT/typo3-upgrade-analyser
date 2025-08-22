<?php

declare(strict_types=1);

namespace Local\SitePackage\Controller;

use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class PageController extends ActionController
{
    public function indexAction(): void
    {
        $this->view->assign('message', 'Site Package v11 Controller');
    }

    public function detailAction(): void
    {
        $this->view->assign('detail', 'Detail page from site package');
    }
}
