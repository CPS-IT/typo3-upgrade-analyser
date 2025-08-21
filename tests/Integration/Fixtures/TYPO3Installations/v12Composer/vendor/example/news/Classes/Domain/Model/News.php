<?php

declare(strict_types=1);

namespace Example\News\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class News extends AbstractEntity
{
    protected string $title = '';
    protected string $bodytext = '';
    protected \DateTime $datetime;

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getBodytext(): string
    {
        return $this->bodytext;
    }

    public function setBodytext(string $bodytext): void
    {
        $this->bodytext = $bodytext;
    }
}
