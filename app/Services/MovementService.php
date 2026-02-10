<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class MovementService
{
    /**
     * Incrementar stock en una ubicación
     *
     * @param int $locationId ID de la ubicación
     * @param int $productId ID del producto
     * @param int $unitId ID de la unidad en la que viene la cantidad
     * @param float $quantity Cantidad a incrementar (en la unidad especificada)
     * @param int|null $packageId ID del paquete (opcional)
     * @return void
     * @throws Exception
     */
    public function increment(int $locationId, int $productId, int $unitId, float $quantity, ?int $packageId = null): void
    {
        DB::beginTransaction();
        try {
            // Si hay package_id, usar el unit_id del producto base
            if ($packageId) {
                $product = Product::findOrFail($productId);
                $unitId = $product->unit_id;
            }
            
            // Convertir la cantidad a la unidad base del producto
            $quantityInProductUnit = $this->convertToProductUnit($productId, $unitId, $quantity, $packageId);

            // Incrementar el stock
            $this->incrementStock($locationId, $productId, $quantityInProductUnit);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Decrementar stock en una ubicación
     *
     * @param int $locationId ID de la ubicación
     * @param int $productId ID del producto
     * @param int $unitId ID de la unidad en la que viene la cantidad
     * @param float $quantity Cantidad a decrementar (en la unidad especificada)
     * @param int|null $packageId ID del paquete (opcional)
     * @return void
     * @throws Exception
     */
    public function decrement(int $locationId, int $productId, int $unitId, float $quantity, ?int $packageId = null): void
    {
        DB::beginTransaction();
        try {
            // Si hay package_id, usar el unit_id del producto base
            if ($packageId) {
                $product = Product::findOrFail($productId);
                $unitId = $product->unit_id;
            }
            
            // Convertir la cantidad a la unidad base del producto
            $quantityInProductUnit = $this->convertToProductUnit($productId, $unitId, $quantity, $packageId);
            Log::info("Cantidad en unidad de producto para decrementar: {$quantityInProductUnit}");

            // Validar que hay suficiente stock
            $this->validateStock($locationId, $productId, $quantityInProductUnit);
            Log::info("Stock validado para el producto ID {$productId} en la ubicación ID {$locationId}");

            // Decrementar el stock
            $this->decrementStock($locationId, $productId, $quantityInProductUnit);
            Log::info("Stock decremented for product ID {$productId} in location ID {$locationId}");

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Convertir cantidad de una unidad específica a la unidad base del producto
     *
     * @param int $productId ID del producto
     * @param int $unitId ID de la unidad en la que viene la cantidad
     * @param float $quantity Cantidad en la unidad especificada
     * @param int|null $packageId ID del paquete (opcional)
     * @return float Cantidad convertida a la unidad base del producto
     * @throws Exception
     */
    protected function convertToProductUnit(int $productId, int $unitId, float $quantity, ?int $packageId): float
    {
        // Obtener el producto con su unidad
        $product = Product::with('unit')->findOrFail($productId);

        if (!$product->unit_id) {
            throw new Exception("El producto ID {$productId} no tiene una unidad base definida");
        }

        // Si es un paquete, primero calcular la cantidad total del paquete
        if ($packageId) {
            $package = ProductPackage::findOrFail($packageId);

            // Validar que el paquete pertenece al producto
            if ($package->product_id !== $productId) {
                throw new Exception("El paquete ID {$packageId} no pertenece al producto ID {$productId}");
            }

            // Para paquetes, solo multiplicar cantidad por quantity_per_package
            // No hacemos conversión de unidad porque quantity_per_package ya define la conversión
            // Ejemplo: 2 cajas * 10 unidades/caja = 20 unidades
            return $quantity * $package->quantity_per_package;
        } else {
            // Para productos normales, convertir directamente de unit_id a product->unit_id
            return $this->convertUnits($quantity, $unitId, $product->unit_id);
        }
    }

    /**
     * Convertir cantidad de una unidad a otra
     *
     * @param float $quantity Cantidad a convertir
     * @param int $fromUnitId ID de la unidad origen
     * @param int $toUnitId ID de la unidad destino
     * @return float Cantidad convertida
     * @throws Exception
     */
    protected function convertUnits(float $quantity, int $fromUnitId, int $toUnitId): float
    {
        // Si son la misma unidad, no hay conversión
        if ($fromUnitId === $toUnitId) {
            return $quantity;
        }

        $fromUnit = Unit::with('baseUnit')->findOrFail($fromUnitId);
        $toUnit = Unit::with('baseUnit')->findOrFail($toUnitId);

        // Validar que las unidades son del mismo tipo (tienen la misma unidad base)
        $fromBaseUnitId = $fromUnit->base_unit_id ?? $fromUnitId;
        $toBaseUnitId = $toUnit->base_unit_id ?? $toUnitId;

        if ($fromBaseUnitId !== $toBaseUnitId) {
            throw new Exception("No se pueden convertir unidades de diferentes tipos (unidad {$fromUnitId} a unidad {$toUnitId})");
        }

        // Convertir de fromUnit a unidad base
        $fromFactor = $fromUnit->factor_to_base ?? 1;
        $quantityInBaseUnit = $quantity * $fromFactor;

        // Convertir de unidad base a toUnit
        $toFactor = $toUnit->factor_to_base ?? 1;
        $convertedQuantity = $quantityInBaseUnit / $toFactor;

        return $convertedQuantity;
    }

    /**
     * Validar que hay suficiente stock
     *
     * @param int $locationId ID de la ubicación
     * @param int $productId ID del producto
     * @param float $quantity Cantidad solicitada (en unidad base del producto)
     * @return void
     * @throws Exception
     */
    protected function validateStock(int $locationId, int $productId, float $quantity): void
    {
        $stock = DB::table('product_location')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->value('current_stock');

        if ($stock === null || $stock < $quantity) {
            throw new Exception("Stock insuficiente para el producto ID {$productId}. Disponible: {$stock}, Solicitado: {$quantity}");
        }
    }

    /**
     * Decrementar stock en una ubicación
     *
     * @param int $locationId ID de la ubicación
     * @param int $productId ID del producto
     * @param float $quantity Cantidad a decrementar (en unidad base del producto)
     * @return void
     * @throws Exception
     */
    protected function decrementStock(int $locationId, int $productId, float $quantity): void
    {
        $updated = DB::table('product_location')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->where('current_stock', '>=', $quantity)
            ->decrement('current_stock', $quantity);

        if (!$updated) {
            throw new Exception("No se pudo decrementar el stock para el producto ID {$productId}");
        }
    }

    /**
     * Incrementar stock en una ubicación
     *
     * @param int $locationId ID de la ubicación
     * @param int $productId ID del producto
     * @param float $quantity Cantidad a incrementar (en unidad base del producto)
     * @return void
     */
    protected function incrementStock(int $locationId, int $productId, float $quantity): void
    {
        $exists = DB::table('product_location')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->exists();

        if ($exists) {
            DB::table('product_location')
                ->where('location_id', $locationId)
                ->where('product_id', $productId)
                ->increment('current_stock', $quantity);
        } else {
            DB::table('product_location')->insert([
                'location_id' => $locationId,
                'product_id' => $productId,
                'current_stock' => $quantity,
                'minimum_stock' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
