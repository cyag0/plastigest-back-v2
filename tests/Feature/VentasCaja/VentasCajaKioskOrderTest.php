<?php

namespace Tests\Feature\VentasCaja;

use App\Models\ActivityLog;
use App\Models\SalesOrder;
use App\Services\CashRegisterSessionService;
use App\Services\KioskCatalog;
use App\Services\KioskOrderService;
use App\Services\SalesOrderStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pedidos del kiosko (POST kiosk/orders): el cliente arma su pedido y paga
 * en caja. Queda pendiente, con su número corto y la mercancía apartada, a
 * precio de catálogo aunque la app mande otros precios.
 */
class VentasCajaKioskOrderTest extends KioskTestCase
{
    public function test_it_creates_a_pending_order_at_catalog_prices(): void
    {
        $water = $this->createProduct(['name' => 'Agua de coco 1 litro', 'sale_price' => 65], 100, 'Litro');
        $twelve = $this->package($water, 12, 700, 'Paquete 12');
        $kiosk = $this->kioskUser();

        // Precios y total de la app: no cuentan.
        $response = $this->kioskOrder($kiosk, [
            $this->item($water, 2) + ['unit_price' => 1, 'price' => 1],
            $this->item($water, 1, $twelve) + ['unit_price' => 1, 'price' => 1],
        ], ['customer_name' => '  Juan   Pérez  ', 'total' => 1])->assertCreated();

        $order = SalesOrder::sole();
        $this->assertSame(['id', 'ticket_number', 'order_number', 'customer_name', 'total', 'created_at', 'expires_at', 'items'], array_keys($response->json('data')));
        $response->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.ticket_number', 1)
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.customer_name', 'Juan Pérez')
            ->assertJsonPath('data.created_at', $order->created_at->toISOString())
            ->assertJsonPath('data.expires_at', $order->created_at->copy()->addMinutes(120)->toISOString());
        $this->assertEquals(830, $response->json('data.total'));
        $this->assertSame(['product_id', 'package_id', 'name', 'option', 'quantity', 'unit_price', 'line_total'], array_keys($response->json('data.items.0')));
        $this->assertEquals([
            ['product_id' => $water->id, 'package_id' => null, 'name' => 'Agua de coco 1 litro', 'option' => 'Litro', 'quantity' => 2, 'unit_price' => 65, 'line_total' => 130],
            ['product_id' => $water->id, 'package_id' => $twelve->id, 'name' => 'Agua de coco 1 litro', 'option' => 'Paquete 12', 'quantity' => 1, 'unit_price' => 700, 'line_total' => 700],
        ], $response->json('data.items'));

        // Un pedido como cualquier otro: de mostrador, pendiente, sin venta.
        $this->assertSame('kiosk', $order->channel->value);
        $this->assertSame('counter', $order->service_mode->value);
        $this->assertSame('pending', $order->status->value);
        $this->assertNull($order->sale_id);
        $this->assertSame($this->location->id, $order->location_id);
        $this->assertSame($kiosk->id, $order->created_by);
        $this->assertSame('Juan Pérez', $order->customer_name_snapshot);
        $this->assertEquals(830, $order->subtotal);
        $this->assertEquals(830, $order->total_amount);
        $details = $order->details()->orderBy('id')->get();
        $this->assertEquals([65, 700], $details->map(fn($d) => (float) $d->unit_price)->all());
        $this->assertEquals([130, 700], $details->map(fn($d) => (float) $d->line_subtotal)->all());
        $this->assertEquals([130, 700], $details->map(fn($d) => (float) $d->line_total)->all());
        $this->assertSame([$water->unit_id, $water->unit_id], $details->pluck('unit_id')->all(), 'el paquete va en la unidad del producto');

        // Pedidos lo muestra con su número corto.
        $this->actingInBranch($this->user)->getJson("/api/auth/admin/sales-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.ticket_number', 1)
            ->assertJsonPath('data.channel', 'kiosk');
    }

    public function test_it_reserves_the_stock(): void
    {
        $water = $this->createProduct(['sale_price' => 65], 30, 'Litro');
        $twelve = $this->package($water, 12, 700);
        $kiosk = $this->kioskUser();

        $this->kioskOrder($kiosk, [$this->item($water, 2), $this->item($water, 1, $twelve)])->assertCreated();

        $this->assertEquals(14, $this->reservedStock($water));
        $this->assertEquals(30, $this->stockOf($water), 'apartar no descuenta la existencia');
        $order = SalesOrder::sole();
        $this->assertNotNull($order->reserved_at);
        $this->assertEquals([2, 12], $order->details()->orderBy('id')->get()->map(fn($d) => (float) $d->reserved_quantity_base)->all());
        // En la bitácora, solo el alta con su total (apartar no es una edición).
        $this->assertSame(
            ['Registró el pedido ' . $order->order_number . ' por $830.00'],
            ActivityLog::where('subject_type', SalesOrder::class)->pluck('description')->all(),
        );

        // El kiosko y el punto de venta ya no lo ofrecen.
        $product = collect($this->catalog($kiosk)->json('data.products'))->firstWhere('id', $water->id);
        $this->assertEquals(16, $product['available']);
        $this->assertSame(1, $product['packages'][0]['available']);
        $this->posSale($this->cashier(), [['product_id' => $water->id, 'quantity' => 17]], [['method' => 'card', 'amount' => 1105]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock');
    }

    public function test_short_stock_is_a_422_with_what_is_left_of_each_option(): void
    {
        $water = $this->createProduct(['name' => 'Agua de coco', 'sale_price' => 65], 20, 'Litro');
        $twelve = $this->package($water, 12, 700);
        $this->setReserved($water, 3);
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 10);
        $kiosk = $this->kioskUser();

        // 6 sueltos + un paquete de 12 = 18 y quedan 17; el coco sí alcanza.
        $response = $this->kioskOrder($kiosk, [$this->item($water, 6), $this->item($coco, 2), $this->item($water, 1, $twelve)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', 'Ya no alcanza: Agua de coco.');
        $this->assertEquals([
            ['product_id' => $water->id, 'package_id' => null, 'available' => 17],
            ['product_id' => $water->id, 'package_id' => $twelve->id, 'available' => 1],
        ], $response->json('items'));

        $this->assertSame(0, SalesOrder::count());
        $this->assertEquals(3, $this->reservedStock($water), 'no se apartó nada');
        $this->assertEquals(0, $this->reservedStock($coco));

        // El número tampoco se gastó.
        $this->kioskOrder($kiosk, [$this->item($water, 5)])->assertCreated()->assertJsonPath('data.ticket_number', 1);
    }

    public function test_the_same_key_returns_the_same_order(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $kiosk = $this->kioskUser();
        $key = ['client_request_id' => 'k-' . Str::uuid(), 'customer_name' => 'Ana'];

        $first = $this->kioskOrder($kiosk, [$this->item($coco, 2)], $key)->assertCreated();
        $second = $this->kioskOrder($kiosk, [$this->item($coco, 2)], $key)->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, SalesOrder::count());
        $this->assertEquals(2, $this->reservedStock($coco), 'se apartó una vez');

        // La llave en otra sucursal, o de un pedido que no es del kiosko: no
        // se devuelve ese pedido ni se crea otro.
        $branch = $this->createBranch();
        $this->setStock($coco, $branch, 10);
        $this->kioskOrder($this->kioskUser($branch), [$this->item($coco, 1)], $key, $branch)
            ->assertStatus(422)
            ->assertJsonPath('code', 'client_request_conflict');

        $this->actingInBranch($this->user)->postJson('/api/auth/admin/sales-orders', [
            'channel' => 'phone', 'service_mode' => 'counter',
            'details' => [$this->orderLine($coco, 1)],
            'client_request_id' => 'k-pedido-por-telefono',
        ])->assertOk();
        $this->kioskOrder($kiosk, [$this->item($coco, 1)], ['client_request_id' => 'k-pedido-por-telefono'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'client_request_conflict');

        $this->assertSame(2, SalesOrder::count());
        $this->assertEquals(2, $this->reservedStock($coco));
    }

    public function test_a_simultaneous_duplicate_answers_with_the_order_that_won(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $kiosk = $this->kioskUser();
        $key = 'k-' . Str::uuid();
        // El ganador entra directo por el servicio (sin pasar por la ruta, que
        // guardaría el controlador con el servicio de verdad).
        [$winner] = app(KioskOrderService::class)->create($this->company->id, $this->location->id, $kiosk->id, $key, null, [$this->item($coco, 2)]);
        $winner = $winner->id;

        // La revisión previa no vio al ganador (llegó justo después): el
        // índice único frena el alta, se revierte completa y se responde aquel.
        $this->app->instance(KioskOrderService::class, new class(
            app(KioskCatalog::class),
            app(SalesOrderStockService::class),
            app(CashRegisterSessionService::class),
        ) extends KioskOrderService {
            private bool $missedOnce = false;

            protected function orderForRequest(int $companyId, int $locationId, string $requestId): ?SalesOrder
            {
                if (!$this->missedOnce) {
                    $this->missedOnce = true;

                    return null;
                }

                return parent::orderForRequest($companyId, $locationId, $requestId);
            }
        });

        $this->kioskOrder($kiosk, [$this->item($coco, 3)], ['client_request_id' => $key])
            ->assertOk()
            ->assertJsonPath('data.id', $winner);

        $this->assertSame(1, SalesOrder::count());
        $this->assertEquals(2, $this->reservedStock($coco), 'el perdedor no apartó nada');
        $this->kioskOrder($kiosk, [$this->item($coco, 1)])->assertCreated()->assertJsonPath('data.ticket_number', 2);
    }

    public function test_the_number_counts_per_branch_and_per_day(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $coco = $this->createProduct(['sale_price' => 30], 100);
        $branch = $this->createBranch();
        $this->setStock($coco, $branch, 100);
        $kiosk = $this->kioskUser();
        $branchKiosk = $this->kioskUser($branch);
        $number = fn($user, $location = null) => $this->kioskOrder($user, [$this->item($coco, 1)], [], $location)
            ->assertCreated()
            ->json('data.ticket_number');

        $this->assertSame(1, $number($kiosk));
        $this->assertSame(2, $number($kiosk));
        $this->assertSame(1, $number($branchKiosk, $branch));
        $this->assertSame(3, $number($kiosk));

        $this->travel(1)->days();
        $this->assertSame(1, $number($kiosk));
        $this->assertSame(1, $number($branchKiosk, $branch));
        $this->assertSame(2, $number($kiosk));
    }

    public function test_it_needs_sales_kiosk(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);

        $this->kioskOrder($this->cashier([], false), [$this->item($coco, 1)])->assertForbidden();
        $taker = $this->createBranchUser($this->location, ['sales_orders_create', 'sales_orders_update', 'products_list'])->fresh();
        $this->kioskOrder($taker, [$this->item($coco, 1)])->assertForbidden();

        $this->assertSame(0, SalesOrder::count());
        $this->assertEquals(0, $this->reservedStock($coco));
    }

    public function test_what_the_kiosk_does_not_sell_is_rejected(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'sale_price' => 30], 10);
        $inactive = $this->createProduct(['name' => 'Cocada', 'sale_price' => 20], 10);
        DB::table('product_location')->where('product_id', $inactive->id)->update(['active' => false]);
        $cookie = $this->createProduct(['name' => 'Galleta', 'for_sale' => false, 'sale_price' => 0], 50);
        $unpriced = $this->package($cookie, 4, null);
        $priced = $this->package($cookie, 16, 80);
        $foreignPackage = $this->package($this->createProduct(['sale_price' => 30], 10), 2, 50);
        [$otherCompany] = $this->createCompanyWithBranch();
        $foreign = $this->createProduct(['name' => 'Producto ajeno', 'company_id' => $otherCompany->id, 'sale_price' => 30], 10);
        $kiosk = $this->kioskUser();

        $this->kioskOrder($kiosk, [$this->item($coco, 1), $this->item($inactive, 1)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_unavailable')
            ->assertJsonPath('message', 'Ya no está disponible: Cocada. Quítalo de tu pedido para continuar.')
            ->assertJsonPath('items', [['product_id' => $inactive->id, 'package_id' => null, 'available' => 0]]);

        // La pieza que no se vende suelta, el paquete sin precio, el paquete de
        // otro producto y el producto de otra empresa (sin nombrarlo).
        $this->kioskOrder($kiosk, [
            $this->item($cookie, 1),
            $this->item($cookie, 1, $unpriced),
            $this->item($coco, 1, $foreignPackage),
            $this->item($foreign, 1),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_unavailable')
            ->assertJsonPath('message', 'Ya no está disponible: Coco entero, Galleta. Quítalos de tu pedido para continuar.')
            ->assertJsonCount(4, 'items');

        $this->assertSame(0, SalesOrder::count());
        $this->assertEquals(0, $this->reservedStock($coco));

        // El paquete con precio sí se vende.
        $this->kioskOrder($kiosk, [$this->item($cookie, 2, $priced)])->assertCreated()->assertJsonPath('data.total', 160);
        $this->assertEquals(32, $this->reservedStock($cookie));
    }

    public function test_the_request_is_validated(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 1000);
        $kiosk = $this->kioskUser();

        $this->kioskOrder($kiosk, [$this->item($coco, 1)], ['client_request_id' => null])
            ->assertStatus(422)->assertJsonValidationErrors('client_request_id');
        $this->kioskOrder($kiosk, [])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->kioskOrder($kiosk, [$this->item($coco, 100)])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->kioskOrder($kiosk, [$this->item($coco, 0)])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->kioskOrder($kiosk, [['product_id' => $coco->id, 'quantity' => 1.5]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->kioskOrder($kiosk, array_fill(0, 41, $this->item($coco, 1)))->assertStatus(422)->assertJsonValidationErrors('items');
        $this->kioskOrder($kiosk, [$this->item($coco, 1)], ['customer_name' => str_repeat('a', 61)])
            ->assertStatus(422)->assertJsonValidationErrors('customer_name');
        $this->assertSame(0, SalesOrder::count());

        // 40 renglones, 99 de uno y un nombre de 60: sí.
        $this->kioskOrder($kiosk, array_merge([$this->item($coco, 99)], array_fill(0, 39, $this->item($coco, 1))), ['customer_name' => str_repeat('a', 60)])
            ->assertCreated()
            ->assertJsonCount(40, 'data.items');
        // Un nombre en blanco no se guarda.
        $this->kioskOrder($kiosk, [$this->item($coco, 1)], ['customer_name' => '   '])
            ->assertCreated()
            ->assertJsonPath('data.customer_name', null);
    }
}
