<?php

namespace App\Notifications\Templates;

class TaskEventTemplate extends BaseNotificationTemplate
{
    public function getEventType(): string
    {
        return 'task_event';
    }

    // task_event targets a specific userId, no permission needed
    public function getDefaultPermission(): string
    {
        return '';
    }

    public function getTitle(): string
    {
        $task      = $this->context['task']      ?? null;
        $subType   = $this->context['sub_type']  ?? '';
        $actorName = $this->context['actor_name'] ?? 'Alguien';
        $taskTitle = $task?->title ?? 'Tarea';

        return match ($subType) {
            'assigned'  => "Nueva tarea asignada: {$taskTitle}",
            'completed' => "Tarea completada: {$taskTitle}",
            'overdue'   => "Tarea vencida: {$taskTitle}",
            'due_soon'  => "Vence pronto: {$taskTitle}",
            'comment'   => "{$actorName} comentó en: {$taskTitle}",
            default     => "Actualización de tarea: {$taskTitle}",
        };
    }

    public function getMessage(): string
    {
        $task      = $this->context['task']       ?? null;
        $subType   = $this->context['sub_type']   ?? '';
        $actorName = $this->context['actor_name'] ?? 'Alguien';
        $taskTitle = $task?->title ?? 'Tarea';

        return match ($subType) {
            'assigned'  => "{$actorName} te asignó la tarea '{$taskTitle}'.",
            'completed' => "{$actorName} marcó como completada la tarea '{$taskTitle}'.",
            'overdue'   => "La tarea '{$taskTitle}' ya pasó su fecha límite.",
            'due_soon'  => "La tarea '{$taskTitle}' vence en menos de un día.",
            'comment'   => "{$actorName} dejó un comentario en '{$taskTitle}'.",
            default     => "La tarea '{$taskTitle}' fue actualizada.",
        };
    }

    public function getSeverity(): string
    {
        return match ($this->context['sub_type'] ?? '') {
            'overdue'   => 'warning',
            'completed' => 'success',
            default     => 'info',
        };
    }

    public function getEmailView(): string
    {
        return 'emails.notifications.task-event';
    }

    public function getPushData(): array
    {
        return [
            'event_type' => $this->getEventType(),
            'task_id'    => $this->context['task']?->id    ?? null,
            'sub_type'   => $this->context['sub_type']     ?? null,
            'actor_name' => $this->context['actor_name']   ?? null,
        ];
    }

    public function getNotifiable(): ?object
    {
        return $this->context['task'] ?? null;
    }
}
