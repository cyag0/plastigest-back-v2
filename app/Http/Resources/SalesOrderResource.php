<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;

class SalesOrderResource extends Resources
{
    protected function formatter(Model $resource, array $data, array $context): array
    {
        $editing = $this->getContext('editing', false);

        $item = [
            'id' => $resource->id,
            'order_number' => $resource->order_number,
            'order_date' => $resource->order_date,
            'channel' => $resource->channel?->value,
            'channel_label' => $resource->channel?->label(),
            'service_mode' => $resource->service_mode?->value,
            'service_mode_label' => $resource->service_mode?->label(),
            'status' => $resource->status?->value,
            'status_label' => $resource->status?->label(),
            'status_color' => $resource->status?->color(),
            'customer_id' => $resource->customer_id,
            'customer_name' => $resource->customer_name_snapshot,
            'customer_phone' => $resource->customer_phone_snapshot,
            'customer_email' => $resource->customer_email_snapshot,
            'promised_at' => $resource->promised_at?->toISOString(),
            'prepared_at' => $resource->prepared_at?->toISOString(),
            'shipped_at' => $resource->shipped_at?->toISOString(),
            'delivered_at' => $resource->delivered_at?->toISOString(),
            'cancelled_at' => $resource->cancelled_at?->toISOString(),
            'reserved_at' => $resource->reserved_at?->toISOString(),
            'sale_id' => $resource->sale_id,
            'subtotal' => $resource->subtotal,
            'tax_amount' => $resource->tax_amount,
            'discount_amount' => $resource->discount_amount,
            'total_amount' => $resource->total_amount,
            'notes' => $resource->notes,
            'internal_notes' => $editing ? $resource->internal_notes : null,
            'content' => $resource->content,
        ];

        if ($resource->relationLoaded('location') && $resource->location) {
            $item['location'] = [
                'id' => $resource->location->id,
                'name' => $resource->location->name,
            ];
        }

        if ($resource->relationLoaded('customer') && $resource->customer) {
            $item['customer'] = [
                'id' => $resource->customer->id,
                'name' => $resource->customer->name,
                'phone' => $resource->customer->phone,
                'email' => $resource->customer->email,
            ];
        }

        if ($resource->relationLoaded('sale') && $resource->sale) {
            $item['sale'] = [
                'id' => $resource->sale->id,
                'sale_number' => $resource->sale->sale_number,
                'status' => $resource->sale->status?->value,
            ];
        }

        if ($resource->relationLoaded('details')) {
            $item['details'] = SalesOrderDetailResource::collection($resource->details);
        }

        return $item;
    }
}