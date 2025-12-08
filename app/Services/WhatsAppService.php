<?php

namespace App\Services;

use Netflie\WhatsAppCloudApi\WhatsAppCloudApi;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected $whatsapp;
    protected $fromPhoneNumberId;

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
     * @param \App\Models\Purchase $purchase
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
            $unitPrice = number_format($detail->unit_cost, 2);
            $subtotal = number_format($detail->total_cost, 2);

            $message .= sprintf(
                "• %s\n  %.2f %s x S/ %s = S/ %s\n",
                $productName,
                $detail->quantity,
                $unit,
                $unitPrice,
                $subtotal
            );
        }

        $message .= "```\n\n";
        $message .= "💰 *TOTAL:* S/ " . number_format($purchase->total_cost, 2) . "\n\n";

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
