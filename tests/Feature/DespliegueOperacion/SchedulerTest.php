<?php

namespace Tests\Feature\DespliegueOperacion;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

/**
 * Tareas programadas: el candado de los avisos vence pronto y la salida de
 * cada tarea llega a los logs del contenedor (Coolify corre schedule:run con
 * docker exec y Laravel la mandaba a /dev/null).
 */
class SchedulerTest extends TestCase
{
    /**
     * Carga routes/console.php en un Schedule limpio y devuelve sus tareas.
     *
     * @return Event[]
     */
    private function loadSchedule(): array
    {
        $schedule = new Schedule();
        ScheduleFacade::swap($schedule);

        require base_path('routes/console.php');

        return $schedule->events();
    }

    private function event(array $events, string $command): Event
    {
        foreach ($events as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        $this->fail("No está programado {$command}");
    }

    public function test_scheduled_notifications_lock_expires_in_ten_minutes(): void
    {
        $event = $this->event($this->loadSchedule(), 'notifications:scheduled');

        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_task_output_goes_to_the_container_log_when_writable(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'schedule-output');
        config(['logging.schedule_output' => $log]);

        try {
            $events = $this->loadSchedule();

            $this->assertNotEmpty($events);
            foreach ($events as $event) {
                $this->assertSame($log, $event->output);
                $this->assertTrue($event->shouldAppendOutput);
                $this->assertStringContainsString(">> '{$log}' 2>&1", $event->buildCommand());
            }
        } finally {
            @unlink($log);
        }
    }

    public function test_task_output_is_left_alone_when_the_log_is_not_writable(): void
    {
        config(['logging.schedule_output' => '/no-existe/gcstock/schedule.log']);

        foreach ($this->loadSchedule() as $event) {
            $this->assertSame('/dev/null', $event->output);
        }
    }
}
