<?php

namespace App\Http\Resources;

use App\Models\CashRegisterSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashRegisterSession
 */
class CashRegisterSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn() => $this->location?->name),
            'cash_register_id' => $this->cash_register_id,
            'cash_register_name' => $this->whenLoaded('cashRegister', fn() => $this->cashRegister?->name),
            'cash_register_code' => $this->whenLoaded('cashRegister', fn() => $this->cashRegister?->code),
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn() => $this->user?->name),
            'opened_at' => $this->opened_at?->toISOString(),
            'opening_amount' => (float) $this->opening_amount,
            'closed_at' => $this->closed_at?->toISOString(),
            'closed_by_name' => $this->whenLoaded('closedBy', fn() => $this->closedBy?->name),
            'expected_cash' => $this->expected_cash !== null ? (float) $this->expected_cash : null,
            'counted_cash' => $this->counted_cash !== null ? (float) $this->counted_cash : null,
            'difference' => $this->difference !== null ? (float) $this->difference : null,
            // Al cerrar se congela el resumen; abierto, el controlador lo manda aparte en vivo.
            'totals' => $this->totals,
            'notes' => $this->notes,
        ];
    }
}
