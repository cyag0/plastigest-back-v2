<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Admin\Location;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\StockLedgerEntry;
use App\Notifications\NotificationEngine;
use App\Support\StockContext;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class MovementService
{
    public function quantityInProductUnit(int $productId, int $unitId, float $quantity, ?int $packageId = null): float
    {
        return $this->convertToProductUnit($productId, $unitId, $quantity, $packageId);
    }

    /**
     * Incrementar stock en una ubicación
     *
     * @param int $locationId ID de la ubicación
     * @param int $productId ID del producto
     * @param int $unitId ID de la unidad en la que viene la cantidad
     * @param float $quantity Cantidad a incrementar (en la unidad especificada)
     * @param int|null $packageId ID del paquete (opcional)
     * @param StockContext|null $context Por qué se mueve el stock (para la trazabilidad)
     * @return void
     * @throws Exception
     */
    public function increment(int $locationId, int $productId, int $unitId, float $quantity, ?int $packageId = null, ?StockContext $context = null): void
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

            // Se lee bloqueando la fila: el asiento del libro mayor tiene que
            // registrar el stock exacto de antes y después de ESTE cambio, y sin
            // el bloqueo dos movimientos simultáneos se pisarían los saldos.
            $previousStock = $this->lockCurrentStock($locationId, $productId);

            // Incrementar el stock
            $this->incrementStock($locationId, $productId, $quantityInProductUnit);

            $this->recordLedgerEntry(
                $locationId,
                $productId,
                StockLedgerEntry::DIRECTION_IN,
                $quantityInProductUnit,
                $previousStock,
                $previousStock + $quantityInProductUnit,
                $context,
            );

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
     * @param StockContext|null $context Por qué se mueve el stock (para la trazabilidad)
     * @return void
     * @throws InsufficientStockException Si no alcanza la existencia (responde 422)
     * @throws Exception
     */
    public function decrement(int $locationId, int $productId, int $unitId, float $quantity, ?int $packageId = null, ?StockContext $context = null): void
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

            // Mismo motivo que en increment(): se bloquea la fila antes de leer
            // el saldo para que el asiento no mienta bajo concurrencia.
            $previousStock = $this->lockCurrentStock($locationId, $productId);

            // Validar que hay suficiente stock (contra el saldo recién bloqueado)
            $this->validateStock($locationId, $productId, $quantityInProductUnit, $previousStock);

            // Decrementar el stock
            $this->decrementStock($locationId, $productId, $quantityInProductUnit);

            $this->recordLedgerEntry(
                $locationId,
                $productId,
                StockLedgerEntry::DIRECTION_OUT,
                $quantityInProductUnit,
                $previousStock,
                $previousStock - $quantityInProductUnit,
                $context,
            );

            $this->notifyIfLowStock($locationId, $productId, $previousStock, $previousStock - $quantityInProductUnit);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Aviso de stock bajo. Toda salida de inventario pasa por decrement()
     * (POS, ventas, producción, empaque, transferencias, ajustes, conteos), así
     * que aquí se cubren todas. Avisa una sola vez, cuando la existencia cruza
     * el mínimo de la sucursal o se agota; no en cada venta mientras siga baja.
     * El aviso sale al confirmarse la transacción (NotificationEngine).
     */
    protected function notifyIfLowStock(int $locationId, int $productId, float $before, float $after): void
    {
        try {
            $minimum = (float) DB::table('product_location')
                ->where('location_id', $locationId)
                ->where('product_id', $productId)
                ->value('minimum_stock');

            $crossedMinimum = $minimum > 0 && $before >= $minimum && $after < $minimum;
            $ranOut = $before > 0 && $after <= 0;

            if (!$crossedMinimum && !$ranOut) {
                return;
            }

            $product = Product::with('unit')->find($productId);
            $location = Location::find($locationId);
            if (!$product || !$location) {
                return;
            }

            NotificationEngine::dispatch('low_stock', (int) $location->company_id, [
                'product'       => $product,
                'location'      => $location,
                'current_stock' => round(max($after, 0), 3),
                'minimum_stock' => $minimum,
                'unit'          => $product->unit?->abbreviation,
            ]);
        } catch (\Throwable $e) {
            // Un aviso nunca debe impedir el movimiento de inventario.
            Log::warning('No se pudo evaluar el aviso de stock bajo', [
                'location_id' => $locationId,
                'product_id'  => $productId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stock actual de un producto en una sucursal, bloqueando la fila hasta el
     * fin de la transacción. Devuelve 0 si el producto todavía no tiene fila.
     */
    protected function lockCurrentStock(int $locationId, int $productId): float
    {
        $stock = DB::table('product_location')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->value('current_stock');

        return (float) ($stock ?? 0);
    }

    /**
     * Deja constancia del cambio en el libro mayor de existencias.
     *
     * Se hace aquí, y no en cada flujo, porque increment() y decrement() son el
     * único paso obligado de todo cambio de stock: registrarlo en los llamadores
     * era justamente lo que dejaba sin rastro a ventas, compras y empaque.
     */
    protected function recordLedgerEntry(
        int $locationId,
        int $productId,
        string $direction,
        float $quantity,
        float $previousStock,
        float $newStock,
        ?StockContext $context,
    ): void {
        $context ??= new StockContext();

        $companyId = Product::whereKey($productId)->value('company_id');

        if (!$companyId) {
            // Sin empresa no se puede clasificar el asiento. No se aborta el
            // movimiento por esto: el stock ya es correcto y perder el renglón
            // es preferible a tumbar una venta.
            Log::warning("No se registró el movimiento en el libro mayor: el producto ID {$productId} no tiene empresa.");

            return;
        }

        StockLedgerEntry::create([
            'company_id' => $companyId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'direction' => $direction,
            'reason' => $context->reason,
            'quantity' => round($quantity, 4),
            'previous_stock' => round($previousStock, 4),
            'new_stock' => round($newStock, 4),
            'unit_cost' => $context->unitCost,
            'reference_type' => $context->reference ? $context->reference::class : null,
            'reference_id' => $context->reference?->getKey(),
            'counterpart_location_id' => $context->counterpartLocationId,
            'user_id' => $context->resolvedUserId(),
            'notes' => $context->notes,
        ]);
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

        $fromUnit = Unit::findOrFail($fromUnitId);
        $toUnit = Unit::findOrFail($toUnitId);

        // Validar compatibilidad por tipo (mass, volume, quantity, etc.)
        if ((string) $fromUnit->unit_type !== (string) $toUnit->unit_type) {
            throw new Exception("No se pueden convertir unidades de diferentes tipos (unidad {$fromUnitId} a unidad {$toUnitId})");
        }

        $fromFactor = (float) ($fromUnit->factor_to_base ?? 1);
        $toFactor = (float) ($toUnit->factor_to_base ?? 1);

        if ($fromFactor <= 0 || $toFactor <= 0) {
            throw new Exception("Factor de conversión inválido (unidad {$fromUnitId} a unidad {$toUnitId})");
        }

        // Convertir de fromUnit a unidad base
        $quantityInBaseUnit = $quantity * $fromFactor;

        // Convertir de unidad base a toUnit
        $convertedQuantity = $quantityInBaseUnit / $toFactor;

        return $convertedQuantity;
    }

    /**
     * Validar que hay suficiente stock
     *
     * Se valida contra el saldo que decrement() ya leyó bloqueando la fila
     * (lockCurrentStock). Antes se volvía a leer sin bloqueo, y en REPEATABLE
     * READ esa lectura puede devolver una foto anterior a una venta simultánea
     * ya confirmada: el rechazo terminaba saliendo del UPDATE condicional como
     * "No se pudo decrementar el stock..." (un 500 sin explicación).
     *
     * @param int $locationId ID de la ubicación
     * @param int $productId ID del producto
     * @param float $quantity Cantidad solicitada (en unidad base del producto)
     * @param float $currentStock Existencia actual, leída con la fila bloqueada
     * @return void
     * @throws InsufficientStockException
     */
    protected function validateStock(int $locationId, int $productId, float $quantity, float $currentStock): void
    {
        if ($currentStock < $quantity) {
            throw InsufficientStockException::forProduct($productId, $currentStock, $quantity, $locationId);
        }
    }

    /**
     * Decrementar stock en una ubicación
     *
     * @param int $locationId ID de la ubicación
     * @param int $productId ID del producto
     * @param float $quantity Cantidad a decrementar (en unidad base del producto)
     * @return void
     * @throws InsufficientStockException
     * @throws Exception
     */
    protected function decrementStock(int $locationId, int $productId, float $quantity): void
    {
        Log::info("Intentando decrementar stock para producto ID {$productId} en ubicación ID {$locationId} por cantidad {$quantity}");

        $updated = DB::table('product_location')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->where('current_stock', '>=', $quantity)
            ->decrement('current_stock', $quantity);

        if (!$updated) {
            // Con la fila bloqueada y el saldo ya validado no debería pasar. Si
            // la causa es que no alcanza (o no hay fila), se informa como stock
            // insuficiente, con la existencia real; si no, es otro problema.
            $currentStock = DB::table('product_location')
                ->where('location_id', $locationId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->value('current_stock');

            if ($currentStock === null || (float) $currentStock < $quantity) {
                throw InsufficientStockException::forProduct($productId, (float) ($currentStock ?? 0), $quantity, $locationId);
            }

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
