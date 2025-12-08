<?php

namespace App\Services;

use App\Models\DeviceToken;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        try {
            $credentialsPath = config('services.firebase.credentials');

            if (!$credentialsPath || !file_exists($credentialsPath)) {
                Log::warning('Firebase credentials file not found: ' . $credentialsPath);
                $this->messaging = null;
                return;
            }

            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $this->messaging = $factory->createMessaging();
        } catch (\Exception $e) {
            Log::error('Error initializing Firebase: ' . $e->getMessage());
            $this->messaging = null;
        }
    }

    /**
     * Enviar notificación push a un usuario específico
     *
     * @param int $userId ID del usuario
     * @param string $title Título de la notificación
     * @param string $body Cuerpo de la notificación
     * @param array $data Datos adicionales (opcional)
     * @return array Resultado del envío
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): array
    {
        if (!$this->messaging) {
            Log::warning('Firebase messaging not initialized, skipping notification');
            return [
                'success' => false,
                'message' => 'Firebase not configured',
                'sent_count' => 0,
            ];
        }

        // Obtener todos los tokens activos del usuario
        $deviceTokens = DeviceToken::active()
            ->forUser($userId)
            ->get();

        if ($deviceTokens->isEmpty()) {
            Log::info("No active device tokens found for user {$userId}");
            return [
                'success' => false,
                'message' => 'No active device tokens',
                'sent_count' => 0,
            ];
        }

        $tokens = $deviceTokens->pluck('token')->toArray();

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Enviar notificación a múltiples tokens
     *
     * @param array $tokens Lista de FCM tokens
     * @param string $title Título de la notificación
     * @param string $body Cuerpo de la notificación
     * @param array $data Datos adicionales (opcional)
     * @return array Resultado del envío
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        if (!$this->messaging) {
            return [
                'success' => false,
                'message' => 'Firebase not configured',
                'sent_count' => 0,
            ];
        }

        if (empty($tokens)) {
            return [
                'success' => false,
                'message' => 'No tokens provided',
                'sent_count' => 0,
            ];
        }

        $notification = Notification::create($title, $body);

        $successCount = 0;
        $failedTokens = [];

        foreach ($tokens as $token) {
            try {
                $message = CloudMessage::withTarget('token', $token)
                    ->withNotification($notification)
                    ->withData($data);

                $this->messaging->send($message);
                $successCount++;

                // Actualizar last_used_at del token
                DeviceToken::where('token', $token)->update([
                    'last_used_at' => now(),
                ]);
            } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
                // Token no válido, desactivarlo
                Log::warning("Invalid FCM token, deactivating: {$token}");
                DeviceToken::where('token', $token)->update(['is_active' => false]);
                $failedTokens[] = $token;
            } catch (\Exception $e) {
                Log::error("Error sending notification to token {$token}: " . $e->getMessage());
                $failedTokens[] = $token;
            }
        }

        return [
            'success' => $successCount > 0,
            'message' => $successCount > 0
                ? "Sent to {$successCount} device(s)"
                : 'Failed to send notifications',
            'sent_count' => $successCount,
            'failed_count' => count($failedTokens),
            'failed_tokens' => $failedTokens,
        ];
    }

    /**
     * Enviar notificación a un token específico
     *
     * @param string $token FCM token
     * @param string $title Título de la notificación
     * @param string $body Cuerpo de la notificación
     * @param array $data Datos adicionales (opcional)
     * @return bool
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): bool
    {
        if (!$this->messaging) {
            return false;
        }

        try {
            $notification = Notification::create($title, $body);

            $message = CloudMessage::withTarget('token', $token)
                ->withNotification($notification)
                ->withData($data);

            $this->messaging->send($message);

            return true;
        } catch (\Exception $e) {
            Log::error("Error sending notification: " . $e->getMessage());
            return false;
        }
    }
}
