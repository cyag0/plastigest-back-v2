<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Unit;
use App\Support\CurrentLocation;
use Exception;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Incrementar stock de un producto
     *
     * @param int $productId ID del producto
     * @param float $quantity Cantidad a incrementar
     * @param int|null $unitId ID de la unidad (opcional, usa la unidad base del producto si no se especifica)
     * @param int|null $locationId ID de la ubicación (opcional, usa CurrentLocation si no se especifica)
     * @return array Información del stock actualizado
     * @throws Exception
     */
    public function increment(
        int $productId,
        float $quantity,
        ?int $unitId = null,
        ?int $locationId = null
    ): array {
        try {
            DB::beginTransaction();

            // Obtener el producto
            $product = Product::with('unit')->findOrFail($productId);

            // Obtener la ubicación (usar CurrentLocation si no se especifica)
            $locationId = $locationId ?? CurrentLocation::id();
            
            if (!$locationId) {
                throw new Exception('No se especificó una ubicación y no hay ubicación actual establecida');
            }

            // Convertir cantidad a la unidad base del producto
            $quantityInBaseUnit = $this->convertToBaseUnit($quantity, $unitId, $product);

            // Verificar/crear relación product_location
            $productLocation = DB::table('product_location')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->first();

            $previousStock = $productLocation ? $productLocation->current_stock : 0;
            $newStock = $previousStock + $quantityInBaseUnit;

            if ($productLocation) {
                // Incrementar stock existente
                DB::table('product_location')
                    ->where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->update([
                        'current_stock' => $newStock,
                        'last_movement_at' => now(),
                        'updated_at' => now()
                    ]);
            } else {
                // Crear nueva relación con stock
                DB::table('product_location')->insert([
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'current_stock' => $newStock,
                    'minimum_stock' => 0,
                    'maximum_stock' => null,
                    'average_cost' => 0,
                    'active' => true,
                    'last_movement_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            DB::commit();

            return [
                'product_id' => $productId,
                'location_id' => $locationId,
                'previous_stock' => $previousStock,
                'quantity_added' => $quantityInBaseUnit,
                'new_stock' => $newStock,
                'unit' => $product->unit->abbreviation ?? 'ud',
            ];
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Decrementar stock de un producto
     *
     * @param int $productId ID del producto
     * @param float $quantity Cantidad a decrementar
     * @param int|null $unitId ID de la unidad (opcional, usa la unidad base del producto si no se especifica)
     * @param int|null $locationId ID de la ubicación (opcional, usa CurrentLocation si no se especifica)
     * @return array Información del stock actualizado
     * @throws Exception
     */
    public function decrement(
        int $productId,
        float $quantity,
        ?int $unitId = null,
        ?int $locationId = null
    ): array {
        try {
            DB::beginTransaction();

            // Obtener el producto
            $product = Product::with('unit')->findOrFail($productId);

            // Obtener la ubicación (usar CurrentLocation si no se especifica)
            $locationId = $locationId ?? CurrentLocation::id();
            
            if (!$locationId) {
                throw new Exception('No se especificó una ubicación y no hay ubicación actual establecida');
            }

            // Convertir cantidad a la unidad base del producto
            $quantityInBaseUnit = $this->convertToBaseUnit($quantity, $unitId, $product);

            // Verificar que existe la relación product_location
            $productLocation = DB::table('product_location')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->first();

            if (!$productLocation) {
                throw new Exception(
                    "El producto '{$product->name}' no existe en la ubicación especificada"
                );
            }

            $previousStock = $productLocation->current_stock;
            
            // Validar que hay suficiente stock
            if ($previousStock < $quantityInBaseUnit) {
                throw new Exception(
                    "Stock insuficiente para el producto '{$product->name}'. " .
                    "Disponible: {$previousStock} {$product->unit->abbreviation}, " .
                    "Solicitado: {$quantityInBaseUnit} {$product->unit->abbreviation}"
                );
            }

            $newStock = $previousStock - $quantityInBaseUnit;

            // Decrementar stock
            DB::table('product_location')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->update([
                    'current_stock' => $newStock,
                    'last_movement_at' => now(),
                    'updated_at' => now()
                ]);

            DB::commit();

            return [
                'product_id' => $productId,
                'location_id' => $locationId,
                'previous_stock' => $previousStock,
                'quantity_removed' => $quantityInBaseUnit,
                'new_stock' => $newStock,
                'unit' => $product->unit->abbreviation ?? 'ud',
            ];
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Convertir cantidad a la unidad base del producto
     *
     * @param float $quantity Cantidad original
     * @param int|null $unitId ID de la unidad de entrada (null = usar unidad base del producto)
     * @param Product $product Producto con su unidad base cargada
     * @return float Cantidad convertida a la unidad base
     * @throws Exception
     */
    private function convertToBaseUnit(float $quantity, ?int $unitId, Product $product): float
    {
        // Si no se especifica unidad, usar la cantidad tal cual (se asume que ya está en unidad base)
        if (!$unitId) {
            return $quantity;
        }

        // Si la unidad especificada es la misma que la unidad base del producto, no convertir
        if ($unitId === $product->unit_id) {
            return $quantity;
        }

        // Obtener la unidad especificada
        $unit = Unit::find($unitId);
        
        if (!$unit) {
            throw new Exception("Unidad con ID {$unitId} no encontrada");
        }

        // Validar que ambas unidades sean del mismo tipo
        $productUnit = $product->unit;
        if ($unit->type !== $productUnit->type) {
            throw new Exception(
                "No se puede convertir de {$unit->type} a {$productUnit->type}. " .
                "Las unidades deben ser del mismo tipo."
            );
        }

        // Convertir usando factor_to_base
        // Ejemplo: Si tengo 2 kg y quiero convertir a g (unidad base):
        // unit->factor_to_base = 1 (kg es la base en este caso)
        // productUnit->factor_to_base = 0.001 (1 g = 0.001 kg)
        // Conversión: 2 kg * (1 / 0.001) = 2000 g
        
        $conversionFactor = $unit->factor_to_base / $productUnit->factor_to_base;
        
        return $quantity * $conversionFactor;
    }
}
