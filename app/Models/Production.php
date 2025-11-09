<?php

namespace App\Models;

use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Production Model - Wrapper para movements con movement_type = 'production' y movement_reason = 'production'
 */
class Production extends Movement
{
    /**
     * Especificar la tabla que debe usar este modelo
     */
    protected $table = 'movements';

    /**
     * Los atributos que deben ser casteados
     */
    protected $casts = [
        'production_date' => 'date'
    ];

    /**
     * Configurar automáticamente el tipo de movimiento como production
     */
    protected static function booted()
    {
        parent::booted();

        // Automáticamente filtrar solo producciones
        static::addGlobalScope('production_scope', function (Builder $builder) {
            $builder->where('movement_type', 'production')
                ->where('movement_reason', 'production');
        });

        // Establecer valores por defecto al crear
        static::creating(function ($model) {
            $model->movement_type = 'production';
            $model->movement_reason = 'production';
            $model->reference_type = 'production_order';
        });
    }

    /**
     * Obtener los detalles de la producción
     */
    public function details(): HasMany
    {
        return $this->hasMany(MovementDetail::class, 'movement_id');
    }

    /**
     * Obtener el producto producido
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Obtener la ubicación
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_destination_id');
    }

    /**
     * Scope para producciones por rango de fechas
     */
    public function scopeBetweenDates(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('movement_date', [$startDate, $endDate]);
    }

    /**
     * Accessor para el número de producción
     */
    public function getProductionNumberAttribute(): string
    {
        return $this->document_number ?? 'PROD-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Accessor para la fecha de producción
     */
    public function getProductionDateAttribute(): string
    {
        return $this->movement_date;
    }

    public function locationDestination(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_destination_id');
    }

    /**
     * Procesar la producción: restar ingredientes y sumar producto final
     */
    public function processProduction(): bool
    {
        DB::beginTransaction();
        try {
            // Obtener el detalle principal para saber qué producto se está produciendo
            $mainDetail = $this->details()->first();

            if (!$mainDetail) {
                throw new Exception("No se encontró el detalle de producción");
            }

            $productId = $mainDetail->product_id;
            $quantityToProduce = $mainDetail->quantity;

            // Obtener el producto que se va a producir
            $product = Product::with('productIngredients.ingredient')->findOrFail($productId);

            // Validar que el producto tenga ingredientes
            if ($product->productIngredients->isEmpty()) {
                throw new Exception("El producto no tiene ingredientes configurados");
            }

            $locationId = $this->location_destination_id;

            // Validar stock de ingredientes
            foreach ($product->productIngredients as $productIngredient) {
                $ingredientId = $productIngredient->ingredient_id;
                $quantityNeeded = $productIngredient->quantity * $quantityToProduce;

                $productLocation = DB::table('product_location')
                    ->where('product_id', $ingredientId)
                    ->where('location_id', $locationId)
                    ->first();

                if (!$productLocation || $productLocation->current_stock < $quantityNeeded) {
                    $ingredientName = $productIngredient->ingredient->name ?? "Ingrediente ID: {$ingredientId}";
                    $available = $productLocation->current_stock ?? 0;
                    throw new Exception(
                        "Stock insuficiente del ingrediente '{$ingredientName}'. " .
                            "Necesitas {$quantityNeeded}, disponible: {$available}"
                    );
                }
            }

            // Restar ingredientes del inventario
            foreach ($product->productIngredients as $productIngredient) {
                $ingredientId = $productIngredient->ingredient_id;
                $quantityNeeded = $productIngredient->quantity * $quantityToProduce;

                DB::table('product_location')
                    ->where('product_id', $ingredientId)
                    ->where('location_id', $locationId)
                    ->decrement('current_stock', $quantityNeeded);

                // Crear detalle de movimiento para el ingrediente (resta)
                MovementDetail::create([
                    'movement_id' => $this->id,
                    'product_id' => $ingredientId,
                    'quantity' => -$quantityNeeded, // Negativo porque se resta
                    'unit_cost' => 0,
                    'total_cost' => 0,
                ]);
            }

            // Agregar producto terminado al inventario
            $productLocation = DB::table('product_location')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->first();

            if ($productLocation) {
                // Actualizar stock existente
                DB::table('product_location')
                    ->where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->increment('current_stock', $quantityToProduce);
            } else {
                // Crear nueva relación product_location si no existe
                DB::table('product_location')->insert([
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'current_stock' => $quantityToProduce,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Crear detalle de movimiento para el producto terminado (suma)
            MovementDetail::create([
                'movement_id' => $this->id,
                'product_id' => $productId,
                'quantity' => $quantityToProduce, // Positivo porque se suma
                'unit_cost' => 0,
                'total_cost' => 0,
            ]);

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Revertir la producción: sumar ingredientes y restar producto final
     */
    public function revertProduction(): bool
    {
        DB::beginTransaction();
        try {
            // Obtener el detalle principal para saber qué producto se produjo
            $mainDetail = $this->details()->first();

            if (!$mainDetail) {
                throw new Exception("No se encontró el detalle de producción");
            }

            $productId = $mainDetail->product_id;
            $quantityProduced = $mainDetail->quantity;

            // Obtener el producto que se produjo
            $product = Product::with('productIngredients')->findOrFail($productId);

            $locationId = $this->location_destination_id;

            // Restar producto terminado del inventario
            $productLocation = DB::table('product_location')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->first();

            if (!$productLocation || $productLocation->current_stock < $quantityProduced) {
                throw new Exception("No hay suficiente stock del producto terminado para revertir la producción");
            }

            DB::table('product_location')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->decrement('current_stock', $quantityProduced);

            // Devolver ingredientes al inventario
            foreach ($product->productIngredients as $productIngredient) {
                $ingredientId = $productIngredient->ingredient_id;
                $quantityToReturn = $productIngredient->quantity * $quantityProduced;

                $ingredientLocation = DB::table('product_location')
                    ->where('product_id', $ingredientId)
                    ->where('location_id', $locationId)
                    ->first();

                if ($ingredientLocation) {
                    DB::table('product_location')
                        ->where('product_id', $ingredientId)
                        ->where('location_id', $locationId)
                        ->increment('current_stock', $quantityToReturn);
                } else {
                    // Crear nueva relación product_location si no existe
                    DB::table('product_location')->insert([
                        'product_id' => $ingredientId,
                        'location_id' => $locationId,
                        'current_stock' => $quantityToReturn,
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Eliminar los detalles del movimiento
            $this->details()->delete();

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
