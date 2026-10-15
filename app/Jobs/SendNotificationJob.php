<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\Services\NotificationSender;
use App\Notifications\Services\RecipientResolver;
use App\Notifications\Services\TemplateResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** Segundos antes de cada reintento: una caída breve de FCM o del correo no agota los intentos. */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly string $eventType,
        private readonly int    $companyId,
        private readonly array  $contextData,
        private readonly ?int   $specificUserId = null,
    ) {}

    public function handle(
        TemplateResolver   $templateResolver,
        RecipientResolver  $recipientResolver,
        NotificationSender $sender,
    ): void {
        try {
            $template   = $templateResolver->resolve($this->eventType, $this->contextData);
            $locationId = $this->resolveLocationId();
            $recipients = $recipientResolver->resolve(
                $this->companyId,
                $this->eventType,
                $template->getDefaultPermission(),
                $this->specificUserId,
                $locationId,
            );

            // Quien provocó el evento (p. ej. registró el ajuste) no recibe su
            // propio aviso.
            $excludedUserId = $this->contextData['exclude_user_id'] ?? null;

            // En un reintento (una excepción a medio envío, o el worker
            // detenido por un deploy) no se repite el aviso a quienes ya lo
            // recibieron en un intento anterior.
            $served = $this->servedRecipients();

            foreach ($recipients as $user) {
                if ($excludedUserId !== null && (int) $user->id === (int) $excludedUserId) {
                    continue;
                }
                if (in_array((int) $user->id, $served, true)) {
                    continue;
                }

                $sender->send($template, $user, $this->companyId, $locationId);

                $served[] = (int) $user->id;
                $this->rememberServed($served);
            }
        } catch (\Throwable $e) {
            Log::error('SendNotificationJob: failed', [
                'event_type'  => $this->eventType,
                'company_id'  => $this->companyId,
                'user_id'     => $this->specificUserId,
                'attempt'     => $this->attempts(),
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            throw $e; // Let the queue retry
        }
    }

    /**
     * Destinatarios ya atendidos por intentos anteriores de este mismo job
     * (misma uuid en la cola).
     *
     * @return int[]
     */
    private function servedRecipients(): array
    {
        $key = $this->servedCacheKey();

        return $key ? array_map('intval', (array) Cache::get($key, [])) : [];
    }

    /**
     * @param int[] $served
     */
    private function rememberServed(array $served): void
    {
        if ($key = $this->servedCacheKey()) {
            Cache::put($key, $served, now()->addDay());
        }
    }

    private function servedCacheKey(): ?string
    {
        $uuid = $this->job?->uuid();

        return $uuid ? "notification-job:{$uuid}:served" : null;
    }

    private function resolveLocationId(): ?int
    {
        if (isset($this->contextData['location_id']) && is_numeric($this->contextData['location_id'])) {
            return (int) $this->contextData['location_id'];
        }

        foreach (['location' => 'id', 'task' => 'location_id', 'purchase' => 'location_id', 'inventory_count' => 'location_id', 'adjustment' => 'location_id'] as $key => $property) {
            $model = $this->contextData[$key] ?? null;

            if ($model && isset($model->{$property}) && is_numeric($model->{$property})) {
                return (int) $model->{$property};
            }
        }

        return null;
    }
}
