<?php

namespace App\Support\Activity;

use App\Models\InventoryAdjustmentDetail;
use App\Models\InventoryCount;
use App\Models\InventoryTransfer;
use App\Models\Operations\ProductionOrder;
use App\Models\PurchaseV2;
use App\Models\Sale;
use Illuminate\Support\Collection;

/**
 * Folio legible del documento que originó un movimiento de inventario
 * ("Venta SAL-20261014-0003", "Compra PUR000051"…). Se resuelve en lote, con
 * una consulta por tipo de documento, para no hacer una por renglón.
 */
class ReferenceLabels
{
    /** Clase → [prefijo visible, columna con el folio]. */
    private const SOURCES = [
        Sale::class => ['Venta', 'sale_number'],
        PurchaseV2::class => ['Compra', 'purchase_number'],
        InventoryTransfer::class => ['Transferencia', 'transfer_number'],
        ProductionOrder::class => ['Producción', 'folio'],
        InventoryCount::class => ['Conteo', 'name'],
        InventoryAdjustmentDetail::class => ['Ajuste', null],
    ];

    /**
     * Agrega el atributo `reference_label` a cada movimiento.
     *
     * @param iterable<\App\Models\StockLedgerEntry> $entries
     */
    public static function attach(iterable $entries): void
    {
        $entries = Collection::wrap($entries);
        $labels = [];

        foreach ($entries->whereNotNull('reference_type')->groupBy('reference_type') as $type => $group) {
            [$prefix, $column] = self::SOURCES[$type] ?? [null, null];
            if (!$prefix) {
                continue;
            }

            $ids = $group->pluck('reference_id')->filter()->unique()->values();
            $folios = $column ? $type::whereIn('id', $ids)->pluck($column, 'id') : collect();

            foreach ($ids as $id) {
                $folio = $folios[$id] ?? null;
                $labels["{$type}:{$id}"] = $folio ? "{$prefix} {$folio}" : "{$prefix} #{$id}";
            }
        }

        foreach ($entries as $entry) {
            $entry->setAttribute('reference_label', $labels["{$entry->reference_type}:{$entry->reference_id}"] ?? null);
        }
    }
}
