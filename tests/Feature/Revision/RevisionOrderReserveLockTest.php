<?php

namespace Tests\Feature\Revision;

use App\Enums\SalesOrderStatus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\VentasCaja\VentasCajaTestCase;

/**
 * "Preparar" revisaba el disponible (existencia - apartado) con una lectura
 * sin bloqueo y bloqueaba las existencias después: dos "preparar" simultáneos
 * de pedidos distintos veían el mismo disponible y apartaban los dos (en
 * REPEATABLE READ esa lectura es de la foto de la transacción). Ahora el
 * disponible se lee con las filas ya bloqueadas, que da su último valor.
 *
 * Dos transacciones simultáneas no se pueden intercalar en una prueba de un
 * solo proceso: se revisa que la lectura que decide sea la bloqueada.
 */
class RevisionOrderReserveLockTest extends VentasCajaTestCase
{
    public function test_prepare_reads_the_available_stock_under_the_lock(): void
    {
        $product = $this->createProduct(['sale_price' => 20], 10);
        $order = $this->makeOrder($product, 4, 20, 'pending', 'delivery');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales-orders/{$order->id}/prepare")->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $availabilityReads = $queries->filter(fn(string $sql) => str_starts_with(strtolower(ltrim($sql)), 'select')
            && str_contains($sql, 'product_location') && str_contains($sql, 'current_stock') && str_contains($sql, 'reserved_stock'));

        $this->assertNotEmpty($availabilityReads, 'preparar no leyó el disponible');
        foreach ($availabilityReads as $sql) {
            $this->assertStringContainsString('for update', strtolower($sql), "el disponible se leyó sin bloqueo: {$sql}");
        }
        $this->assertEquals(4, $this->reservedStock($product));
    }

    public function test_a_second_prepare_cannot_reserve_stock_that_is_already_reserved(): void
    {
        $product = $this->createProduct(['sale_price' => 20], 10);
        $first = $this->makeOrder($product, 10, 20, 'pending', 'delivery');
        $second = $this->makeOrder($product, 1, 20, 'pending', 'delivery');

        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales-orders/{$first->id}/prepare")->assertOk();
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales-orders/{$second->id}/prepare")->assertStatus(422);

        $this->assertEquals(10, $this->reservedStock($product));
        $this->assertSame(SalesOrderStatus::PENDING, $second->fresh()->status);
    }
}
