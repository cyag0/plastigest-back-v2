<?php

namespace App\Services;

use App\Enums\ProductionOrderStatus;
use App\Enums\StockLedgerReason;
use App\Models\Operations\ProductionOrder;
use App\Models\Operations\ProductionOrderPackaging;
use App\Models\Operations\ProductionOrderPackagingMaterial;
use App\Models\Product;
use App\Support\StockContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Paso de empaque de una orden de producción.
 *
 * El empaque es posterior a la producción a propósito: la fórmula entrega
 * granel ("Dulce de coco granel") y hasta que la orden no se completa ese
 * granel no existe en inventario. Sólo entonces se puede empacar, consumiendo
 * granel + material (frasco, tapa, etiqueta) para generar producto terminado.
 *
 * La receta de empaque vive en el producto terminado
 * (`packaging_source_product_id`, `packaging_source_quantity` y
 * `product_packaging_materials`); aquí se copia a la orden con las cantidades
 * reales, para que el histórico no cambie si después se edita el producto.
 */
class ProductionPackagingService
{
    public function __construct(private readonly MovementService $movementService) {}

    /**
     * Plan de empaque de la orden: qué se puede empacar de cada salida, cuánto
     * granel queda disponible y qué material haría falta.
     */
    public function getPlan(ProductionOrder $order): array
    {
        $status = $this->status($order);
        $order->loadMissing(['outputs.product', 'outputs.unit', 'packagings.materials']);

        $lines = [];

        foreach ($order->outputs as $output) {
            $candidates = $this->packageableProducts((int) $output->product_id, $order->company_id);

            if ($candidates->isEmpty()) {
                continue;
            }

            // Granel de esta salida que todavía no se ha empacado en esta orden.
            $producedBase = $this->toBaseUnit((int) $output->product_id, (int) $output->unit_id, (float) $output->quantity);
            $packedBase = $this->packedSourceBaseQuantity($order, (int) $output->product_id);
            $remainingBase = max(0, round($producedBase - $packedBase, 4));

            $lines[] = [
                'source_product_id' => (int) $output->product_id,
                'source_product_name' => $output->product?->name,
                'source_unit_id' => (int) $output->unit_id,
                'source_unit_name' => $output->unit?->name,
                'produced_quantity' => round($producedBase, 4),
                'packed_quantity' => round($packedBase, 4),
                'remaining_quantity' => $remainingBase,
                'available_stock' => $this->currentStock((int) $order->location_id, (int) $output->product_id),
                'products' => $candidates
                    ->map(fn(Product $product) => $this->describePackagingOption($product, $order, $remainingBase))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'can_pack' => $status->allowsPackaging(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'lines' => $lines,
            'packagings' => $this->describeRegistered($order),
        ];
    }

    /**
     * Registra empaques. Cada línea es `product_id` (terminado) + `quantity`
     * (unidades a empacar); el granel y el material salen de la receta del
     * producto salvo que la línea los sobrescriba.
     */
    public function pack(ProductionOrder $order, array $lines): ProductionOrder
    {
        $status = $this->status($order);

        if (!$status->allowsPackaging()) {
            throw new RuntimeException(
                $status === ProductionOrderStatus::DRAFT
                    ? 'La producción debe completarse antes de poder empacar.'
                    : "No se puede empacar una orden en estado {$status->label()}."
            );
        }

        $rows = array_values(array_filter(
            $lines,
            fn($row) => !empty($row['product_id']) && (float) ($row['quantity'] ?? 0) > 0
        ));

        if (!$rows) {
            throw new RuntimeException('No hay nada que empacar: indica al menos un producto y su cantidad.');
        }

        return DB::transaction(function () use ($order, $rows) {
            $locationId = (int) $order->location_id;
            $nextSort = (int) $order->packagings()->max('sort_order') + 1;

            foreach ($rows as $row) {
                $product = Product::with('packagingMaterials')->findOrFail((int) $row['product_id']);
                $quantity = (float) $row['quantity'];

                $sourceProductId = $row['source_product_id'] ?? $product->packaging_source_product_id;
                // Sólo se sobrescribe la receta si la línea manda un valor real:
                // un null suelto no debe convertirse en "no consume granel".
                $sourcePerUnit = isset($row['source_quantity_per_unit'])
                    ? (float) $row['source_quantity_per_unit']
                    : (float) ($product->packaging_source_quantity ?? 0);
                $sourceUnitId = $row['source_unit_id']
                    ?? $product->packaging_source_unit_id
                    ?? ($sourceProductId ? Product::whereKey($sourceProductId)->value('unit_id') : null);

                $sourceTotal = $sourceProductId ? round($sourcePerUnit * $quantity, 4) : null;

                $packaging = ProductionOrderPackaging::create([
                    'production_order_id' => $order->id,
                    'product_id' => $product->id,
                    'unit_id' => (int) ($row['unit_id'] ?? $product->unit_id),
                    'quantity' => $quantity,
                    'source_product_id' => $sourceProductId,
                    'source_unit_id' => $sourceProductId ? $sourceUnitId : null,
                    'source_quantity' => $sourceTotal,
                    'packed_at' => now(),
                    'packed_by' => Auth::id(),
                    'sort_order' => $nextSort++,
                    'notes' => $row['notes'] ?? null,
                ]);

                // 1. Consumir el granel del que se empaca.
                if ($sourceProductId && $sourceTotal > 0) {
                    $this->movementService->decrement(
                        $locationId,
                        (int) $sourceProductId,
                        (int) $sourceUnitId,
                        $sourceTotal,
                        null,
                        StockContext::for(StockLedgerReason::PACKAGING_CONSUMPTION, $order, 'Granel consumido al empacar')
                    );
                }

                // 2. Consumir el material de empaque.
                foreach ($this->materialsFor($product, $row) as $material) {
                    $materialQty = round((float) $material['quantity'] * $quantity, 4);
                    if ($materialQty <= 0) {
                        continue;
                    }

                    $this->movementService->decrement(
                        $locationId,
                        (int) $material['product_id'],
                        (int) $material['unit_id'],
                        $materialQty,
                        null,
                        StockContext::for(StockLedgerReason::PACKAGING_CONSUMPTION, $order, 'Material de empaque')
                    );

                    ProductionOrderPackagingMaterial::create([
                        'production_order_packaging_id' => $packaging->id,
                        'product_id' => (int) $material['product_id'],
                        'unit_id' => (int) $material['unit_id'],
                        'quantity' => $materialQty,
                    ]);
                }

                // 3. Dar de alta el producto terminado.
                $this->movementService->increment(
                    $locationId,
                    (int) $product->id,
                    (int) $packaging->unit_id,
                    $quantity,
                    null,
                    StockContext::for(StockLedgerReason::PACKAGING_OUTPUT, $order)
                );
            }

            if ($this->status($order) === ProductionOrderStatus::COMPLETED) {
                $order->status = ProductionOrderStatus::PACKAGING;
                $order->updated_by = Auth::id();
                $order->save();
            }

            return $order->fresh(['packagings.materials', 'outputs']);
        });
    }

    /**
     * Revierte un empaque: devuelve granel y material, y retira el terminado.
     */
    public function revert(ProductionOrder $order, ProductionOrderPackaging $packaging): ProductionOrder
    {
        $status = $this->status($order);

        if (!$status->allowsPackaging()) {
            throw new RuntimeException("No se puede revertir un empaque de una orden en estado {$status->label()}.");
        }

        if ((int) $packaging->production_order_id !== (int) $order->id) {
            throw new RuntimeException('El empaque no pertenece a esta orden de producción.');
        }

        return DB::transaction(function () use ($order, $packaging) {
            $this->reverseStock($order, $packaging);
            $packaging->materials()->delete();
            $packaging->delete();

            // Sin empaques la orden vuelve a estar sólo producida.
            if (!$order->packagings()->exists()) {
                $order->status = ProductionOrderStatus::COMPLETED;
                $order->packed_at = null;
                $order->updated_by = Auth::id();
                $order->save();
            }

            return $order->fresh(['packagings.materials', 'outputs']);
        });
    }

    /**
     * Cierra el empaque de la orden. Ya no se pueden registrar más empaques.
     */
    public function finish(ProductionOrder $order): ProductionOrder
    {
        $order->assertCanTransitionTo(ProductionOrderStatus::PACKED);

        $order->status = ProductionOrderStatus::PACKED;
        $order->packed_at = now();
        $order->updated_by = Auth::id();
        $order->save();

        return $order->fresh(['packagings.materials', 'outputs']);
    }

    /**
     * Revierte TODOS los empaques de la orden. Lo usa la cancelación de la
     * orden: primero se deshace el empaque y después la producción, en ese
     * orden, para que el granel exista cuando se devuelva.
     */
    public function revertAll(ProductionOrder $order): void
    {
        foreach ($order->packagings()->with('materials')->get() as $packaging) {
            $this->reverseStock($order, $packaging);
            $packaging->materials()->delete();
            $packaging->delete();
        }
    }

    // -----------------------------------------------------------------
    // Helpers privados
    // -----------------------------------------------------------------

    /**
     * Deshace el movimiento de stock de un empaque, en orden inverso al de
     * `pack()`: primero se retira el terminado y después se devuelven insumos.
     */
    private function reverseStock(ProductionOrder $order, ProductionOrderPackaging $packaging): void
    {
        $locationId = (int) $order->location_id;

        $this->movementService->decrement(
            $locationId,
            (int) $packaging->product_id,
            (int) $packaging->unit_id,
            (float) $packaging->quantity,
            null,
            StockContext::for(StockLedgerReason::PACKAGING_OUTPUT, $order, 'Reversa del empaque')
        );

        if ($packaging->source_product_id && (float) $packaging->source_quantity > 0) {
            $this->movementService->increment(
                $locationId,
                (int) $packaging->source_product_id,
                (int) $packaging->source_unit_id,
                (float) $packaging->source_quantity,
                null,
                StockContext::for(StockLedgerReason::PACKAGING_CONSUMPTION, $order, 'Granel devuelto al revertir el empaque')
            );
        }

        foreach ($packaging->materials as $material) {
            $this->movementService->increment(
                $locationId,
                (int) $material->product_id,
                (int) $material->unit_id,
                (float) $material->quantity,
                null,
                StockContext::for(StockLedgerReason::PACKAGING_CONSUMPTION, $order, 'Material devuelto al revertir el empaque')
            );
        }
    }

    /**
     * Material a consumir: el de la línea si viene, o la receta del producto.
     *
     * @return array<int, array{product_id:int, unit_id:int, quantity:float}>
     */
    private function materialsFor(Product $product, array $row): array
    {
        if (!empty($row['materials'])) {
            return array_values(array_filter(array_map(fn($m) => [
                'product_id' => (int) ($m['product_id'] ?? 0),
                'unit_id' => (int) ($m['unit_id'] ?? 0),
                'quantity' => (float) ($m['quantity'] ?? 0),
            ], $row['materials']), fn($m) => $m['product_id'] && $m['unit_id']));
        }

        return $product->packagingMaterials
            ->map(fn($m) => [
                'product_id' => (int) $m->material_product_id,
                'unit_id' => (int) $m->unit_id,
                'quantity' => (float) $m->quantity,
            ])
            ->all();
    }

    /**
     * Productos terminados que se empacan a partir de este granel.
     */
    private function packageableProducts(int $sourceProductId, ?int $companyId): Collection
    {
        return Product::query()
            ->with(['packagingMaterials.material', 'packagingMaterials.unit', 'unit', 'packagingSourceUnit'])
            ->where('requires_packaging', true)
            ->where('packaging_source_product_id', $sourceProductId)
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->orderBy('name')
            ->get();
    }

    /**
     * Una opción de empaque con lo que hace falta y cuánto alcanza.
     */
    private function describePackagingOption(Product $product, ProductionOrder $order, float $remainingSourceBase): array
    {
        $locationId = (int) $order->location_id;
        $sourceProductId = (int) $product->packaging_source_product_id;

        // Sin unidad capturada se asume la unidad base del granel.
        $sourceUnitId = (int) ($product->packaging_source_unit_id
            ?: Product::whereKey($sourceProductId)->value('unit_id'));

        $perUnitBase = $this->toBaseUnit(
            $sourceProductId,
            $sourceUnitId,
            (float) ($product->packaging_source_quantity ?? 0)
        );

        // Techo por granel disponible. Sin consumo de granel no hay techo.
        $maxBySource = $perUnitBase > 0 ? (int) floor($remainingSourceBase / $perUnitBase) : null;

        $materials = $product->packagingMaterials->map(function ($material) use ($locationId) {
            $stock = $this->currentStock($locationId, (int) $material->material_product_id);
            $perUnitBase = $this->toBaseUnit(
                (int) $material->material_product_id,
                (int) $material->unit_id,
                (float) $material->quantity
            );

            return [
                'product_id' => (int) $material->material_product_id,
                'product_name' => $material->material?->name,
                'product_code' => $material->material?->code,
                'unit_id' => (int) $material->unit_id,
                'unit_name' => $material->unit?->name,
                'quantity_per_unit' => (float) $material->quantity,
                'available_stock' => $stock,
                'max_units' => $perUnitBase > 0 ? (int) floor($stock / $perUnitBase) : null,
                'notes' => $material->notes,
            ];
        })->values()->all();

        // Lo que de verdad se puede empacar: lo limita el insumo más escaso.
        $limits = array_filter(
            array_merge([$maxBySource], array_column($materials, 'max_units')),
            fn($v) => $v !== null
        );

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->code,
            'unit_id' => $product->unit_id,
            'unit_name' => $product->unit?->name,
            'source_quantity_per_unit' => (float) ($product->packaging_source_quantity ?? 0),
            'source_unit_id' => $sourceUnitId ?: null,
            'source_unit_name' => $product->packagingSourceUnit?->name,
            'max_units' => $limits ? max(0, min($limits)) : null,
            'max_units_by_source' => $maxBySource,
            'materials' => $materials,
        ];
    }

    /**
     * Empaques ya registrados, con su material.
     */
    private function describeRegistered(ProductionOrder $order): array
    {
        $order->loadMissing([
            'packagings.product',
            'packagings.unit',
            'packagings.sourceProduct',
            'packagings.sourceUnit',
            'packagings.materials.product',
            'packagings.materials.unit',
        ]);

        return $order->packagings->map(fn(ProductionOrderPackaging $p) => [
            'id' => $p->id,
            'product_id' => $p->product_id,
            'product_name' => $p->product?->name,
            'unit_id' => $p->unit_id,
            'unit_name' => $p->unit?->name,
            'quantity' => (float) $p->quantity,
            'source_product_id' => $p->source_product_id,
            'source_product_name' => $p->sourceProduct?->name,
            'source_unit_id' => $p->source_unit_id,
            'source_unit_name' => $p->sourceUnit?->name,
            'source_quantity' => $p->source_quantity !== null ? (float) $p->source_quantity : null,
            'packed_at' => optional($p->packed_at)->toISOString(),
            'notes' => $p->notes,
            'materials' => $p->materials->map(fn($m) => [
                'id' => $m->id,
                'product_id' => $m->product_id,
                'product_name' => $m->product?->name,
                'unit_id' => $m->unit_id,
                'unit_name' => $m->unit?->name,
                'quantity' => (float) $m->quantity,
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Granel de un producto ya consumido por los empaques de esta orden,
     * en la unidad base del producto.
     */
    private function packedSourceBaseQuantity(ProductionOrder $order, int $sourceProductId): float
    {
        return (float) $order->packagings
            ->where('source_product_id', $sourceProductId)
            ->sum(fn(ProductionOrderPackaging $p) => $this->toBaseUnit(
                $sourceProductId,
                (int) $p->source_unit_id,
                (float) $p->source_quantity
            ));
    }

    private function currentStock(int $locationId, int $productId): float
    {
        return (float) (DB::table('product_location')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->value('current_stock') ?? 0);
    }

    /**
     * Convierte a la unidad base del producto. Las cantidades del plan se
     * comparan siempre en unidad base, porque el granel puede capturarse en
     * litros y consumirse en mililitros.
     */
    private function toBaseUnit(int $productId, int $unitId, float $quantity): float
    {
        if ($quantity <= 0 || !$productId || !$unitId) {
            return 0.0;
        }

        try {
            return $this->movementService->quantityInProductUnit($productId, $unitId, $quantity);
        } catch (\Throwable) {
            // Producto sin unidad base o unidad incompatible: se usa tal cual
            // para no tumbar el plan completo por una receta mal capturada.
            return $quantity;
        }
    }

    private function status(ProductionOrder $order): ProductionOrderStatus
    {
        return $order->status instanceof ProductionOrderStatus
            ? $order->status
            : ProductionOrderStatus::from((string) $order->status);
    }
}
