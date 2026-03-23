<?php

declare(strict_types=1);

namespace Example\Powermail\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Form extends AbstractEntity
{
    protected string $title = '';
    protected string $sender_email = '';
    protected string $receiver_email = '';

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getSenderEmail(): string
    {
        return $this->sender_email;
    }

    public function setSenderEmail(string $sender_email): void
    {
        $this->sender_email = $sender_email;
    }

    public function getReceiverEmail(): string
    {
        return $this->receiver_email;
    }

    public function setReceiverEmail(string $receiver_email): void
    {
        $this->receiver_email = $receiver_email;
    }
}
