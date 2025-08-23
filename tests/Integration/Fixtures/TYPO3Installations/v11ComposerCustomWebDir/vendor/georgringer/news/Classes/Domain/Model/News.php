<?php

declare(strict_types=1);

namespace GeorgRinger\News\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class News extends AbstractEntity
{
    protected string $title = '';
    protected string $teaser = '';
    protected \DateTime $datetime;

    public function __construct()
    {
        $this->datetime = new \DateTime();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getTeaser(): string
    {
        return $this->teaser;
    }

    public function setTeaser(string $teaser): void
    {
        $this->teaser = $teaser;
    }

    public function getDatetime(): \DateTime
    {
        return $this->datetime;
    }

    public function setDatetime(\DateTime $datetime): void
    {
        $this->datetime = $datetime;
    }
}
