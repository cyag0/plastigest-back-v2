<?php

namespace App\Notifications\Contracts;

interface NotificationTemplateInterface
{
    /**
     * @param array $contextData The event-specific data (product, location, task, etc.)
     */
    public function __construct(array $contextData);

    // Slug identifying this event type, e.g. 'low_stock'
    public function getEventType(): string;

    // Visual severity for the in-app badge
    public function getSeverity(): string; // info|success|warning|error|alert

    // Permission required to receive this event when no company preference is set
    public function getDefaultPermission(): string;

    // In-app notification content
    public function getTitle(): string;
    public function getMessage(): string;

    // Email content
    public function getEmailSubject(): string;
    public function getEmailView(): string;   // Blade view name, e.g. 'emails.notifications.low-stock'
    public function getEmailData(): array;    // Variables injected into the view

    // Firebase FCM push content
    public function getPushTitle(): string;
    public function getPushBody(): string;
    public function getPushData(): array;    // Extra payload sent via FCM data field

    // The model that triggered this notification (for polymorphic notifiable columns)
    public function getNotifiable(): ?object;
}
