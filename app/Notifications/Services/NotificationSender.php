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
     * Send the notification to all configured channels for this company+event.
     * Each channel is fully independent — a failure in one does not block the others.
     */
    public function send(
        NotificationTemplateInterface $template,
        User $user,
        int $companyId
    ): void {
        $pref = NotificationPreference::where('company_id', $companyId)
            ->where('event_type', $template->getEventType())
            ->first();

        // Defaults: all channels enabled when no preference is saved
        $channelDb    = $pref?->channel_db    ?? true;
        $channelEmail = $pref?->channel_email ?? true;
        $channelPush  = $pref?->channel_push  ?? true;

        if ($channelDb) {
            $this->sendToDb($template, $user, $companyId);
        }

        if ($channelEmail) {
            $this->sendEmail($template, $user, $companyId);
        }

        if ($channelPush) {
            $this->sendPush($template, $user, $companyId);
        }
    }

    private function sendToDb(NotificationTemplateInterface $template, User $user, int $companyId): void
    {
        try {
            $notifiable = $template->getNotifiable();

            Notification::create([
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
            ]);
        } catch (\Throwable $e) {
            Log::error('NotificationSender: DB channel failed', [
                'event_type' => $template->getEventType(),
                'user_id'    => $user->id,
                'error'      => $e->getMessage(),
            ]);
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

    private function sendPush(NotificationTemplateInterface $template, User $user, int $companyId): void
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
            $result = $this->firebase->sendToUser(
                $user->id,
                $template->getPushTitle(),
                $template->getPushBody(),
                $template->getPushData()
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
