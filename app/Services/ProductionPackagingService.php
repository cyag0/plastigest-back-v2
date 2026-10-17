<?php

namespace App\Services;

use App\Enums\StockLedgerReason;
use App\Exceptions\BusinessRuleException;
use App\Models\Operations\ProductionOrder;
use App\Models\Operations\ProductionOrderPackaging;
use App\Models\Operations\ProductionOrderPackagingMaterial;
use App\Models\Product;
use App\Support\StockContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Envasado: granel + material de empaque → producto terminado.
 *
 * La receta vive en la presentación (el producto terminado): de qué granel
 * sale, cuánto granel lleva cada unidad (`packaging_source_*`) y qué material
 * consume (`product_packaging_materials`: botella, tapa). Al envasar se copia
 * a la orden con las cantidades reales, para que el histórico no cambie si
 * después se edita el producto.
 *
 * Se envasa en dos momentos:
 *  - Al registrar una producción, con el granel que esa misma orden produjo
 *    (cocos → 120 L de agua → 51 botellas de 1 L…). Sólo se puede envasar lo
 *    que la orden produjo.
 *  - Después, en una orden de envasado (kind = packaging), con el granel que
 *    está en inventario: el agua que quedó en la mañana se embotella en la
 *    tarde.
 *
 * Todo corre dentro de la transacción de ProductionOrderService::complete();
 * la cancelación de la orden lo revierte con revertAll().
 */
class ProductionPackagingService
{
    public function __construct(private readonly MovementService $movementService) {}

    /**
     * Líneas de envasado tal como se guardan en el borrador: producto
     * terminado, cantidad mayor que cero y, si viene, la unidad.
     *
     * @return array<int, array{product_id:int, quantity:float, unit_id:?int}>
     */
    public function normalize(array $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity = (float) ($line['quantity'] ?? 0);

            if (!$productId || $quantity <= 0) {
                continue;
            }

            $rows[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_id' => !empty($line['unit_id']) ? (int) $line['unit_id'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Envasa: por cada línea descuenta el granel y el material de la receta y
     * da de alta el producto terminado. Tiene que llamarse dentro de una
     * transacción, con la orden ya bloqueada.
     *
     * Antes de mover nada revisa que alcance todo (granel y material, sumando
     * lo que piden todas las líneas), para responder en palabras de la planta
     * qué falta en vez de fallar a la mitad.
     */
    public function apply(ProductionOrder $order, array $lines): void
    {
        $rows = $this->normalize($lines);
        if (!$rows) {
            return;
        }

        $order->loadMissing(['outputs', 'packagings']);
        $locationId = (int) $order->location_id;
        $nextSort = (int) $order->packagings->max('sort_order') + 1;

        $plan = array_map(fn(array $row) => $this->planLine($order, $row), $rows);

        $this->assertBulkIsEnough($order, $plan);
        $this->assertMaterialsAreEnough($locationId, $plan);

        foreach ($plan as $line) {
            /** @var Product $product */
            $product = $line['product'];

            $packaging = ProductionOrderPackaging::create([
                'production_order_id' => $order->id,
                'product_id' => $product->id,
                'unit_id' => $line['unit_id'],
                'quantity' => $line['quantity'],
                'source_product_id' => $line['source_product_id'],
                'source_unit_id' => $line['source_unit_id'],
                'source_quantity' => $line['source_quantity'],
                'packed_at' => now(),
                'packed_by' => Auth::id(),
                'sort_order' => $nextSort++,
            ]);

            // 1. El granel del que se envasa.
            $this->movementService->decrement(
                $locationId,
                $line['source_product_id'],
                $line['source_unit_id'],
                $line['source_quantity'],
                null,
                StockContext::for(StockLedgerReason::PACKAGING_CONSUMPTION, $order, 'Granel envasado')
            );

            // 2. El material de empaque.
            foreach ($line['materials'] as $material) {
                $this->movementService->decrement(
                    $locationId,
                    $material['product_id'],
                    $material['unit_id'],
                    $material['quantity'],
                    null,
                    StockContext::for(StockLedgerReason::PACKAGING_CONSUMPTION, $order, 'Material de empaque')
                );

                ProductionOrderPackagingMaterial::create([
                    'production_order_packaging_id' => $packaging->id,
                    'product_id' => $material['product_id'],
                    'unit_id' => $material['unit_id'],
                    'quantity' => $material['quantity'],
                ]);
            }

            // 3. El producto terminado.
            $this->movementService->increment(
                $locationId,
                (int) $product->id,
                $line['unit_id'],
                $line['quantity'],
                null,
                StockContext::for(StockLedgerReason::PACKAGING_OUTPUT, $order)
            );
        }

        $order->unsetRelation('packagings');
    }

    /**
     * Revierte TODO el envasado de la orden. Lo usa la cancelación: primero se
     * deshace el envasado y después la producción, para que el granel exista
     * cuando se devuelva.
     */
    public function revertAll(ProductionOrder $order): void
    {
        foreach ($order->packagings()->with('materials')->get() as $packaging) {
            $this->reverseStock($order, $packaging);
            $packaging->materials()->delete();
            $packaging->delete();
        }
    }

    /**
     * Graneles que se envasan, con sus presentaciones, la receta de cada una y
     * las existencias de la sucursal. Es lo que necesita la app para armar el
     * envasado (el de una producción y el de después) sin hacer cuentas de
     * unidades: `content_in_bulk_unit` ya viene en la unidad del granel (una
     * botella de 500 ml de un granel en litros dice 0.5).
     *
     * @param  int[]|null  $onlyBulkIds  Sólo esos graneles (null = todos los que tienen presentaciones).
     */
    public function bulkCatalog(?int $companyId, ?int $locationId, ?array $onlyBulkIds = null): array
    {
        $presentations = Product::query()
            ->with(['unit', 'packagingSourceUnit', 'packagingMaterials.material.unit', 'packagingMaterials.unit', 'activePackages'])
            ->where('requires_packaging', true)
            ->whereNotNull('packaging_source_product_id')
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->when($onlyBulkIds !== null, fn($q) => $q->whereIn('packaging_source_product_id', $onlyBulkIds ?: [0]))
            ->orderBy('name')
            ->get()
            ->groupBy('packaging_source_product_id');

        if ($presentations->isEmpty()) {
            return [];
        }

        $bulks = Product::query()
            ->with('unit')
            ->whereIn('id', $presentations->keys())
            ->orderBy('name')
            ->get();

        $materialIds = $presentations->flatten()
            ->flatMap(fn(Product $p) => $p->packagingMaterials->pluck('material_product_id'))
            ->all();
        $stock = $this->stockMap($locationId, [
            ...$bulks->pluck('id')->all(),
            ...$presentations->flatten()->pluck('id')->all(),
            ...$materialIds,
        ]);

        return $bulks->map(fn(Product $bulk) => [
            'product_id' => (int) $bulk->id,
            'product_name' => $bulk->name,
            'product_code' => $bulk->code,
            'unit_id' => (int) $bulk->unit_id,
            'unit_name' => $bulk->unit?->name,
            'unit_abbreviation' => $bulk->unit?->abbreviation,
            'unit_type' => $bulk->unit?->unit_type,
            'stock' => $stock[$bulk->id] ?? 0.0,
            'presentations' => $presentations->get($bulk->id, collect())
                ->map(fn(Product $presentation) => $this->describePresentation($presentation, $bulk, $stock))
                ->values()
                ->all(),
        ])->values()->all();
    }

    // -----------------------------------------------------------------
    // Helpers privados
    // -----------------------------------------------------------------

    /**
     * Una línea con todo resuelto: unidades, granel de origen (de la receta) y
     * material, ya multiplicados por la cantidad envasada.
     */
    private function planLine(ProductionOrder $order, array $row): array
    {
        $product = Product::with(['packagingMaterials', 'unit'])
            ->where('company_id', $order->company_id)
            ->find($row['product_id']);

        if (!$product) {
            throw new BusinessRuleException('Uno de los productos a envasar ya no existe.', 'packaging_product_missing');
        }

        $quantity = (float) $row['quantity'];
        $unitId = (int) ($row['unit_id'] ?? $product->unit_id);

        // La receta es por unidad base del terminado: envasar 2 docenas de un
        // producto por pieza son 24 piezas, no 2.
        $units = $this->movementService->quantityInProductUnit((int) $product->id, $unitId, $quantity);

        $sourceProductId = (int) $product->packaging_source_product_id;
        $sourcePerUnit = (float) ($product->packaging_source_quantity ?? 0);

        if (!$product->requires_packaging || !$sourceProductId) {
            throw new BusinessRuleException(
                "{$product->name} no tiene receta de envasado: indica en el producto de qué granel sale.",
                'packaging_recipe_missing'
            );
        }
        if ($sourcePerUnit <= 0) {
            throw new BusinessRuleException(
                "La receta de {$product->name} no dice cuánto granel lleva cada unidad.",
                'packaging_recipe_missing'
            );
        }

        // En una producción sólo se envasa lo que salió de la orden; el resto
        // se envasa después, desde «Envasar».
        if (!$order->isPackaging() && !$order->outputs->contains('product_id', $sourceProductId)) {
            $sourceName = Product::whereKey($sourceProductId)->value('name') ?? "producto {$sourceProductId}";
            throw new BusinessRuleException(
                "{$sourceName} no salió en esta producción: agrégalo a lo que salió o envásalo después desde «Envasar».",
                'packaging_source_invalid'
            );
        }

        $sourceUnitId = (int) ($product->packaging_source_unit_id
            ?: Product::whereKey($sourceProductId)->value('unit_id'));
        $sourceQuantity = round($sourcePerUnit * $units, 4);
        $sourceBase = $this->convertForRecipe($product, $sourceProductId, $sourceUnitId, $sourceQuantity);

        $materials = [];
        foreach ($product->packagingMaterials as $material) {
            $materialQuantity = round((float) $material->quantity * $units, 4);
            if ($materialQuantity <= 0) {
                continue;
            }
            $materials[] = [
                'product_id' => (int) $material->material_product_id,
                'unit_id' => (int) $material->unit_id,
                'quantity' => $materialQuantity,
                'base' => $this->convertForRecipe($product, (int) $material->material_product_id, (int) $material->unit_id, $materialQuantity),
            ];
        }

        return [
            'product' => $product,
            'quantity' => $quantity,
            'unit_id' => $unitId,
            'source_product_id' => $sourceProductId,
            'source_unit_id' => $sourceUnitId,
            'source_quantity' => $sourceQuantity,
            'source_base' => $sourceBase,
            'materials' => $materials,
        ];
    }

    /**
     * Cantidad de la receta en la unidad base del insumo. Una receta con una
     * unidad de otra clase (granel en kg de un producto que se mide en
     * piezas) se explica en vez de fallar con un error técnico.
     */
    private function convertForRecipe(Product $presentation, int $productId, int $unitId, float $quantity): float
    {
        try {
            return $this->movementService->quantityInProductUnit($productId, $unitId, $quantity);
        } catch (BusinessRuleException $e) {
            throw $e;
        } catch (\Throwable) {
            $name = Product::whereKey($productId)->value('name') ?? "el producto {$productId}";
            throw new BusinessRuleException(
                "La receta de {$presentation->name} tiene a {$name} en una unidad que no le corresponde: corrígela en el producto.",
                'packaging_recipe_invalid'
            );
        }
    }

    /**
     * Alcanza el granel para todas las líneas juntas.
     *
     * En una producción sólo cuenta lo que la orden produjo (sin lo ya
     * envasado); en un envasado, lo que hay en la sucursal.
     */
    private function assertBulkIsEnough(ProductionOrder $order, array $plan): void
    {
        $needed = [];
        foreach ($plan as $line) {
            $needed[$line['source_product_id']] = ($needed[$line['source_product_id']] ?? 0) + $line['source_base'];
        }

        foreach ($needed as $sourceId => $quantity) {
            $source = Product::with('unit')->find($sourceId);
            $name = $source?->name ?? "producto {$sourceId}";
            $unit = $source?->unit?->abbreviation ?? '';

            if ($order->isPackaging()) {
                $available = $this->currentStock((int) $order->location_id, (int) $sourceId);
            } else {
                $available = $this->remainingSourceBaseQuantity($order, (int) $sourceId);
                if ($available === null) {
                    throw new BusinessRuleException(
                        "{$name} no salió en esta producción: agrégalo a lo que salió o envásalo después desde «Envasar».",
                        'packaging_source_invalid'
                    );
                }
            }

            if ($quantity > $available + 0.0001) {
                throw new BusinessRuleException(sprintf(
                    'No alcanza %s: %s %s %s y se quieren envasar %s %s.',
                    $name,
                    $order->isPackaging() ? 'hay' : 'salieron',
                    $this->formatQuantity($available),
                    $unit,
                    $this->formatQuantity($quantity),
                    $unit
                ), 'insufficient_bulk', [
                    'product_id' => (int) $sourceId,
                    'available' => round($available, 4),
                    'requested' => round($quantity, 4),
                ]);
            }
        }
    }

    /**
     * Alcanza el material de empaque para todas las líneas juntas: la misma
     * tapa la usan la botella de 1 L y la de ½ L.
     */
    private function assertMaterialsAreEnough(int $locationId, array $plan): void
    {
        $needed = [];
        foreach ($plan as $line) {
            foreach ($line['materials'] as $material) {
                $needed[$material['product_id']] = ($needed[$material['product_id']] ?? 0) + $material['base'];
            }
        }

        foreach ($needed as $materialId => $quantity) {
            $available = $this->currentStock($locationId, (int) $materialId);
            if ($quantity <= $available + 0.0001) {
                continue;
            }

            $material = Product::with('unit')->find($materialId);
            throw new BusinessRuleException(sprintf(
                'No alcanza %s: hay %s y se necesitan %s.',
                $material?->name ?? "el material {$materialId}",
                $this->formatQuantity($available),
                $this->formatQuantity($quantity)
            ), 'insufficient_material', [
                'product_id' => (int) $materialId,
                'available' => round($available, 4),
                'requested' => round($quantity, 4),
            ]);
        }
    }

    /**
     * Deshace el movimiento de stock de un envasado, en orden inverso al de
     * `apply()`: primero se retira el terminado y después se devuelven insumos.
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
            StockContext::for(StockLedgerReason::PACKAGING_OUTPUT, $order, 'Reversa del envasado')
        );

        if ($packaging->source_product_id && (float) $packaging->source_quantity > 0) {
            $this->movementService->increment(
                $locationId,
                (int) $packaging->source_product_id,
                (int) $packaging->source_unit_id,
                (float) $packaging->source_quantity,
                null,
                StockContext::for(StockLedgerReason::PACKAGING_CONSUMPTION, $order, 'Granel devuelto al revertir el envasado')
            );
        }

        foreach ($packaging->materials as $material) {
            $this->movementService->increment(
                $locationId,
                (int) $material->product_id,
                (int) $material->unit_id,
                (float) $material->quantity,
                null,
                StockContext::for(StockLedgerReason::PACKAGING_CONSUMPTION, $order, 'Material devuelto al revertir el envasado')
            );
        }
    }

    /**
     * Una presentación con su receta y existencias. El contenido viene en la
     * unidad de la receta y también convertido a la del granel.
     */
    private function describePresentation(Product $presentation, Product $bulk, array $stock): array
    {
        $contentUnitId = (int) ($presentation->packaging_source_unit_id ?: $bulk->unit_id);
        $content = (float) ($presentation->packaging_source_quantity ?? 0);

        return [
            'product_id' => (int) $presentation->id,
            'product_name' => $presentation->name,
            'product_code' => $presentation->code,
            'barcodes' => $presentation->activePackages->pluck('barcode')->filter()->values()->all(),
            'unit_id' => (int) $presentation->unit_id,
            'unit_name' => $presentation->unit?->name,
            'unit_abbreviation' => $presentation->unit?->abbreviation,
            'content' => $content,
            'content_unit_id' => $contentUnitId,
            'content_unit_abbreviation' => $presentation->packagingSourceUnit?->abbreviation ?? $bulk->unit?->abbreviation,
            'content_in_bulk_unit' => round($this->toBaseUnit((int) $bulk->id, $contentUnitId, $content), 6),
            'stock' => $stock[$presentation->id] ?? 0.0,
            'materials' => $presentation->packagingMaterials->map(function ($material) use ($stock) {
                $materialProduct = $material->material;

                return [
                    'product_id' => (int) $material->material_product_id,
                    'product_name' => $materialProduct?->name,
                    'product_code' => $materialProduct?->code,
                    'unit_id' => (int) $material->unit_id,
                    'unit_abbreviation' => $material->unit?->abbreviation,
                    'quantity_per_unit' => (float) $material->quantity,
                    // Para comparar contra la existencia, en la unidad base del material.
                    'quantity_per_unit_base' => round($this->toBaseUnit(
                        (int) $material->material_product_id,
                        (int) $material->unit_id,
                        (float) $material->quantity
                    ), 6),
                    'stock' => $stock[$material->material_product_id] ?? 0.0,
                    'stock_unit_abbreviation' => $materialProduct?->unit?->abbreviation,
                ];
            })->values()->all(),
        ];
    }

    /** @return array<int, float> */
    private function stockMap(?int $locationId, array $productIds): array
    {
        if (!$locationId || !$productIds) {
            return [];
        }

        return DB::table('product_location')
            ->where('location_id', $locationId)
            ->whereIn('product_id', array_unique($productIds))
            ->pluck('current_stock', 'product_id')
            ->map(fn($value) => (float) $value)
            ->all();
    }

    /**
     * Granel de un producto que esta orden produjo y aún no se ha envasado ni
     * tirado, en la unidad base del granel. Null si no es salida de la orden.
     */
    private function remainingSourceBaseQuantity(ProductionOrder $order, int $sourceProductId): ?float
    {
        $outputs = $order->outputs->where('product_id', $sourceProductId);

        if ($outputs->isEmpty()) {
            return null;
        }

        $produced = (float) $outputs->sum(fn($output) => $this->toBaseUnit(
            $sourceProductId,
            (int) $output->unit_id,
            (float) $output->quantity
        ));

        $packed = (float) $order->packagings
            ->where('source_product_id', $sourceProductId)
            ->sum(fn(ProductionOrderPackaging $p) => $this->toBaseUnit(
                $sourceProductId,
                (int) $p->source_unit_id,
                (float) $p->source_quantity
            ));

        // Lo que se tiró de ese granel en la misma orden tampoco se envasa.
        $wasted = 0.0;
        foreach ($order->getAttribute('wastes') ?? [] as $waste) {
            if ((int) ($waste['product_id'] ?? 0) === $sourceProductId) {
                $wasted += $this->toBaseUnit($sourceProductId, (int) ($waste['unit_id'] ?? 0), (float) ($waste['quantity'] ?? 0));
            }
        }

        return max(0.0, round($produced - $packed - $wasted, 4));
    }

    private function currentStock(int $locationId, int $productId): float
    {
        return (float) (DB::table('product_location')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->value('current_stock') ?? 0);
    }

    /**
     * Convierte a la unidad base del producto. Con una receta mal capturada
     * (unidad incompatible) se usa tal cual para no tumbar el catálogo.
     */
    private function toBaseUnit(int $productId, int $unitId, float $quantity): float
    {
        if ($quantity <= 0 || !$productId || !$unitId) {
            return 0.0;
        }

        try {
            return $this->movementService->quantityInProductUnit($productId, $unitId, $quantity);
        } catch (\Throwable) {
            return $quantity;
        }
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }
}
