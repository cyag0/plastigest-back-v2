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

        $successCount = 0;
        $failedTokens = [];

        // Enviamos por dispositivo (no en lote) porque el payload difiere por
        // plataforma: web va data-only para evitar notificaciones DUPLICADAS,
        // nativo lleva notification payload. Ver buildMessage().
        foreach ($deviceTokens as $deviceToken) {
            try {
                $message = $this->buildMessage(
                    $deviceToken->token,
                    $deviceToken->device_type,
                    $title,
                    $body,
                    $data,
                );

                $this->messaging->send($message);
                $successCount++;

                $deviceToken->update(['last_used_at' => now()]);
            } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
                // Token no válido, desactivarlo
                Log::warning("Invalid FCM token, deactivating: {$deviceToken->token}");
                $deviceToken->update(['is_active' => false]);
                $failedTokens[] = $deviceToken->token;
            } catch (\Exception $e) {
                Log::error("Error sending notification to token {$deviceToken->token}: " . $e->getMessage());
                $failedTokens[] = $deviceToken->token;
            }
        }

        return [
            'success'       => $successCount > 0,
            'message'       => $successCount > 0
                ? "Sent to {$successCount} device(s)"
                : 'Failed to send notifications',
            'sent_count'    => $successCount,
            'failed_count'  => count($failedTokens),
            'failed_tokens' => $failedTokens,
        ];
    }

    /**
     * Construye el CloudMessage según la plataforma del dispositivo.
     *
     * WEB: data-only. Si incluyéramos un `notification` payload, Chrome mostraría
     * la notificación automáticamente Y nuestro Service Worker la mostraría otra
     * vez en onBackgroundMessage → notificación DUPLICADA. Mandando solo data,
     * únicamente nuestro handler la pinta (una sola) y conservamos el shape de
     * `data` intacto para el deep-link al hacer click.
     *
     * NATIVE (android/ios): incluimos el `notification` payload para que el SO
     * muestre la notificación automáticamente cuando la app está en segundo
     * plano o cerrada.
     */
    private function buildMessage(
        string  $token,
        ?string $deviceType,
        string  $title,
        string  $body,
        array   $data,
    ): CloudMessage {
        // FCM exige que TODOS los valores de data sean strings.
        $stringData = array_map(
            static fn ($value) => $value === null ? '' : (string) $value,
            $data,
        );

        if ($deviceType === 'web') {
            return CloudMessage::withTarget('token', $token)
                ->withData(array_merge($stringData, [
                    'title' => $title,
                    'body'  => $body,
                ]));
        }

        return CloudMessage::withTarget('token', $token)
            ->withNotification(Notification::create($title, $body))
            ->withData($stringData);
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
