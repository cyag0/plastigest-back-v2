<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Models\Admin\Location;
use App\Models\InventoryTransfer;
use App\Models\Product;
use Illuminate\Http\UploadedFile;

/**
 * Transferencias armadas por la API como las arma la app. Las hace el
 * administrador del contexto ($this->user, acceso a todas las sucursales),
 * para que las pruebas de permisos por sucursal partan de datos válidos.
 *
 * Requiere CreatesTenant y Storage::fake('public') (evidencias).
 */
trait BuildsTransfers
{
    /** $destination le pide $qty piezas de $product a $origin. */
    protected function requestTransfer(Location $origin, Location $destination, Product $product, float $qty): InventoryTransfer
    {
        $id = $this->actingInBranch($this->user, $destination)
            ->postJson('/api/auth/admin/inventory-transfers', [
                'from_location_id' => $origin->id,
                'to_location_id' => $destination->id,
                'details' => [[
                    'product_id' => $product->id,
                    'unit_id' => $product->unit_id,
                    'quantity_requested' => $qty,
                    'unit_cost' => 10,
                ]],
            ])->assertCreated()->json('data.id');

        return InventoryTransfer::with('details')->findOrFail($id);
    }

    protected function approveTransfer(InventoryTransfer $transfer): InventoryTransfer
    {
        $this->actingInBranch($this->user, $transfer->fromLocation)
            ->postJson("/api/auth/admin/inventory-transfers/{$transfer->id}/approve", [])
            ->assertOk();

        return $transfer->fresh('details');
    }

    /** Aprueba y envía todo lo solicitado del primer renglón. */
    protected function shipTransfer(InventoryTransfer $transfer, ?float $qty = null): InventoryTransfer
    {
        $transfer = $this->approveTransfer($transfer);
        $detail = $transfer->details->first();

        $this->actingInBranch($this->user, $transfer->fromLocation)
            ->post("/api/auth/admin/inventory-transfers/{$transfer->id}/ship", [
                'items' => [['detail_id' => $detail->id, 'quantity_shipped' => $qty ?? (float) $detail->quantity_requested]],
                'evidence' => [UploadedFile::fake()->image('camion.jpg')],
            ])->assertOk();

        return $transfer->fresh('details');
    }

    /** Payload de recepción completa (todo lo enviado llegó). */
    protected function fullReceivePayload(InventoryTransfer $transfer): array
    {
        return [
            'items' => $transfer->details->map(fn($detail) => [
                'detail_id' => $detail->id,
                'quantity_received' => (float) $detail->quantity_shipped,
            ])->values()->all(),
        ];
    }
}
