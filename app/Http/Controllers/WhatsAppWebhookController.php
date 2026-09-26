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

        // El verify token lo defines tú en Meta Dashboard
        $verifyToken = config('services.whatsapp.verify_token', 'plastigest_webhook_token');

        if ($mode === 'subscribe' && $token === $verifyToken) {
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
        try {
            $data = $request->all();

            Log::info('WhatsApp webhook received', ['data' => $data]);

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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Siempre retornar 200 para que Meta no reintente
            return response()->json(['status' => 'error'], 200);
        }
    }

    /**
     * Process incoming message
     */
    protected function processMessage($message, $webhookData)
    {
        $from = $message['from'] ?? null;
        $messageType = $message['type'] ?? null;
        $messageText = $message['text']['body'] ?? null;

        if (!$from || !$messageText) {
            return;
        }

        Log::info('Processing WhatsApp message', [
            'from' => $from,
            'text' => $messageText
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

        Log::info('Searching purchases for phone', [
            'from' => $from,
            'formatted' => WhatsAppService::formatPhoneNumber($from),
            'last_digits' => substr(WhatsAppService::formatPhoneNumber($from), -10)
        ]);

        if ($purchases->isEmpty()) {
            Log::info('No pending purchases found for phone', ['phone' => $from]);
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

        Log::info("compras", ['count' => $purchases->count()]);

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
                    'keyword' => $keyword,
                    'message' => $messageText,
                ]);

                $this->createReceiveTask($purchase);
                return;
            }
        }

        // Si no se detectó ninguna palabra clave, solo registrar
        Log::info('WhatsApp message received but no action taken', [
            'from' => $from,
            'text' => $messageText,
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
            $recipientId = $status['recipient_id'] ?? null;

            Log::info('WhatsApp message status update', [
                'message_id' => $messageId,
                'status' => $statusType,
                'recipient_id' => $recipientId,
                'errors' => $status['errors'] ?? null
            ]);

            // Si el mensaje falló, registrar el error
            if ($statusType === 'failed' && isset($status['errors'])) {
                foreach ($status['errors'] as $error) {
                    Log::warning('WhatsApp message failed', [
                        'message_id' => $messageId,
                        'recipient' => $recipientId,
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
                'error' => $e->getMessage(),
            ]);
        }
    }
}
