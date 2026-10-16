<?php

namespace Tests\Feature\VentasCaja;

use App\Models\SalesOrder;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Vencimiento de los pedidos del kiosko (kiosk:expire-orders, cada 10
 * minutos): los que nadie cobró después de kiosk.order_ttl_minutes (120) se
 * cancelan y liberan lo apartado. Los demás no se tocan.
 */
class VentasCajaKioskExpiryTest extends KioskTestCase
{
    public function test_it_cancels_and_releases_only_the_expired_ones(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $coco = $this->createProduct(['sale_price' => 30], 100);
        $branch = $this->createBranch();
        $this->setStock($coco, $branch, 100);

        $old = $this->placeKioskOrder([$this->item($coco, 3)]);
        $oldInBranch = $this->placeKioskOrder([$this->item($coco, 2)], $branch);
        $oldCharged = $this->placeKioskOrder([$this->item($coco, 1)]);
        $phoneOrder = $this->makeOrder($coco, 1, 30);
        $this->posSale($this->cashier(), [['product_id' => $coco->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 30]], ['kiosk_order_id' => $oldCharged])
            ->assertCreated();
        $this->travel(30)->minutes();
        $recent = $this->placeKioskOrder([$this->item($coco, 4)]);

        // 11:05: los de las 9:00 ya pasaron sus 120 minutos; el de las 9:30, no.
        $this->travelTo(now()->setTime(11, 5));
        $this->artisan('kiosk:expire-orders')->expectsOutputToContain('Pedidos del kiosko vencidos: 2')->assertExitCode(0);

        foreach ([$old, $oldInBranch] as $id) {
            $order = SalesOrder::find($id);
            $this->assertSame('cancelled', $order->status->value);
            $this->assertNotNull($order->cancelled_at);
            $this->assertNull($order->reserved_at);
            $this->assertSame('expired', $order->content['cancel_reason']);
            $this->assertEquals(0, $order->details()->sum('reserved_quantity_base'));
        }
        $this->assertSame('delivered', SalesOrder::find($oldCharged)->status->value);
        $this->assertSame('pending', SalesOrder::find($recent)->status->value);
        $this->assertSame('pending', $phoneOrder->fresh()->status->value, 'un pedido que no es del kiosko no vence');
        $this->assertEquals(4, $this->reservedStock($coco), 'solo sigue apartado el reciente');
        $this->assertEquals(0, $this->reservedStock($coco, $branch));
        $this->assertEquals(99, $this->stockOf($coco), 'vencer no mueve la existencia');

        // Otra corrida no hace nada más.
        $this->artisan('kiosk:expire-orders')->expectsOutputToContain('Pedidos del kiosko vencidos: 0')->assertExitCode(0);

        // A las 11:31 vence el de las 9:30.
        $this->travelTo(now()->setTime(11, 31));
        $this->artisan('kiosk:expire-orders')->expectsOutputToContain('Pedidos del kiosko vencidos: 1')->assertExitCode(0);
        $this->assertSame('cancelled', SalesOrder::find($recent)->status->value);
        $this->assertEquals(0, $this->reservedStock($coco));
    }

    public function test_the_time_limit_comes_from_the_config(): void
    {
        config(['kiosk.order_ttl_minutes' => 30]);
        $this->travelTo(now()->setTime(9, 0));
        $coco = $this->createProduct(['sale_price' => 30], 10);

        $response = $this->kioskOrder($this->kioskUser(), [$this->item($coco, 1)])->assertCreated();
        $this->assertSame(now()->addMinutes(30)->toISOString(), $response->json('data.expires_at'));

        $this->travel(29)->minutes();
        $this->artisan('kiosk:expire-orders')->expectsOutputToContain('Pedidos del kiosko vencidos: 0');
        $this->assertEquals(1, $this->reservedStock($coco));

        $this->travel(2)->minutes();
        $this->artisan('kiosk:expire-orders')->expectsOutputToContain('Pedidos del kiosko vencidos: 1');
        $this->assertEquals(0, $this->reservedStock($coco));
    }

    public function test_it_runs_every_ten_minutes_without_overlapping(): void
    {
        // schedule:list carga routes/console.php.
        $this->artisan('schedule:list')->expectsOutputToContain('kiosk:expire-orders')->assertExitCode(0);

        $event = collect(app(Schedule::class)->events())
            ->first(fn($event) => str_contains((string) $event->command, 'kiosk:expire-orders'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt, 'el candado no se queda puesto 24 h si una corrida se interrumpe');
    }
}
