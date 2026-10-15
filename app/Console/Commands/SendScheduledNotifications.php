<?php

namespace App\Console\Commands;

use App\Services\ReminderNotificationService;
use App\Services\TaskService;
use Illuminate\Console\Command;

/**
 * Avisos que dependen del reloj y no de una acción: tareas que vencen pronto o
 * ya vencieron, y recordatorios. Corre cada hora en horario de trabajo (ver
 * routes/console.php); cada aviso sale una sola vez.
 */
class SendScheduledNotifications extends Command
{
    protected $signature = 'notifications:scheduled';

    protected $description = 'Envía los avisos de tareas por vencer o vencidas y de recordatorios';

    public function handle(TaskService $tasks, ReminderNotificationService $reminders): int
    {
        $due = $tasks->notifyDueTasks();
        $this->info("Tareas: {$due['due_soon']} por vencer, {$due['overdue']} vencidas.");

        $sent = $reminders->notifyDue();
        $this->info("Recordatorios avisados: {$sent}.");

        return self::SUCCESS;
    }
}
