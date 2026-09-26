<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un renglón del historial de un producto, ya listo para pintar.
 *
 * La app no debería tener que saber qué modelo de Laravel originó el
 * movimiento, así que el documento de origen se resuelve aquí a un tipo corto
 * y una etiqueta legible.
 */
class StockLedgerEntryResource extends JsonResource
{
    /** Nombre de clase → tipo corto que entiende la app. */
    private const REFERENCE_TYPES = [
        \App\Models\Sale::class => 'sale',
        \App\Models\PurchaseV2::class => 'purchase',
        \App\Models\InventoryTransfer::class => 'transfer',
        \App\Models\InventoryCount::class => 'count',
        \App\Models\InventoryAdjustmentDetail::class => 'adjustment',
        \App\Models\Operations\ProductionOrder::class => 'production_order',
    ];

    public function toArray(Request $request): array
    {
        $this->loadMissing(['location', 'counterpartLocation', 'user']);

        return [
            'id' => $this->id,
            'date' => $this->created_at?->toISOString(),

            'direction' => $this->direction,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'reason_icon' => $this->reason->icon(),

            // Positiva para entradas, negativa para salidas: la app la pinta tal cual.
            'quantity' => (float) $this->quantity,
            'signed_quantity' => $this->signed_quantity,
            'previous_stock' => (float) $this->previous_stock,
            'new_stock' => (float) $this->new_stock,
            'unit_cost' => $this->unit_cost !== null ? (float) $this->unit_cost : null,

            'location' => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null,

            'counterpart_location' => $this->counterpartLocation ? [
                'id' => $this->counterpartLocation->id,
                'name' => $this->counterpartLocation->name,
            ] : null,

            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null,

            'reference' => $this->reference_type ? [
                'type' => self::REFERENCE_TYPES[$this->reference_type] ?? 'other',
                'id' => $this->reference_id,
            ] : null,

            'notes' => $this->notes,
        ];
    }
}
