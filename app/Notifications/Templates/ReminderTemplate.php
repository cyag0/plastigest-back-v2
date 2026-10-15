<?php

namespace App\Notifications\Templates;

use Carbon\Carbon;

/**
 * Aviso de un recordatorio (pagos a proveedor, renovaciones, etc.) para el
 * usuario al que está asignado. Sale con anticipación (notify_days_before) y el
 * mismo día.
 */
class ReminderTemplate extends BaseNotificationTemplate
{
    public function getEventType(): string
    {
        return 'reminder';
    }

    public function getSeverity(): string
    {
        return $this->isDueToday() ? 'warning' : 'info';
    }

    public function getTitle(): string
    {
        $title = $this->context['reminder']?->title ?? 'Recordatorio';

        return $this->isDueToday() ? "Hoy: {$title}" : "Recordatorio: {$title}";
    }

    public function getMessage(): string
    {
        $reminder = $this->context['reminder'] ?? null;
        $title    = $reminder?->title ?? 'tu recordatorio';
        $date     = $reminder?->reminder_date ? Carbon::parse($reminder->reminder_date) : null;
        $amount   = $reminder?->amount ? ' por $' . number_format((float) $reminder->amount, 2) : '';

        if (!$date || $this->isDueToday()) {
            return "Hoy toca: {$title}{$amount}.";
        }

        $days = (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
        $when = $days === 1 ? 'mañana' : "en {$days} días ({$date->format('d/m/Y')})";

        return "{$title}{$amount} vence {$when}.";
    }

    public function getEmailView(): string
    {
        return 'emails.notifications.generic';
    }

    public function getPushData(): array
    {
        return [
            'event_type'  => $this->getEventType(),
            'reminder_id' => $this->context['reminder']?->id ?? null,
        ];
    }

    public function getNotifiable(): ?object
    {
        return $this->context['reminder'] ?? null;
    }

    private function isDueToday(): bool
    {
        $date = $this->context['reminder']?->reminder_date ?? null;

        return $date && Carbon::parse($date)->isSameDay(now());
    }
}
