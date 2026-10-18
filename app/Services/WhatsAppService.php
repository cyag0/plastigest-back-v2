<?php

namespace App\Services;

use Netflie\WhatsAppCloudApi\WhatsAppCloudApi;
use Illuminate\Support\Facades\Log;

/**
 * Pedido al proveedor por WhatsApp Cloud API.
 *
 * APAGADO (WHATSAPP_ENABLED=false) hasta rehacerlo como bot. Lo que hay que
 * resolver antes de prenderlo:
 * - WhatsApp solo deja mandar texto libre dentro de las 24 h siguientes al
 *   último mensaje del proveedor; para iniciar la conversación (un pedido
 *   nuevo) exige una plantilla aprobada por Meta. sendTextMessage() fallaba
 *   en la práctica y el pedido quedaba "guardado" sin que nadie lo notara.
 * - El webhook adivina "ya lo envié" buscando palabras en el texto: «no lo voy
 *   a mandar» contiene «lo voy a mandar» y marcaba la compra en tránsito.
 *   Mejor botones (plantilla con respuestas rápidas) que traigan el id del
 *   pedido.
 * - El webhook busca la compra por el teléfono en todas las empresas.
 * - Teléfonos de México: el envío antepone 521 y algunos números ya van con 52.
 */
class WhatsAppService
{
    protected $whatsapp;
    protected $fromPhoneNumberId;

    /** Está prendido y hay credenciales de WhatsApp Cloud API. */
    public static function isConfigured(): bool
    {
        return self::isEnabled()
            && filled(config('services.whatsapp.access_token'))
            && filled(config('services.whatsapp.phone_number_id'));
    }

    /** WHATSAPP_ENABLED (apagado por omisión, ver arriba). */
    public static function isEnabled(): bool
    {
        return (bool) config('services.whatsapp.enabled', false);
    }

    /**
     * Número en formato internacional sin "+". Un número local de 10 dígitos
     * se toma como celular de México (521 + número).
     */
    public static function formatPhoneNumber(string $phone): string
    {
        $phone = ltrim(preg_replace('/[^0-9+]/', '', $phone), '+');

        return strlen($phone) === 10 ? '521' . $phone : $phone;
    }

    public function __construct()
    {
        $this->fromPhoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token');

        $this->whatsapp = new WhatsAppCloudApi([
            'from_phone_number_id' => $this->fromPhoneNumberId,
            'access_token' => $accessToken,
        ]);
    }

    /**
     * Send purchase order details to supplier via WhatsApp
     *
     * @param string $phoneNumber Phone number in format: 51987654321 (without +)
     * @param \App\Models\PurchaseV2 $purchase
     * @return bool
     */
    public function sendPurchaseOrder($phoneNumber, $purchase)
    {
        try {
            // Format message
            $message = $this->formatPurchaseMessage($purchase);

            // Send message
            $response = $this->whatsapp->sendTextMessage($phoneNumber, $message);

            Log::info('WhatsApp message sent', [
                'phone' => $phoneNumber,
                'purchase_id' => $purchase->id,
                'response' => $response
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('WhatsApp send error', [
                'phone' => $phoneNumber,
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Format purchase details into WhatsApp message
     */
    protected function formatPurchaseMessage($purchase)
    {
        $message = "🛒 *NUEVO PEDIDO - #{$purchase->purchase_number}*\n\n";

        if ($purchase->supplier) {
            $message .= "👤 *Proveedor:* {$purchase->supplier->name}\n";
        }

        // Formatear fecha (puede ser string o Carbon)
        $purchaseDate = $purchase->purchase_date;
        if (is_string($purchaseDate)) {
            $purchaseDate = \Carbon\Carbon::parse($purchaseDate);
        }
        $message .= "📅 *Fecha:* " . $purchaseDate->format('d/m/Y') . "\n";
        $message .= "📍 *Ubicación:* " . ($purchase->location->name ?? 'N/A') . "\n\n";

        $message .= "📦 *PRODUCTOS:*\n";
        $message .= "```\n";

        foreach ($purchase->details as $detail) {
            $productName = $detail->product->name ?? 'Producto';
            $quantity = number_format($detail->quantity, 2);
            $unit = $detail->unit->abbreviation ?? 'und';
            $unitPrice = number_format($detail->unit_price, 2);
            $subtotal = number_format($detail->total, 2);

            $message .= sprintf(
                "• %s\n  %.2f %s x $%s = $%s\n",
                $productName,
                $detail->quantity,
                $unit,
                $unitPrice,
                $subtotal
            );
        }

        $message .= "```\n\n";
        $message .= "💰 *TOTAL:* $" . number_format($purchase->total, 2) . "\n\n";

        if ($purchase->notes) {
            $message .= "📝 *Notas:* {$purchase->notes}\n\n";
        }

        $message .= "Por favor, confirme la recepción de este pedido.";

        return $message;
    }

    /**
     * Send simple text message
     */
    public function sendMessage($phoneNumber, $message)
    {
        try {
            $response = $this->whatsapp->sendTextMessage($phoneNumber, $message);

            Log::info('WhatsApp message sent', [
                'phone' => $phoneNumber,
                'response' => $response
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('WhatsApp send error', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}
