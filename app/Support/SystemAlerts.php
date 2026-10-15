<?php

namespace App\Support;

use App\Mail\SystemAlertMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Avisos de operación para los administradores del sistema: un trabajo de la
 * cola que falló del todo (correo del corte de caja, push, OTP) o una tarea
 * programada que falló. Antes nadie se enteraba: los jobs fallidos se
 * borraban a los 7 días sin aviso.
 *
 * Cada alerta se registra en el log como critical (Coolify) y, una vez por
 * ventana de tiempo y tipo de falla, crea una notificación en la campana de
 * cada administrador del sistema y les manda correo si el correo está
 * configurado. Nunca lanza excepciones: avisar no puede tumbar al worker.
 */
class SystemAlerts
{
    /** Minutos entre dos avisos del mismo tipo de falla (sin inundar la campana). */
    public const THROTTLE_MINUTES = 30;

    public const EVENT_TYPE = 'system_alert';

    /** Nombres para mostrar de los trabajos conocidos. */
    private const JOB_LABELS = [
        \App\Jobs\SendNotificationJob::class => 'Envío de notificaciones',
        \App\Mail\CashClosingMail::class => 'Correo del corte de caja',
        \App\Mail\PasswordResetMail::class => 'Correo de recuperación de contraseña',
        \App\Mail\GenericNotificationMail::class => 'Correo de notificación',
    ];

    public function jobFailed(JobFailed $event): void
    {
        $this->guard(function () use ($event) {
            $name = $event->job->resolveName();
            $label = self::JOB_LABELS[$name] ?? class_basename($name);
            $error = $this->describe($event->exception);

            $this->raise(
                'job:' . $name,
                'Falló un trabajo en segundo plano',
                "{$label} falló después de {$event->job->attempts()} intento(s): {$error}",
                [
                    'job' => $name,
                    'job_id' => $event->job->uuid(),
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                    'attempts' => $event->job->attempts(),
                    'exception' => get_class($event->exception),
                    'error' => $event->exception->getMessage(),
                ]
            );
        });
    }

    public function scheduledTaskFailed(ScheduledTaskFailed $event): void
    {
        $this->guard(function () use ($event) {
            $task = trim($event->task->getSummaryForDisplay());
            $error = $this->describe($event->exception);

            $this->raise(
                'schedule:' . $task,
                'Falló una tarea programada',
                "La tarea \"{$task}\" falló: {$error}",
                [
                    'task' => $task,
                    'exit_code' => $event->task->exitCode,
                    'exception' => get_class($event->exception),
                    'error' => $event->exception->getMessage(),
                ]
            );
        });
    }

    /**
     * Registra la alerta y avisa a los administradores (con límite por tipo).
     */
    public function raise(string $key, string $title, string $message, array $context = []): void
    {
        $this->guard(function () use ($key, $title, $message, $context) {
            Log::critical("{$title}: {$message}", $context);

            $cacheKey = 'system-alert:' . sha1($key);

            // Ya se avisó de esta falla hace poco: solo se cuenta, y el
            // siguiente aviso dice cuántas veces más ocurrió.
            if (!Cache::add($cacheKey, true, now()->addMinutes(self::THROTTLE_MINUTES))) {
                // increment() no crea la llave en todos los stores (database).
                Cache::add($cacheKey . ':repeats', 0, now()->addDay());
                Cache::increment($cacheKey . ':repeats');

                return;
            }

            $repeats = (int) Cache::pull($cacheKey . ':repeats', 0);
            if ($repeats > 0) {
                $message .= " (y se repitió {$repeats} vez/veces desde el aviso anterior)";
            }

            $admins = $this->systemAdmins();
            $this->notifyInApp($admins, $title, $message, $key);
            $this->notifyByEmail($admins, $title, $message);
        });
    }

    /**
     * Usuarios activos con un rol que tiene system_admin (directo o por
     * sucursal), como el Super Admin de la primera instalación.
     *
     * @return Collection<int, User>
     */
    private function systemAdmins(): Collection
    {
        $roleIds = DB::table('rol_permission')
            ->join('permissions', 'permissions.id', '=', 'rol_permission.permission_id')
            ->where('permissions.name', 'system_admin')
            ->pluck('rol_permission.role_id');

        if ($roleIds->isEmpty()) {
            return collect();
        }

        $userIds = DB::table('users_roles')->whereIn('role_id', $roleIds)->pluck('user_id')
            ->merge(DB::table('user_location_roles')->whereIn('role_id', $roleIds)->pluck('user_id'))
            ->unique();

        return User::whereIn('id', $userIds)->where('is_active', true)->get();
    }

    private function notifyInApp(Collection $admins, string $title, string $message, string $key): void
    {
        $allCompanies = null;

        foreach ($admins as $admin) {
            // La campana muestra las de la empresa actual: una por cada empresa
            // del administrador (o todas, si no tiene ninguna asignada).
            $companyIds = DB::table('user_company')->where('user_id', $admin->id)->pluck('company_id');
            if ($companyIds->isEmpty()) {
                $allCompanies ??= DB::table('companies')->pluck('id');
                $companyIds = $allCompanies;
            }

            foreach ($companyIds->unique() as $companyId) {
                try {
                    Notification::create([
                        'user_id' => $admin->id,
                        'company_id' => $companyId,
                        'event_type' => self::EVENT_TYPE,
                        'title' => $title,
                        'message' => Str::limit($message, 1000),
                        'severity' => 'error',
                        'data' => ['alert' => Str::before($key, ':')],
                        'channel' => 'db',
                        'delivery_status' => 'sent',
                    ]);
                } catch (Throwable $e) {
                    Log::error('SystemAlerts: no se pudo crear la notificación', [
                        'user_id' => $admin->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Correo directo (SystemAlertMail no se encola): si lo que falla es la
     * cola o el correo, un aviso encolado fallaría también.
     */
    private function notifyByEmail(Collection $admins, string $title, string $message): void
    {
        $emails = $admins->pluck('email')->filter()->unique()->values()->all();
        if (empty($emails) || !self::mailIsConfigured()) {
            return;
        }

        try {
            Mail::to($emails)->send(new SystemAlertMail($title, $message));
        } catch (Throwable $e) {
            Log::error('SystemAlerts: no se pudo enviar el correo de alerta', ['error' => $e->getMessage()]);
        }
    }

    /**
     * ¿Hay un proveedor de correo real? (no log/array, y con credenciales).
     */
    public static function mailIsConfigured(): bool
    {
        return match (config('mail.default')) {
            null, 'log', 'array' => false,
            'resend' => filled(config('services.resend.key')),
            'smtp' => filled(config('mail.mailers.smtp.host')),
            default => true,
        };
    }

    private function describe(Throwable $e): string
    {
        return Str::limit(trim($e->getMessage()) ?: class_basename($e), 300);
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            try {
                Log::error('SystemAlerts: falló el aviso de operación', [
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // Ni el log está disponible: no hay nada más que hacer.
            }
        }
    }
}
