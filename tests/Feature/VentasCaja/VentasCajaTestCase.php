<?php

namespace Tests\Feature\VentasCaja;

use App\Models\Admin\Location;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\User;
use App\Services\CashRegisterSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Base de las pruebas de ventas y caja (punto de venta, pantalla de ventas
 * anterior, pedidos, turnos y gastos): empresa con todos los permisos para
 * el usuario del contexto y helpers para cajas, turnos, ventas y pedidos.
 */
abstract class VentasCajaTestCase extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Cajero: vende en el POS y abre/cierra su turno; no administra. */
    protected const CASHIER = ['sales_pos', 'sales_list', 'sales_read', 'products_list', 'products_read'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        // Releer para traer is_active (default de la columna): el middleware
        // rechaza cuentas inactivas.
        $this->user = $this->user->fresh();
    }

    protected function register(?Location $location = null): CashRegister
    {
        $location ??= $this->location;

        return CashRegister::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'name' => 'Caja ' . uniqid(),
            'code' => 'C' . uniqid(),
            'is_active' => true,
        ]);
    }

    /** Turno abierto del usuario en una caja nueva de la sucursal. */
    protected function openShiftFor(User $user, ?Location $location = null, float $openingAmount = 500): CashRegisterSession
    {
        return app(CashRegisterSessionService::class)->open($this->register($location), $openingAmount, $user);
    }

    /** Cajero de la sucursal (solo esa sucursal), con turno abierto si se pide. */
    protected function cashier(array $extraPermissions = [], bool $withShift = true, ?Location $location = null): User
    {
        $location ??= $this->location;
        $cashier = $this->createBranchUser($location, array_merge(self::CASHIER, $extraPermissions))->fresh();

        if ($withShift) {
            $this->openShiftFor($cashier, $location);
        }

        return $cashier;
    }

    protected function posSale(User $user, array $details, array $payments, array $extra = [], ?Location $location = null): TestResponse
    {
        return $this->actingInBranch($user, $location)
            ->postJson('/api/auth/admin/pos/sales', array_merge(['details' => $details, 'payments' => $payments], $extra));
    }

    /** Venta de la pantalla anterior (POST sales, formv2). */
    protected function legacySale(User $user, array $payload, ?Location $location = null): TestResponse
    {
        return $this->actingInBranch($user, $location)->postJson('/api/auth/admin/sales', $payload);
    }

    /** Renglón de la pantalla anterior para un producto en su unidad base. */
    protected function legacyLine(Product $product, float|string $quantity, float|string|null $price = null): array
    {
        return [
            'product_id' => $product->id,
            'unit_id' => $product->unit_id,
            'quantity' => $quantity,
            'unit_price' => $price ?? $product->sale_price,
        ];
    }

    /** Pedido creado directo en la base (sin pasar por la API). */
    protected function makeOrder(Product $product, float $quantity, float $price, string $status = 'pending', string $mode = 'counter', ?Location $location = null): SalesOrder
    {
        $location ??= $this->location;

        $order = SalesOrder::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'order_number' => 'SOR-' . uniqid(),
            'order_date' => now()->toDateString(),
            'channel' => 'admin',
            'service_mode' => $mode,
            'status' => $status,
            'subtotal' => $quantity * $price,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $quantity * $price,
        ]);

        SalesOrderDetail::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'unit_id' => $product->unit_id,
            'requested_quantity' => $quantity,
            'unit_price' => $price,
            'line_subtotal' => $quantity * $price,
            'line_total' => $quantity * $price,
        ]);

        return $order;
    }

    /** Renglón de pedido (POST sales-orders) para un producto en su unidad base. */
    protected function orderLine(Product $product, float|string $quantity, float|string|null $price = null, ?int $packageId = null): array
    {
        return array_filter([
            'product_id' => $product->id,
            'package_id' => $packageId,
            'unit_id' => $product->unit_id,
            'requested_quantity' => $quantity,
            'unit_price' => $price ?? $product->sale_price,
        ], fn($v) => $v !== null);
    }

    protected function reservedStock(Product $product, ?Location $location = null): float
    {
        return (float) DB::table('product_location')
            ->where('product_id', $product->id)
            ->where('location_id', ($location ?? $this->location)->id)
            ->value('reserved_stock');
    }

    protected function setReserved(Product $product, float $reserved, ?Location $location = null): void
    {
        DB::table('product_location')
            ->where('product_id', $product->id)
            ->where('location_id', ($location ?? $this->location)->id)
            ->update(['reserved_stock' => $reserved]);
    }
}
