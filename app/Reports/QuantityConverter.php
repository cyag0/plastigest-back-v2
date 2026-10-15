<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Lleva cantidades a la unidad del producto con las mismas reglas que
 * MovementService (un paquete multiplica por su contenido; una unidad se
 * convierte por su factor si es del mismo tipo), pero con las unidades y
 * paquetes en memoria: un reporte convierte miles de renglones.
 *
 * Devuelve null cuando la conversión no es posible (unidades de distinto
 * tipo, factor inválido): mejor un hueco visible que un número inventado.
 */
final class QuantityConverter
{
    /** @var array<int, object{unit_type: ?string, factor_to_base: ?string}> */
    private array $units;

    /** @var array<int, int|null> product_id => unit_id */
    private array $productUnits = [];

    /** @var array<int, float|null> package_id => quantity_per_package */
    private array $packages = [];

    public function __construct()
    {
        $this->units = DB::table('units')->get(['id', 'unit_type', 'factor_to_base'])->keyBy('id')->all();
    }

    /** @param int[] $productIds */
    public function loadProducts(array $productIds): void
    {
        $missing = array_diff(array_unique($productIds), array_keys($this->productUnits));
        foreach (array_chunk($missing, 1000) as $chunk) {
            foreach (DB::table('products')->whereIn('id', $chunk)->pluck('unit_id', 'id') as $id => $unitId) {
                $this->productUnits[(int) $id] = $unitId ? (int) $unitId : null;
            }
        }
    }

    public function productUnit(int $productId): ?int
    {
        if (!array_key_exists($productId, $this->productUnits)) {
            $this->loadProducts([$productId]);
        }

        return $this->productUnits[$productId] ?? null;
    }

    public function toProductUnit(int $productId, ?int $unitId, float $quantity, ?int $packageId = null): ?float
    {
        if ($packageId) {
            $perPackage = $this->packageContent($packageId);

            return $perPackage === null ? null : $quantity * $perPackage;
        }

        $productUnit = $this->productUnit($productId);
        if (!$unitId || !$productUnit || $unitId === $productUnit) {
            return $quantity;
        }

        return $this->convert($quantity, $unitId, $productUnit);
    }

    public function convert(float $quantity, int $fromUnitId, int $toUnitId): ?float
    {
        if ($fromUnitId === $toUnitId) {
            return $quantity;
        }

        $from = $this->units[$fromUnitId] ?? null;
        $to = $this->units[$toUnitId] ?? null;
        if (!$from || !$to || (string) $from->unit_type !== (string) $to->unit_type) {
            return null;
        }

        $fromFactor = (float) ($from->factor_to_base ?? 1);
        $toFactor = (float) ($to->factor_to_base ?? 1);
        if ($fromFactor <= 0 || $toFactor <= 0) {
            return null;
        }

        return $quantity * $fromFactor / $toFactor;
    }

    private function packageContent(int $packageId): ?float
    {
        if (!array_key_exists($packageId, $this->packages)) {
            $content = DB::table('product_packages')->where('id', $packageId)->value('quantity_per_package');
            $this->packages[$packageId] = $content !== null && (float) $content > 0 ? (float) $content : null;
        }

        return $this->packages[$packageId];
    }
}
