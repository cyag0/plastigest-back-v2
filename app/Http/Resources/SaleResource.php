<?php

namespace App\Http\Resources;

use App\Http\Resources\Resources;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;

class SaleResource extends Resources
{
    /**
     * Format the resource data
     *
     * @param Sale $resource
     * @param array $data
     * @param array $context
     * @return array
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            'sale_number' => $resource->sale_number ?? $resource->formatted_sale_number,
            'sale_date' => $resource->sale_date?->format('Y-m-d'),
            'status' => $resource->status->value,
            'status_label' => $resource->status->label(),
            'status_color' => $resource->status->color(),
            'can_cancel' => $resource->status === \App\Enums\SaleStatus::CLOSED,
            'total' => $resource->total,
            'subtotal' => $resource->subtotal,
            'tax' => $resource->tax,
            'discount' => $resource->discount,
            'payment_method' => $resource->payment_method,
            'payment_status' => $resource->payment_status,
            'paid_amount' => $resource->paid_amount,
            'content' => $resource->content,
            'created_at' => $resource->created_at?->toISOString(),
            // Para el detalle: quién atendió, en qué caja y cómo se pagó.
            'user_name' => $resource->relationLoaded('user') ? $resource->user?->name : null,
            'cash_register_name' => $resource->relationLoaded('cashRegister') ? $resource->cashRegister?->name : null,
            'payments' => $resource->relationLoaded('payments')
                ? $resource->payments->map(fn($p) => [
                    'method' => $p->method,
                    'amount' => (float) $p->amount,
                    'tendered' => $p->tendered !== null ? (float) $p->tendered : null,
                    'change' => $p->change !== null ? (float) $p->change : null,
                ])->values()
                : [],
        ];

        // Información del cliente (si existe)
        $customerInfo = $resource->customer_info;
        if ($customerInfo['name'] || $customerInfo['phone'] || $customerInfo['email']) {
            $item['customer_name'] = $customerInfo['name'];
            $item['customer_phone'] = $customerInfo['phone'];
            $item['customer_email'] = $customerInfo['email'];
        }

        // Detalles de la venta
        if ($resource->relationLoaded('details')) {
            $item['details'] = $resource->details->map(function ($detail) use ($editing) {
                $detailData = [
                    'id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'package_id' => $detail->package_id,
                    'unit_id' => $detail->unit_id,
                    'quantity' => $detail->quantity,
                    'unit_price' => $detail->unit_price,
                    'subtotal' => $detail->subtotal,
                    'tax' => $detail->tax,
                    'discount' => $detail->discount,
                    'total' => $detail->total,
                    'unit_abbreviation' => $detail->relationLoaded('unit') ? $detail->unit?->abbreviation : null,
                    'package_name' => $detail->relationLoaded('package') ? $detail->package?->package_name : null,
                ];

                // Información del producto
                if ($detail->relationLoaded('product')) {
                    $detailData['product_name'] = $detail->product->name;
                    $detailData['product_code'] = $detail->product->code;

                    $product = (new ProductResource($detail->product))->formatter($detail->product, [], []);

                    if (isset($product["main_image"]) && isset($product["main_image"]["uri"])) {
                        $detailData['product_image'] = $product["main_image"]["uri"];
                    }

                    //$detailData['product_image'] = $product->;
                }

                return $detailData;
            });
        }

        // Ubicación
        if ($resource->relationLoaded('location')) {
            if (!$editing) {
                $item['location_name'] = $resource->location?->name;
            } else {
                $item['location'] = $resource->location;
            }
        }

        // Campos adicionales según el contexto
        if ($editing) {
            $item['notes'] = $resource->notes;
            $item['location_id'] = $resource->location_id;
            $item['created_at'] = $resource->created_at?->toISOString();
            $item['updated_at'] = $resource->updated_at?->toISOString();
        }

        return $item;
    }
}
