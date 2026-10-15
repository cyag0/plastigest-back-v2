<?php

namespace App\Http\Controllers;

use App\Models\PurchaseV2;
use App\Services\TaskService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Verify webhook (required by Meta)
     * GET /api/webhooks/whatsapp
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // El verify token lo defines tú en Meta Dashboard y en
        // WHATSAPP_VERIFY_TOKEN. Sin él no se verifica nada: antes había un
        // valor por defecto, público en el repositorio, con el que cualquiera
        // pasaba la verificación.
        $verifyToken = config('services.whatsapp.verify_token');

        if (empty($verifyToken)) {
            Log::warning('WhatsApp webhook: falta WHATSAPP_VERIFY_TOKEN, verificación rechazada');

            return response()->json(['error' => 'Verification failed'], 403);
        }

        if ($mode === 'subscribe' && is_string($token) && hash_equals((string) $verifyToken, $token)) {
            Log::info('WhatsApp webhook verified');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response()->json(['error' => 'Verification failed'], 403);
    }

    /**
     * Receive webhook notifications
     * POST /api/webhooks/whatsapp
     */
    public function handle(Request $request)
    {
        if (!$this->signatureIsValid($request)) {
            Log::warning('WhatsApp webhook con firma inválida o ausente');
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        try {
            $data = $request->all();

            // Sin el payload: trae teléfonos y textos de los proveedores. En
            // los logs solo van ids de mensaje y estados.
            Log::info('WhatsApp webhook received', [
                'messages' => count($data['entry'][0]['changes'][0]['value']['messages'] ?? []),
                'statuses' => count($data['entry'][0]['changes'][0]['value']['statuses'] ?? []),
            ]);

            // Verificar que sea una notificación de mensajes
            if (!isset($data['entry'][0]['changes'][0]['value']['messages'])) {
                // Verificar si es una notificación de estado (delivery/read/failed)
                if (isset($data['entry'][0]['changes'][0]['value']['statuses'])) {
                    $this->processMessageStatus($data['entry'][0]['changes'][0]['value']['statuses']);
                }
                return response()->json(['status' => 'ok']);
            }

            $messages = $data['entry'][0]['changes'][0]['value']['messages'];

            foreach ($messages as $message) {
                $this->processMessage($message, $data);
            }

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error('WhatsApp webhook error', [
                'error' => $this->errorDetail($e),
                'trace' => $e->getTraceAsString()
            ]);

            // Siempre retornar 200 para que Meta no reintente
            return response()->json(['status' => 'error'], 200);
        }
    }

    /**
     * Valida la firma X-Hub-Signature-256 = 'sha256=' + HMAC-SHA256(cuerpo
     * crudo, app_secret). Sin esto cualquiera podía forjar un webhook y mover
     * compras de estado.
     *
     * - Con app_secret configurado: se exige una firma válida (siempre).
     * - Sin app_secret: solo se permite en local/testing (comodidad de
     *   desarrollo); en cualquier otro entorno se rechaza.
     */
    protected function signatureIsValid(Request $request): bool
    {
        $secret = config('services.whatsapp.app_secret');

        if (empty($secret)) {
            return app()->environment('local', 'testing');
        }

        $header = $request->header('X-Hub-Signature-256', '');
        if (!is_string($header) || !str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /**
     * Process incoming message
     */
    protected function processMessage($message, $webhookData)
    {
        $from = $message['from'] ?? null;
        $messageId = $message['id'] ?? null;
        $messageType = $message['type'] ?? null;
        $messageText = $message['text']['body'] ?? null;

        if (!$from || !$messageText) {
            return;
        }

        Log::info('Processing WhatsApp message', [
            'message_id' => $messageId,
            'type' => $messageType,
        ]);

        // Buscar compras pendientes del proveedor que envió el mensaje
        // Compras v2 ordenadas del proveedor. Antes se consultaban las compras
        // del sistema anterior (tabla movements), que la app ya no crea: el
        // webhook nunca encontraba nada.
        $purchases = PurchaseV2::whereHas('supplier', function ($query) use ($from) {
            $formattedPhone = WhatsAppService::formatPhoneNumber($from);
            // Buscar por los últimos 10 dígitos (número sin código de país)
            $lastDigits = substr($formattedPhone, -10);
            // Solo dígitos: en el catálogo el teléfono puede venir como
            // "322 123 4567" o "(322) 123-4567" y no coincidía.
            $query->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ['%' . $lastDigits]);
        })
            ->where('status', PurchaseV2::STATUS_ORDERED)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($purchases->isEmpty()) {
            Log::info('No pending purchases found for WhatsApp message', ['message_id' => $messageId]);
            return;
        }

        // Detectar palabras clave en el mensaje
        $messageTextLower = strtolower($messageText);

        // Palabras clave para confirmar envío (más variaciones)
        $transitKeywords = [
            'enviado',
            'en camino',
            'despachado',
            'transito',
            'tránsito',
            'voy a enviar',
            'lo voy a mandar',
            'ya lo mando',
            'ya lo envio',
            'mañana lo envío',
            'hoy lo envio',
            'sale hoy',
            'sale mañana',
            'ya sale',
            'en proceso',
            'preparando',
            'saliendo'
        ];

        // Solo la compra más reciente. La recepción no se marca por WhatsApp:
        // recibir en v2 requiere capturar cantidades y mover stock, así que se
        // crea la tarea "Recibir compra" para hacerlo en la app.
        $purchase = $purchases->first();

        foreach ($transitKeywords as $keyword) {
            if (str_contains($messageTextLower, $keyword)) {
                $purchase->appendStatusLog(PurchaseV2::STATUS_IN_TRANSIT, null);
                $purchase->update(['status' => PurchaseV2::STATUS_IN_TRANSIT]);

                Log::info('Purchase marked as in_transit via WhatsApp', [
                    'purchase_id' => $purchase->id,
                    'message_id' => $messageId,
                    'keyword' => $keyword,
                ]);

                $this->createReceiveTask($purchase);
                return;
            }
        }

        // Si no se detectó ninguna palabra clave, solo registrar
        Log::info('WhatsApp message received but no action taken', [
            'message_id' => $messageId,
            'purchases_count' => $purchases->count()
        ]);
    }


    /**
     * Process message status updates (delivery, read, failed)
     */
    protected function processMessageStatus(array $statuses)
    {
        foreach ($statuses as $status) {
            $messageId = $status['id'] ?? null;
            $statusType = $status['status'] ?? null;

            // Sin recipient_id: es el teléfono del proveedor.
            Log::info('WhatsApp message status update', [
                'message_id' => $messageId,
                'status' => $statusType,
            ]);

            // Si el mensaje falló, registrar el error
            if ($statusType === 'failed' && isset($status['errors'])) {
                foreach ($status['errors'] as $error) {
                    Log::warning('WhatsApp message failed', [
                        'message_id' => $messageId,
                        'error_code' => $error['code'] ?? null,
                        'error_title' => $error['title'] ?? null,
                        'error_message' => $error['message'] ?? null,
                        'error_details' => $error['error_data']['details'] ?? null
                    ]);
                }
            }
        }
    }

    /**
     * Tarea "Recibir compra" (la creación de la tarea ya notifica a los
     * usuarios con permiso). Mismo paso que PurchaseV2Controller::markInTransit.
     */
    protected function createReceiveTask(PurchaseV2 $purchase): void
    {
        try {
            app(TaskService::class)->createFromPurchaseV2($purchase);
        } catch (\Throwable $e) {
            Log::error('Error creating purchase reception task', [
                'purchase_id' => $purchase->id,
                'error' => $this->errorDetail($e),
            ]);
        }

        // El proveedor avisó que ya la envió: aviso de "compra en camino".
        try {
            \App\Notifications\Templates\PurchaseUpdateTemplate::dispatchFor($purchase, 'in_transit');
        } catch (\Throwable $e) {
            Log::error('Error notifying purchase in transit', [
                'purchase_id' => $purchase->id,
                'error' => $this->errorDetail($e),
            ]);
        }
    }
}
