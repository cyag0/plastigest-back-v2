<?php

namespace App\Reports;

use App\Models\PurchaseV2;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Costo unitario de cada producto, en su propia unidad, para valorizar
 * inventario y mermas.
 *
 * El inventario no lleva costo promedio (product_location.average_cost nunca
 * se actualiza), así que se toma, en este orden:
 *
 *  1. La última compra recibida (hasta la fecha de corte), con el precio
 *     llevado a la unidad del producto: una caja de 12 a $120 son $10 la pieza.
 *  2. Si se fabrica, lo que cuestan sus insumos: la receta de empaque (granel
 *     + material) del terminado o la fórmula que lo produce.
 *  3. El precio de compra capturado en el catálogo.
 *
 * Sin nada de eso el producto queda "sin costo": el reporte lo muestra en
 * lugar de valuarlo en cero sin avisar.
 */
final class ProductCosts
{
    public const SOURCE_PURCHASE = 'purchase';
    public const SOURCE_PACKAGING = 'packaging';
    public const SOURCE_FORMULA = 'formula';
    public const SOURCE_CATALOG = 'catalog';
    public const SOURCE_NONE = 'none';

    public const SOURCE_LABELS = [
        self::SOURCE_PURCHASE => 'Última compra',
        self::SOURCE_PACKAGING => 'Receta de empaque',
        self::SOURCE_FORMULA => 'Fórmula',
        self::SOURCE_CATALOG => 'Catálogo',
        self::SOURCE_NONE => 'Sin costo',
    ];

    /** Una fórmula que usa un producto fabricado que usa otro… hasta aquí. */
    private const MAX_DEPTH = 6;

    /** @var array<int, object> */
    private array $products;

    /** @var array<int, float> product_id => costo por unidad del producto */
    private array $lastPurchase;

    /** @var array<int, array<int, object>> product_id => materiales de empaque */
    private array $packagingMaterials;

    /** @var array<int, object> product_id => fórmula activa que lo produce, con items y outputs */
    private array $formulaByOutput;

    /** @var array<int, array{cost: ?float, source: string, complete: bool}> */
    private array $resolved = [];

    /** @var array<int, true> */
    private array $resolving = [];

    public function __construct(
        private readonly int $companyId,
        private readonly ?CarbonInterface $asOf = null,
        private readonly QuantityConverter $converter = new QuantityConverter(),
    ) {
        $this->products = DB::table('products')
            ->where('company_id', $companyId)
            ->get(['id', 'unit_id', 'purchase_price', 'sale_price', 'packaging_source_product_id', 'packaging_source_quantity', 'packaging_source_unit_id'])
            ->keyBy('id')
            ->all();
        $this->converter->loadProducts(array_keys($this->products));

        $this->lastPurchase = $this->loadLastPurchases();
        $this->packagingMaterials = DB::table('product_packaging_materials')
            ->whereIn('product_id', array_keys($this->products))
            ->get(['product_id', 'material_product_id', 'unit_id', 'quantity'])
            ->groupBy('product_id')
            ->map(fn($rows) => $rows->all())
            ->all();
        $this->formulaByOutput = $this->loadFormulas();
    }

    public function converter(): QuantityConverter
    {
        return $this->converter;
    }

    /** Costo por unidad del producto, o null si no hay de dónde sacarlo. */
    public function cost(int $productId): ?float
    {
        return $this->resolve($productId)['cost'];
    }

    /**
     * @return array{cost: ?float, source: string, complete: bool}
     *   complete = false cuando a la receta o fórmula le faltó el costo de
     *   algún insumo y el resultado se quedó corto.
     */
    public function resolve(int $productId, int $depth = 0): array
    {
        if (isset($this->resolved[$productId])) {
            return $this->resolved[$productId];
        }

        // Ciclo (A se hace con B y B con A) o cadena demasiado larga: no hay costo.
        if (isset($this->resolving[$productId]) || $depth > self::MAX_DEPTH) {
            return ['cost' => null, 'source' => self::SOURCE_NONE, 'complete' => false];
        }

        $this->resolving[$productId] = true;
        try {
            $result = $this->compute($productId, $depth);
        } finally {
            unset($this->resolving[$productId]);
        }

        return $this->resolved[$productId] = $result;
    }

    public static function sourceLabel(string $source, bool $complete = true): string
    {
        $label = self::SOURCE_LABELS[$source] ?? $source;

        return $complete ? $label : $label . ' (incompleta)';
    }

    private function compute(int $productId, int $depth): array
    {
        if (isset($this->lastPurchase[$productId])) {
            return ['cost' => $this->lastPurchase[$productId], 'source' => self::SOURCE_PURCHASE, 'complete' => true];
        }

        $product = $this->products[$productId] ?? null;

        $derived = $this->fromPackaging($product, $depth) ?? $this->fromFormula($productId, $depth);
        if ($derived !== null) {
            return $derived;
        }

        if ($product && (float) $product->purchase_price > 0) {
            return ['cost' => (float) $product->purchase_price, 'source' => self::SOURCE_CATALOG, 'complete' => true];
        }

        return ['cost' => null, 'source' => self::SOURCE_NONE, 'complete' => true];
    }

    /**
     * Terminado que se empaca: granel por unidad + material por unidad. La
     * receta es por unidad del terminado (ver ProductionPackagingService).
     */
    private function fromPackaging(?object $product, int $depth): ?array
    {
        if (!$product || !$product->packaging_source_product_id || (float) $product->packaging_source_quantity <= 0) {
            return null;
        }

        $sourceId = (int) $product->packaging_source_product_id;
        $sourceQty = $this->converter->toProductUnit(
            $sourceId,
            $product->packaging_source_unit_id ? (int) $product->packaging_source_unit_id : null,
            (float) $product->packaging_source_quantity,
        );
        $source = $this->resolve($sourceId, $depth + 1);
        if ($sourceQty === null || $source['cost'] === null) {
            return null;
        }

        $cost = $sourceQty * $source['cost'];
        $complete = $source['complete'];

        foreach ($this->packagingMaterials[$product->id] ?? [] as $material) {
            $materialId = (int) $material->material_product_id;
            $qty = $this->converter->toProductUnit($materialId, $material->unit_id ? (int) $material->unit_id : null, (float) $material->quantity);
            $materialCost = $this->resolve($materialId, $depth + 1);
            if ($qty === null || $materialCost['cost'] === null) {
                $complete = false;
                continue;
            }
            $cost += $qty * $materialCost['cost'];
            $complete = $complete && $materialCost['complete'];
        }

        return ['cost' => $cost, 'source' => self::SOURCE_PACKAGING, 'complete' => $complete];
    }

    /**
     * Producto que sale de una fórmula: lo que cuestan los insumos de una
     * tanda, repartido entre lo que la tanda produce.
     *
     * Con varias salidas (coco → agua + pulpa + cáscara) el costo se reparte
     * por valor de venta, como se hace con productos conjuntos: lo que no se
     * vende (la cáscara) no carga costo. Si ninguna salida tiene precio de
     * venta, se reparte por cantidad.
     */
    private function fromFormula(int $productId, int $depth): ?array
    {
        $formula = $this->formulaByOutput[$productId] ?? null;
        if (!$formula) {
            return null;
        }

        $inputs = 0.0;
        $complete = true;
        $anyInput = false;
        foreach ($formula->items as $item) {
            $itemId = (int) $item->product_id;
            $qty = $this->converter->toProductUnit($itemId, $item->unit_id ? (int) $item->unit_id : null, (float) $item->expected_quantity);
            $itemCost = $this->resolve($itemId, $depth + 1);
            if ($qty === null || $itemCost['cost'] === null) {
                $complete = false;
                continue;
            }
            $anyInput = true;
            $inputs += $qty * $itemCost['cost'];
            $complete = $complete && $itemCost['complete'];
        }

        if (!$anyInput) {
            return null;
        }

        $outputs = [];
        foreach ($formula->outputs as $output) {
            $outputId = (int) $output->product_id;
            $qty = $this->converter->toProductUnit($outputId, $output->unit_id ? (int) $output->unit_id : null, (float) $output->expected_quantity);
            if ($qty === null || $qty <= 0) {
                continue;
            }
            $outputs[$outputId] = [
                'quantity' => $qty,
                'value' => $qty * (float) ($this->products[$outputId]->sale_price ?? 0),
            ];
        }

        $mine = $outputs[$productId] ?? null;
        if (!$mine) {
            return null;
        }

        $totalValue = array_sum(array_column($outputs, 'value'));
        $share = $totalValue > 0
            ? $mine['value'] / $totalValue
            : $mine['quantity'] / array_sum(array_column($outputs, 'quantity'));

        return ['cost' => $inputs * $share / $mine['quantity'], 'source' => self::SOURCE_FORMULA, 'complete' => $complete];
    }

    /** @return array<int, float> */
    private function loadLastPurchases(): array
    {
        $receivedAt = 'COALESCE(purchase_details.received_at, purchases.delivery_date, purchases.updated_at)';

        $rows = DB::table('purchase_details')
            ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
            ->where('purchases.company_id', $this->companyId)
            ->where('purchases.status', PurchaseV2::STATUS_RECEIVED)
            ->where('purchase_details.unit_price', '>', 0)
            // Solo lo que de verdad llegó: un renglón recibido en 0 (no llegó)
            // fijaba el costo con un precio que nadie pagó. Sin cantidad
            // recibida es una compra de antes de capturarla: llegó completa.
            ->where(fn($q) => $q->whereNull('purchase_details.quantity_received')
                ->orWhere('purchase_details.quantity_received', '>', 0))
            ->when($this->asOf, fn($q) => $q->whereRaw("{$receivedAt} <= ?", [$this->asOf->copy()->endOfDay()->toDateTimeString()]))
            ->orderByRaw("{$receivedAt} DESC")
            ->orderByDesc('purchase_details.id')
            ->get(['purchase_details.product_id', 'purchase_details.unit_id', 'purchase_details.package_id', 'purchase_details.unit_price']);

        $costs = [];
        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            if (isset($costs[$productId])) {
                continue;
            }
            // Cuántas unidades del producto trae una unidad (o paquete) comprada.
            $perUnit = $this->converter->toProductUnit($productId, $row->unit_id ? (int) $row->unit_id : null, 1.0, $row->package_id ? (int) $row->package_id : null);
            if ($perUnit !== null && $perUnit > 0) {
                $costs[$productId] = (float) $row->unit_price / $perUnit;
            }
        }

        return $costs;
    }

    /** @return array<int, object> */
    private function loadFormulas(): array
    {
        $formulas = DB::table('formulas')
            ->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get(['id']);
        if ($formulas->isEmpty()) {
            return [];
        }

        $ids = $formulas->pluck('id');
        $items = DB::table('formula_items')->whereIn('formula_id', $ids)->get()->groupBy('formula_id');
        $outputs = DB::table('formula_outputs')->whereIn('formula_id', $ids)->orderByDesc('is_primary')->get()->groupBy('formula_id');

        // La fórmula que produce cada producto: la que lo tiene como salida
        // principal antes que como secundaria, y la versión más nueva.
        $byOutput = [];
        $primary = [];
        foreach ($formulas as $formula) {
            $formula->items = ($items[$formula->id] ?? collect())->all();
            $formula->outputs = ($outputs[$formula->id] ?? collect())->all();
            foreach ($formula->outputs as $output) {
                $productId = (int) $output->product_id;
                $isPrimary = (bool) $output->is_primary;
                if (!isset($byOutput[$productId]) || ($isPrimary && !($primary[$productId] ?? false))) {
                    $byOutput[$productId] = $formula;
                    $primary[$productId] = $isPrimary;
                }
            }
        }

        return $byOutput;
    }
}
