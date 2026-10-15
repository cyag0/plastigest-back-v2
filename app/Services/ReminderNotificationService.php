<?php

namespace App\Services;

use App\Models\Reminder;
use App\Notifications\NotificationEngine;

/**
 * Avisos de recordatorios. El formulario promete "Recibirás una notificación
 * N días antes", pero nada los enviaba.
 *
 * Por cada recordatorio pendiente con avisos activados sale un aviso al entrar
 * en la ventana de anticipación (notify_days_before) y otro el mismo día.
 * last_notified_at evita repetir el aviso dentro de la misma etapa.
 */
class ReminderNotificationService
{
    public function notifyDue(): int
    {
        $sent = 0;
        $today = today();

        Reminder::query()
            ->where('status', 'pending')
            ->where('notify_enabled', true)
            ->whereNotNull('user_id')
            ->whereDate('reminder_date', '>=', $today)
            ->chunkById(100, function ($reminders) use (&$sent, $today) {
                foreach ($reminders as $reminder) {
                    $dueDate = $reminder->reminder_date->copy()->startOfDay();
                    $windowStart = $dueDate->copy()->subDays(max(0, (int) $reminder->notify_days_before));

                    if ($today->lt($windowStart)) {
                        continue;
                    }

                    // Etapa actual: el mismo día, o la anticipación.
                    $stageStart = $today->isSameDay($dueDate) ? $dueDate : $windowStart;
                    if ($reminder->last_notified_at && $reminder->last_notified_at->gte($stageStart)) {
                        continue;
                    }

                    NotificationEngine::dispatch('reminder', (int) $reminder->company_id, [
                        'reminder' => $reminder,
                    ], userId: (int) $reminder->user_id);

                    $reminder->forceFill(['last_notified_at' => now()])->save();
                    $sent++;
                }
            });

        return $sent;
    }
}
