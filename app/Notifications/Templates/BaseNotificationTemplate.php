<?php

namespace App\Notifications\Templates;

use App\Notifications\Contracts\NotificationTemplateInterface;

abstract class BaseNotificationTemplate implements NotificationTemplateInterface
{
    protected array $context;

    public function __construct(array $contextData)
    {
        $this->context = $contextData;
    }

    public function getSeverity(): string
    {
        return 'info';
    }

    public function getPushTitle(): string
    {
        return $this->getTitle();
    }

    public function getPushBody(): string
    {
        return $this->getMessage();
    }

    public function getPushData(): array
    {
        return [
            'event_type' => $this->getEventType(),
        ];
    }

    public function getEmailSubject(): string
    {
        return $this->getTitle();
    }

    public function getEmailData(): array
    {
        return $this->context;
    }

    public function getNotifiable(): ?object
    {
        return null;
    }

    public function getDefaultPermission(): string
    {
        return '';
    }
}
