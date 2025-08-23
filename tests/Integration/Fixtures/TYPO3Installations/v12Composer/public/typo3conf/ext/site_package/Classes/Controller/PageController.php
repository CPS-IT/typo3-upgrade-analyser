<?php

declare(strict_types=1);

namespace Local\SitePackage\Controller;

use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class PageController extends ActionController
{
    public function showAction(): string
    {
        return 'Local Site Package Controller';
    }
}
