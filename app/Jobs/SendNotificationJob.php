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
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;

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
            $recipients = $recipientResolver->resolve(
                $this->companyId,
                $this->eventType,
                $template->getDefaultPermission(),
                $this->specificUserId,
                $this->resolveLocationId(),
            );

            foreach ($recipients as $user) {
                $sender->send($template, $user, $this->companyId);
            }
        } catch (\Throwable $e) {
            Log::error('SendNotificationJob: failed', [
                'event_type'  => $this->eventType,
                'company_id'  => $this->companyId,
                'user_id'     => $this->specificUserId,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            throw $e; // Let the queue retry
        }
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
