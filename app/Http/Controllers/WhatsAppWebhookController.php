<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Task;
use App\Notifications\NotificationEngine;
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
        $purchases = Purchase::whereHas('supplier', function ($query) use ($from) {
            $formattedPhone = $this->formatPhoneNumber($from);
            // Buscar por los últimos 10 dígitos (número sin código de país)
            $lastDigits = substr($formattedPhone, -10);
            $query->where('phone', 'like', '%' . $lastDigits . '%');
        })
            ->whereIn('status', ['ordered'])
            ->orderBy('created_at', 'desc')
            ->get();

        Log::info('Searching purchases for phone', [
            'from' => $from,
            'formatted' => $this->formatPhoneNumber($from),
            'last_digits' => substr($this->formatPhoneNumber($from), -10)
        ]);

        if ($purchases->isEmpty()) {
            Log::info('No pending purchases found for phone', ['phone' => $from]);
            return;
        }

        // Detectar palabras clave en el mensaje
        $messageTextLower = strtolower($messageText);

        // Palabras clave para confirmar recepción
        $receivedKeywords = ['recibido', 'entregado', 'llegó', 'llego', 'completado'];

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

        foreach ($purchases as $purchase) {
            // Limpiar espacios en blanco del status
            /** @var \App\Models\Purchase $purchase */
            $purchase = $purchase;

            $currentStatus = $purchase->status->value;

            Log::info('Evaluating purchase for status update', [
                'purchase_id' => $purchase->id,
                'current_status' => $currentStatus,
            ]);

            // Si está "ordered" y el mensaje indica que fue enviado
            if ($currentStatus === 'ordered') {
                Log::info("evaluando palabras de tránsito", [
                    'transit_keywords' => $transitKeywords,
                    'message_text' => $messageTextLower
                ]);

                foreach ($transitKeywords as $keyword) {
                    if (str_contains($messageTextLower, $keyword)) {
                        Log::info("palabra de tránsito detectada: " . $keyword);

                        $purchase->update(['status' => 'in_transit']);

                        Log::info('Purchase marked as in_transit via WhatsApp', [
                            'purchase_id' => $purchase->id,
                            'message' => $messageText
                        ]);

                        // Enviar notificación de que el producto fue enviado
                        $this->notifyPurchaseInTransit($purchase);

                        return; // Solo procesar la compra más reciente
                    }
                }
            }

            // Si está "in_transit" y el mensaje indica que fue recibido
            if ($currentStatus === 'in_transit') {
                foreach ($receivedKeywords as $keyword) {
                    if (str_contains($messageTextLower, $keyword)) {
                        $purchase->update(['status' => 'received']);
                        Log::info('Purchase marked as received via WhatsApp', [
                            'purchase_id' => $purchase->id,
                            'message' => $messageText
                        ]);

                        // Enviar notificación de que el producto fue recibido
                        $this->notifyPurchaseReceived($purchase);

                        return; // Solo procesar la compra más reciente
                    }
                }
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
     * Format phone number
     */
    protected function formatPhoneNumber($phone)
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        $phone = ltrim($phone, '+');

        // Si tiene 10 dígitos (número local mexicano), agregar código de país
        if (strlen($phone) === 10) {
            $phone = '521' . $phone; // 52 (México) + 1 (celular)
        }

        return $phone;
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
     * Create reception task for purchase in transit
     * Note: Task creation automatically sends notification, no need for duplicate notification
     */
    protected function notifyPurchaseInTransit(Purchase $purchase)
    {
        try {
            // Crear tarea automática para recibir la compra
            // La creación de la tarea ya envía una notificación a los usuarios con permiso
            (new PurchaseController())->createReceivePurchaseTask($purchase);

            Log::info('Purchase in transit - task created and notification sent', [
                'purchase_id' => $purchase->id
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating purchase reception task', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify users that a purchase was received
     */
    protected function notifyPurchaseReceived(Purchase $purchase)
    {
        try {
            $purchase->load(['supplier', 'details.product']);

            $products = $purchase->details->map(function ($detail) {
                return [
                    'name' => $detail->product->name,
                    'quantity' => $detail->quantity,
                ];
            })->toArray();

            // Enviar notificación usando NotificationEngine
            NotificationEngine::dispatch('purchase_update', $purchase->company_id, [
                'purchase'      => $purchase,
                'supplier_name' => $purchase->supplier->name,
                'sub_type'      => 'received',
                'products'      => $products,
            ]);

            Log::info('Purchase received notification sent', [
                'purchase_id' => $purchase->id
            ]);
        } catch (\Exception $e) {
            Log::error('Error notifying purchase received', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Crear tarea automática para recibir la compra
     */
    protected function createReceivePurchaseTask(Purchase $purchase)
    {
        try {
            $productsList = $purchase->details->map(function ($detail) {
                return "- {$detail->product->name} (x{$detail->quantity})";
            })->join("\n");

            $task = Task::create([
                'title' => "Recibir Compra #{$purchase->id} - {$purchase->supplier->name}",
                'description' => "Verificar y recibir los siguientes productos:\n\n{$productsList}\n\nProveedor: {$purchase->supplier->name}\nReferencia: {$purchase->reference}",
                'type' => 'stock_check',
                'priority' => 'high',
                'status' => 'pending',
                'due_date' => now()->addDays(2), // 2 días para recibir
                'company_id' => $purchase->company_id,
                'location_id' => $purchase->location_id,
                'assigned_users' => [], // Se asignará automáticamente a usuarios con permiso
                'is_recurring' => false,
            ]);

            Log::info('Receive purchase task created', [
                'task_id' => $task->id,
                'purchase_id' => $purchase->id,
            ]);

            // Notificar asignación de tarea a usuarios con permiso de compras
            $this->notifyPurchaseTaskAssignment($task, $purchase);
        } catch (\Exception $e) {
            Log::error('Error creating receive purchase task', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notificar asignación de tarea de recepción de compra
     */
    protected function notifyPurchaseTaskAssignment(Task $task, Purchase $purchase)
    {
        try {
            $purchase->load(['supplier', 'details.product']);

            // Notificar a usuarios con permiso de compras via NotificationEngine (task_event)
            NotificationEngine::dispatch('purchase_update', $purchase->company_id, [
                'purchase'      => $purchase,
                'supplier_name' => $purchase->supplier->name,
                'sub_type'      => 'in_transit',
                'products'      => $purchase->details->map(fn($d) => [
                    'name'     => $d->product->name,
                    'quantity' => $d->quantity,
                ])->toArray(),
            ]);

            Log::info('Purchase task assignment notification sent', [
                'task_id' => $task->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Error notifying purchase task assignment', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
