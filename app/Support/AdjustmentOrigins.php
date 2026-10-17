<?php

namespace App\Support;

use App\Models\InventoryAdjustmentDetail;
use App\Models\InventoryCount;
use App\Models\InventoryTransfer;
use App\Models\Operations\ProductionOrder;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Lo que la pantalla de ajustes muestra de otros registros: el documento del
 * que salió cada ajuste ("Orden PROD-2026-0012", "Conteo «Semanal»",
 * "Transferencia TRANS-20261017-0003") y quién lo deshizo.
 *
 * Se resuelve en lote, con una consulta por tipo de documento, para no hacer
 * una por renglón del listado. Queda en cada ajuste como relación
 * (originDocument, revertedByUser) y no como atributo: así no se intenta
 * guardar si el ajuste se vuelve a guardar.
 */
final class AdjustmentOrigins
{
    /** Tipo de origen → [modelo, columna con su folio o nombre]. */
    private const DOCUMENTS = [
        InventoryAdjustmentDetail::ORIGIN_PRODUCTION_ORDER => [ProductionOrder::class, 'folio'],
        InventoryAdjustmentDetail::ORIGIN_INVENTORY_COUNT => [InventoryCount::class, 'name'],
        InventoryAdjustmentDetail::ORIGIN_TRANSFER => [InventoryTransfer::class, 'transfer_number'],
    ];

    /**
     * Carga el documento de origen y quién lo deshizo de los ajustes que aún
     * no los tienen.
     *
     * @param iterable<InventoryAdjustmentDetail>|InventoryAdjustmentDetail $details
     */
    public static function load(iterable|InventoryAdjustmentDetail $details): void
    {
        $pending = Collection::wrap($details)
            ->reject(fn(InventoryAdjustmentDetail $detail) => $detail->relationLoaded('originDocument'));

        if ($pending->isEmpty()) {
            return;
        }

        $documents = [];
        foreach (self::DOCUMENTS as $type => [$model, $column]) {
            $ids = $pending->filter(fn(InventoryAdjustmentDetail $d) => $d->originType() === $type)
                ->map(fn(InventoryAdjustmentDetail $d) => $d->originId())
                ->filter()->unique()->values();

            $documents[$type] = $ids->isEmpty()
                ? collect()
                : $model::query()->whereIn('id', $ids)->get(['id', $column])->keyBy('id');
        }

        $userIds = $pending->map(fn(InventoryAdjustmentDetail $d) => $d->revertedById())->filter()->unique()->values();
        $users = $userIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        foreach ($pending as $detail) {
            $documentId = $detail->originId();
            $userId = $detail->revertedById();

            $detail->setRelation('originDocument', $documentId ? ($documents[$detail->originType()] ?? collect())->get($documentId) : null);
            $detail->setRelation('revertedByUser', $userId ? $users->get($userId) : null);
        }
    }

    /**
     * {type, id, label} del origen. El label lleva el folio o el nombre del
     * documento ("Orden PROD-2026-0012", "Conteo «Semanal 14 oct»",
     * "Transferencia TRANS-20261017-0031") o su número si ya no existe; es
     * null en los manuales.
     *
     * @return array{type: string, id: int|null, label: string|null}
     */
    public static function origin(InventoryAdjustmentDetail $detail): array
    {
        self::load($detail);

        $type = $detail->originType();
        $id = $detail->originId();
        $document = $detail->getRelation('originDocument');

        $label = match (true) {
            $id === null => null,
            $type === InventoryAdjustmentDetail::ORIGIN_PRODUCTION_ORDER => 'Orden ' . ($document?->folio ?: "#{$id}"),
            $type === InventoryAdjustmentDetail::ORIGIN_INVENTORY_COUNT => $document?->name ? "Conteo «{$document->name}»" : "Conteo #{$id}",
            $type === InventoryAdjustmentDetail::ORIGIN_TRANSFER => 'Transferencia ' . ($document?->transfer_number ?: "#{$id}"),
            default => null,
        };

        return ['type' => $type, 'id' => $id, 'label' => $label];
    }

    /**
     * Quién lo deshizo (o revirtió la merma al cancelar la orden), o null.
     *
     * @return array{id: int, name: string}|null
     */
    public static function revertedBy(InventoryAdjustmentDetail $detail): ?array
    {
        self::load($detail);

        $user = $detail->getRelation('revertedByUser');

        return $user ? ['id' => (int) $user->id, 'name' => (string) $user->name] : null;
    }
}
