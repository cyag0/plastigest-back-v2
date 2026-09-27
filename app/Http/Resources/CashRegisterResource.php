<?php

namespace App\Http\Resources;

use App\Models\CashRegister;
use Illuminate\Database\Eloquent\Model;

class CashRegisterResource extends Resources
{
    /**
     * @param CashRegister $resource
     */
    public function formatter(Model $resource, array $data, array $context): array
    {
        $item = [
            'id' => $resource->id,
            'company_id' => $resource->company_id,
            'location_id' => $resource->location_id,
            'location_name' => $resource->relationLoaded('location') ? $resource->location?->name : null,
            'name' => $resource->name,
            'code' => $resource->code,
            'is_active' => (bool) $resource->is_active,
            'notes' => $resource->notes,
        ];

        // Quién la tiene abierta ahora: el POS lo usa para ofrecer solo las cajas libres.
        if ($resource->relationLoaded('openSession')) {
            $open = $resource->openSession;
            $item['open_session'] = $open ? [
                'id' => $open->id,
                'user_id' => $open->user_id,
                'user_name' => $open->relationLoaded('user') ? $open->user?->name : null,
                'opened_at' => $open->opened_at?->toISOString(),
            ] : null;
        }

        return $item;
    }
}
