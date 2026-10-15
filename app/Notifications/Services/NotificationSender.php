<?php

namespace App\Notifications\Services;

use App\Mail\GenericNotificationMail;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Contracts\NotificationTemplateInterface;
use App\Services\FirebaseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationSender
{
    public function __construct(
        private FirebaseService $firebase,
    ) {}

    /**
     * Send the notification to all configured channels for this company+event
     * (the location's channels when the location customized the event).
     * Each channel is fully independent — a failure in one does not block the others.
     */
    public function send(
        NotificationTemplateInterface $template,
        User $user,
        int $companyId,
        ?int $locationId = null,
    ): void {
        $pref = NotificationPreference::effective($companyId, $template->getEventType(), $locationId);

        // Sin preferencia guardada se usan los mismos valores por defecto que
        // muestra la pantalla de Preferencias (antes aquí todo era true y se
        // mandaba correo de cada evento aunque la pantalla dijera lo contrario).
        $defaults     = NotificationPreference::getDefaults()[$template->getEventType()] ?? [];
        $channelDb    = $pref?->channel_db    ?? ($defaults['channel_db'] ?? true);
        $channelEmail = $pref?->channel_email ?? ($defaults['channel_email'] ?? false);
        $channelPush  = $pref?->channel_push  ?? ($defaults['channel_push'] ?? true);

        $inAppId = null;
        if ($channelDb) {
            $inAppId = $this->sendToDb($template, $user, $companyId);
        }

        if ($channelEmail) {
            $this->sendEmail($template, $user, $companyId);
        }

        if ($channelPush) {
            $this->sendPush($template, $user, $companyId, $inAppId);
        }
    }

    /** @return int|null id de la notificación en la bandeja */
    private function sendToDb(NotificationTemplateInterface $template, User $user, int $companyId): ?int
    {
        try {
            $notifiable = $template->getNotifiable();

            return Notification::create([
                'user_id'          => $user->id,
                'company_id'       => $companyId,
                'event_type'       => $template->getEventType(),
                'title'            => $template->getTitle(),
                'message'          => $template->getMessage(),
                'severity'         => $template->getSeverity(),
                'data'             => $template->getPushData(),
                'channel'          => 'db',
                'delivery_status'  => 'sent',
                'notifiable_type'  => $notifiable ? get_class($notifiable) : null,
                'notifiable_id'    => $notifiable?->id,
            ])->id;
        } catch (\Throwable $e) {
            Log::error('NotificationSender: DB channel failed', [
                'event_type' => $template->getEventType(),
                'user_id'    => $user->id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function sendEmail(NotificationTemplateInterface $template, User $user, int $companyId): void
    {
        $notifiable = $template->getNotifiable();

        $record = Notification::create([
            'user_id'          => $user->id,
            'company_id'       => $companyId,
            'event_type'       => $template->getEventType(),
            'title'            => $template->getTitle(),
            'message'          => $template->getMessage(),
            'severity'         => $template->getSeverity(),
            'data'             => $template->getPushData(),
            'channel'          => 'email',
            'delivery_status'  => 'pending',
            'notifiable_type'  => $notifiable ? get_class($notifiable) : null,
            'notifiable_id'    => $notifiable?->id,
        ]);

        try {
            // TODO(perf): este `Mail::send` es sincrono y corre dentro del
            // SendNotificationJob. Si el SMTP tarda en responder (timeout,
            // DNS, TLS handshake, etc.) bloquea al worker y retrasa la
            // generacion del push y la atencion de otros recipients.
            //
            // Hay dos opciones a evaluar cuando el volumen crezca:
            //
            // 1. Rapido y sin config adicional: cambiar a
            //    `Mail::to(...)->queue(new GenericNotificationMail(...))`.
            //    Laravel detecta que el Mailable usa el trait Queueable y
            //    lo encola en la misma conexion (`QUEUE_CONNECTION=database`,
            //    queue `notifications` por default). El worker existente
            //    lo procesa. La unica diferencia: el delivery_status se
            //    actualizaria a 'sent' por el sub-job, no por este metodo.
            //
            // 2. Mejor pero mas cambio: extraer el envio a un sub-job
            //    dedicado (p.ej. `SendNotificationEmailJob`) con su propio
            //    `tries`/`backoff` independiente del push. Asi un SMTP
            //    caido no bloquea la entrega de push a otros recipients
            //    del mismo evento, y se puede escalar el pool de workers
            //    de email por separado. Habria que:
            //    - Crear `App\Jobs\SendNotificationEmailJob`
            //    - Mover toda la logica de este try/catch ahi
            //    - Reemplazar este `Mail::send` por
            //      `SendNotificationEmailJob::dispatch($record->id)`
            //    - Agregar el nuevo queue al supervisor: `--queue=notifications,emails,default`
            //
            // Mientras tanto, dejaremos `send()` para no cambiar
            // delivery_status contract; en dev con Mailhog es instantaneo.
            Mail::to($user->email)->send(new GenericNotificationMail($template, $user));
            $record->update(['delivery_status' => 'sent']);
        } catch (\Throwable $e) {
            $record->update([
                'delivery_status' => 'failed',
                'delivery_error'  => $e->getMessage(),
            ]);
            Log::error('NotificationSender: Email channel failed', [
                'event_type' => $template->getEventType(),
                'user_id'    => $user->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function sendPush(NotificationTemplateInterface $template, User $user, int $companyId, ?int $inAppId = null): void
    {
        $notifiable = $template->getNotifiable();

        $record = Notification::create([
            'user_id'          => $user->id,
            'company_id'       => $companyId,
            'event_type'       => $template->getEventType(),
            'title'            => $template->getTitle(),
            'message'          => $template->getMessage(),
            'severity'         => $template->getSeverity(),
            'data'             => $template->getPushData(),
            'channel'          => 'push',
            'delivery_status'  => 'pending',
            'notifiable_type'  => $notifiable ? get_class($notifiable) : null,
            'notifiable_id'    => $notifiable?->id,
        ]);

        try {
            // notification_id: al tocar el push la app marca como leída la
            // misma notificación de la bandeja.
            $result = $this->firebase->sendToUser(
                $user->id,
                $template->getPushTitle(),
                $template->getPushBody(),
                array_merge($template->getPushData(), array_filter([
                    'notification_id' => $inAppId,
                    // Para no abrir un registro de otra empresa que la actual.
                    'company_id'      => $companyId,
                ]))
            );

            $record->update([
                'delivery_status' => $result['success'] ? 'sent' : 'failed',
                'delivery_error'  => $result['success'] ? null : ($result['message'] ?? 'FCM error'),
            ]);
        } catch (\Throwable $e) {
            $record->update([
                'delivery_status' => 'failed',
                'delivery_error'  => $e->getMessage(),
            ]);
            Log::error('NotificationSender: Push channel failed', [
                'event_type' => $template->getEventType(),
                'user_id'    => $user->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
