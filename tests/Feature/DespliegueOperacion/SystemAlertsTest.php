<?php

namespace Tests\Feature\DespliegueOperacion;

use App\Mail\SystemAlertMail;
use App\Models\Notification;
use App\Models\User;
use App\Support\SystemAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Un job que falla del todo o una tarea programada que falla avisa a los
 * administradores del sistema (campana, y correo si está configurado), sin
 * inundarlos y sin tumbar al worker si el aviso mismo falla.
 */
class SystemAlertsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private User $cashier;

    /** @var string[] */
    private array $criticalLogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // $this->user tiene todos los permisos, incluido system_admin.
        $this->createTenant();
        $this->cashier = $this->createBranchUser($this->location, ['sales_pos']);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            if ($event->level === 'critical') {
                $this->criticalLogs[] = $event->message;
            }
        });
    }

    private function alertsFor(User $user)
    {
        return Notification::where('user_id', $user->id)
            ->where('event_type', SystemAlerts::EVENT_TYPE)
            ->orderBy('id')
            ->get();
    }

    private function runFailingJob(): void
    {
        try {
            Queue::push(new AlwaysFailingJob());
        } catch (RuntimeException $e) {
            $this->assertSame('falla de prueba', $e->getMessage());

            return;
        }

        $this->fail('El job debió fallar.');
    }

    public function test_a_failed_queue_job_alerts_only_system_admins(): void
    {
        $this->runFailingJob();

        $alerts = $this->alertsFor($this->user);
        $this->assertCount(1, $alerts);
        $this->assertSame('error', $alerts[0]->severity);
        $this->assertSame('db', $alerts[0]->channel);
        $this->assertSame($this->company->id, $alerts[0]->company_id);
        $this->assertSame('Falló un trabajo en segundo plano', $alerts[0]->title);
        $this->assertStringContainsString('AlwaysFailingJob', $alerts[0]->message);
        $this->assertStringContainsString('falla de prueba', $alerts[0]->message);

        $this->assertCount(0, $this->alertsFor($this->cashier), 'el cajero no es administrador del sistema');
        $this->assertCount(1, $this->criticalLogs);
    }

    public function test_repeated_failures_are_throttled_and_counted(): void
    {
        $this->runFailingJob();
        $this->runFailingJob();
        $this->runFailingJob();

        $this->assertCount(1, $this->alertsFor($this->user));
        $this->assertCount(3, $this->criticalLogs, 'cada falla queda en el log');

        $this->travel(SystemAlerts::THROTTLE_MINUTES + 1)->minutes();
        $this->runFailingJob();

        $alerts = $this->alertsFor($this->user);
        $this->assertCount(2, $alerts);
        $this->assertStringContainsString('se repitió 2', $alerts[1]->message);
    }

    public function test_a_failed_scheduled_task_alerts_system_admins(): void
    {
        $task = $this->app->make(Schedule::class)->command('notifications:scheduled');

        event(new ScheduledTaskFailed($task, new RuntimeException('sin conexión a la base')));

        $alerts = $this->alertsFor($this->user);
        $this->assertCount(1, $alerts);
        $this->assertSame('Falló una tarea programada', $alerts[0]->title);
        $this->assertStringContainsString('notifications:scheduled', $alerts[0]->message);
        $this->assertStringContainsString('sin conexión a la base', $alerts[0]->message);
    }

    public function test_alert_email_is_sent_only_when_mail_is_configured(): void
    {
        Mail::fake();

        // En pruebas el mailer es "array": no hay proveedor real.
        $this->runFailingJob();
        Mail::assertNothingSent();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);
        $this->travel(SystemAlerts::THROTTLE_MINUTES + 1)->minutes();
        $this->runFailingJob();

        Mail::assertSent(SystemAlertMail::class, function (SystemAlertMail $mail) {
            return $mail->hasTo($this->user->email)
                && !$mail->hasTo($this->cashier->email)
                && str_contains($mail->alertMessage, 'falla de prueba');
        });
    }

    public function test_a_failing_alert_does_not_break_the_worker(): void
    {
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache caída'));

        // El aviso falla por dentro, pero el job se marca fallido normalmente.
        $this->runFailingJob();

        $this->assertCount(0, $this->alertsFor($this->user));
        $this->assertCount(1, $this->criticalLogs);
    }
}

/**
 * Job de prueba que siempre falla.
 */
class AlwaysFailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('falla de prueba');
    }
}
