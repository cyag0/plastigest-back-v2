<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Cómo se mide un producto (su unidad base, el factor y el tipo de las
 * unidades, el contenido de sus presentaciones) no cambia una vez que hay
 * stock o documentos que dependen de esa medida.
 *
 * La existencia y el libro mayor están en la unidad base del producto y no se
 * reescalan, y MovementService convierte cada movimiento con la definición
 * vigente en ese momento, también al recibir una transferencia o al cancelar
 * una compra, una producción o una venta. Cambiar la medida a medio camino
 * descuadra el inventario: 500 g que se vuelven 500 kg, una caja de 10 que
 * regresa como caja de 12.
 *
 * Un producto nuevo (sin existencia, sin movimientos y sin documentos
 * abiertos) sí se puede corregir.
 */
class StockDefinitionGuard
{
    private const OPEN_PURCHASES = ['draft', 'ordered', 'in_transit'];
    private const OPEN_TRANSFERS = ['pending', 'approved', 'in_transit'];

    public function assertProductUnitCanChange(Product $product): void
    {
        $reason = $this->productsUsage([$product->id]);
        if ($reason === null) {
            return;
        }

        throw new BusinessRuleException(
            "No se puede cambiar la unidad base de {$product->name}: {$reason}, y esas cantidades están en "
            . ($product->unit?->name ?? 'su unidad actual') . '. Crea un producto nuevo con la unidad correcta y desactiva este.',
            'product_unit_locked'
        );
    }

    public function assertPackageCanChange(ProductPackage $package): void
    {
        $reason = $this->productsUsage([$package->product_id]);
        if ($reason === null) {
            return;
        }

        throw new BusinessRuleException(
            "No se puede cambiar el contenido de la presentación {$package->package_name}: el producto {$reason} y se "
            . 'recalcularía mal. Crea una presentación nueva con el contenido correcto y desactiva esta.',
            'package_in_use'
        );
    }

    public function assertUnitCanChange(Unit $unit): void
    {
        $reason = $this->productsUsage(DB::table('products')->where('unit_id', $unit->id)->select('id'))
            ? 'la usan como unidad base productos con existencia, movimientos o documentos abiertos'
            : $this->unitInDocuments($unit->id);

        if ($reason === null) {
            return;
        }

        throw new BusinessRuleException(
            "No se puede cambiar el factor ni el tipo de la unidad {$unit->name}: {$reason}, y sus cantidades se "
            . 'recalcularían mal. Crea una unidad nueva con el factor correcto.',
            'unit_in_use'
        );
    }

    /**
     * Por qué alguno de los productos ya no puede cambiar de medida, o null
     * si todos son nuevos.
     *
     * @param array<int>|\Illuminate\Database\Query\Builder $productIds Ids o subconsulta que los selecciona.
     */
    public function productsUsage($productIds): ?string
    {
        if (DB::table('product_location')->whereIn('product_id', $productIds)->where('current_stock', '!=', 0)->exists()) {
            return 'tiene existencia en alguna sucursal';
        }

        if (DB::table('stock_ledger_entries')->whereIn('product_id', $productIds)->exists()) {
            return 'ya tiene movimientos de inventario';
        }

        $purchases = DB::table('purchase_details')
            ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
            ->whereIn('purchase_details.product_id', $productIds)
            ->whereIn('purchases.status', self::OPEN_PURCHASES);
        if ($purchases->exists()) {
            return 'está en compras sin recibir';
        }

        $transfers = DB::table('inventory_transfer_details')
            ->join('inventory_transfers', 'inventory_transfers.id', '=', 'inventory_transfer_details.transfer_id')
            ->whereIn('inventory_transfer_details.product_id', $productIds)
            ->whereIn('inventory_transfers.status', self::OPEN_TRANSFERS);
        if ($transfers->exists()) {
            return 'está en transferencias sin recibir';
        }

        foreach (['production_order_consumptions', 'production_order_outputs'] as $table) {
            $production = DB::table($table)
                ->join('production_orders', 'production_orders.id', '=', "{$table}.production_order_id")
                ->whereIn("{$table}.product_id", $productIds)
                ->where('production_orders.status', 'draft');
            if ($production->exists()) {
                return 'está en producciones en borrador';
            }
        }

        return null;
    }

    /**
     * Documentos que guardan cantidades en esta unidad y todavía pueden mover
     * stock con ella (recibir, enviar, producir o cancelar).
     */
    private function unitInDocuments(int $unitId): ?string
    {
        $purchases = DB::table('purchase_details')
            ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
            ->where('purchase_details.unit_id', $unitId)
            ->whereIn('purchases.status', self::OPEN_PURCHASES);
        if ($purchases->exists()) {
            return 'está en compras sin recibir';
        }

        $transfers = DB::table('inventory_transfer_details')
            ->join('inventory_transfers', 'inventory_transfers.id', '=', 'inventory_transfer_details.transfer_id')
            ->where('inventory_transfer_details.unit_id', $unitId)
            ->whereIn('inventory_transfers.status', self::OPEN_TRANSFERS);
        if ($transfers->exists()) {
            return 'está en transferencias sin recibir';
        }

        // Una producción terminada todavía se puede cancelar (y se revierte
        // convirtiendo con la unidad).
        foreach (['production_order_consumptions', 'production_order_outputs'] as $table) {
            $production = DB::table($table)
                ->join('production_orders', 'production_orders.id', '=', "{$table}.production_order_id")
                ->where("{$table}.unit_id", $unitId)
                ->where('production_orders.status', '!=', 'cancelled');
            if ($production->exists()) {
                return 'la usan producciones que todavía se pueden cancelar';
            }
        }

        // Igual una venta que no se ha cancelado.
        $sales = DB::table('sales_details')
            ->join('sales', 'sales.id', '=', 'sales_details.sale_id')
            ->where('sales_details.unit_id', $unitId)
            ->where('sales.status', '!=', 'cancelled');
        if ($sales->exists()) {
            return 'la usan ventas que todavía se pueden cancelar';
        }

        return null;
    }
}
