<?php

namespace Tests\Feature\VentasCaja;

use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Services\SaleService;
use App\Support\ClientRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cobros idempotentes (contrato C1): la app manda client_request_id (una
 * llave por intento de cobro) y la repite en cada reintento. Si la venta, el
 * pedido o el abono ya se registraron con esa llave, el servidor responde
 * 200 con el mismo cuerpo que el alta y no cobra otra vez. Antes un
 * reintento después de un timeout o de perder la red cobraba doble.
 */
class VentasCajaIdempotencyTest extends VentasCajaTestCase
{
    private function cashMovements(): int
    {
        return DB::table('cash_movements')->where('source_type', 'sale')->count();
    }

    public function test_pos_same_key_twice_charges_once_and_answers_the_same(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $cashier = $this->cashier();
        $key = (string) Str::uuid();
        $cart = [['product_id' => $coco->id, 'quantity' => 2]];
        $cash = [['method' => 'cash', 'amount' => 60, 'tendered' => 100]];

        $first = $this->posSale($cashier, $cart, $cash, ['client_request_id' => $key])->assertCreated();
        $second = $this->posSale($cashier, $cart, $cash, ['client_request_id' => $key])->assertOk();

        $this->assertSame($first->json(), $second->json(), 'mismo cuerpo que el alta');
        $this->assertEquals(40, $second->json('data.change'));
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, SalePayment::count());
        $this->assertSame(1, $this->cashMovements());
        $this->assertEquals(8, $this->stockOf($coco), 'el stock se descuenta una vez');
        $this->assertSame($key, Sale::sole()->client_request_id);
    }

    public function test_pos_different_keys_are_different_sales(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $cashier = $this->cashier();
        $cart = [['product_id' => $coco->id, 'quantity' => 1]];
        $card = [['method' => 'card', 'amount' => 30]];

        $this->posSale($cashier, $cart, $card, ['client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->posSale($cashier, $cart, $card, ['client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->posSale($cashier, $cart, $card)->assertCreated();

        $this->assertSame(3, Sale::count());
        $this->assertEquals(7, $this->stockOf($coco));
    }

    public function test_a_replay_after_closing_the_shift_still_returns_the_sale(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $cashier = $this->cashier();
        $key = (string) Str::uuid();
        $cart = [['product_id' => $coco->id, 'quantity' => 1]];
        $cash = [['method' => 'cash', 'amount' => 30]];

        $first = $this->posSale($cashier, $cart, $cash, ['client_request_id' => $key])->assertCreated();

        $shift = CashRegisterSession::where('user_id', $cashier->id)->sole();
        $this->actingInBranch($cashier)
            ->postJson("/api/auth/admin/cash-register-sessions/{$shift->id}/close", ['counted_cash' => 530])
            ->assertOk();

        $this->posSale($cashier, $cart, $cash, ['client_request_id' => $key])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, Sale::count());
    }

    public function test_lookup_by_key_returns_the_sale_or_404(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $cashier = $this->cashier();
        $key = (string) Str::uuid();

        $created = $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 30]], ['client_request_id' => $key])
            ->assertCreated();

        $found = $this->actingInBranch($cashier)->getJson("/api/auth/admin/pos/sales/request/{$key}")->assertOk();
        $this->assertSame($created->json('data'), $found->json('data'));

        $this->actingInBranch($cashier)->getJson('/api/auth/admin/pos/sales/request/' . Str::uuid())
            ->assertNotFound()
            ->assertJsonStructure(['message']);

        // Otra sucursal no la ve.
        $otherBranch = $this->createBranch();
        $otherCashier = $this->cashier([], false, $otherBranch);
        $this->actingInBranch($otherCashier, $otherBranch)->getJson("/api/auth/admin/pos/sales/request/{$key}")
            ->assertNotFound();

        // Mismo permiso que el punto de venta.
        $viewer = $this->createBranchUser($this->location, ['sales_list', 'sales_read'])->fresh();
        $this->actingInBranch($viewer)->getJson("/api/auth/admin/pos/sales/request/{$key}")->assertForbidden();
    }

    public function test_a_simultaneous_duplicate_is_resolved_by_the_unique_index(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $cashier = $this->cashier();
        $key = (string) Str::uuid();

        // Simula la carrera: justo antes de que esta petición guarde, otra con
        // la misma llave ya registró su venta (la comprobación previa no la vio).
        $this->app->instance(SaleService::class, new class extends SaleService {
            public bool $raced = false;

            public function create(array $data, ?\App\Models\CashRegisterSession $session = null): Sale
            {
                if (!$this->raced) {
                    $this->raced = true;
                    parent::create($data, $session);
                }

                return parent::create($data, $session);
            }
        });

        $response = $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 60]], ['client_request_id' => $key])
            ->assertOk();

        $this->assertSame(Sale::sole()->id, $response->json('data.id'), 'responde con la venta que ganó');
        $this->assertEquals(8, $this->stockOf($coco), 'la petición perdedora se revirtió completa');
        $this->assertSame(1, $this->cashMovements());
    }

    public function test_the_unique_index_rejects_a_repeated_key_and_rolls_the_sale_back(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $key = (string) Str::uuid();
        $data = [
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
            'client_request_id' => $key,
            'details' => [['product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'quantity' => 1, 'unit_price' => 30]],
            'payments' => [['method' => 'card', 'amount' => 30]],
        ];
        app(SaleService::class)->create($data);

        try {
            app(SaleService::class)->create($data);
            $this->fail('La llave repetida debió rechazarse.');
        } catch (QueryException $e) {
            $this->assertTrue(ClientRequest::isDuplicate($e));
        }

        $this->assertSame(1, Sale::count());
        $this->assertEquals(9, $this->stockOf($coco));
    }

    public function test_a_key_used_in_another_branch_is_a_conflict(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $otherBranch = $this->createBranch();
        $key = (string) Str::uuid();
        Sale::create([
            'company_id' => $this->company->id, 'location_id' => $otherBranch->id, 'user_id' => $this->user->id,
            'client_request_id' => $key, 'sale_date' => now()->toDateString(), 'status' => 'closed',
            'subtotal' => 30, 'tax' => 0, 'discount' => 0, 'total' => 30, 'paid_amount' => 30,
            'payment_method' => 'card', 'payment_status' => 'paid',
        ]);
        $cashier = $this->cashier();

        $this->posSale($cashier, [['product_id' => $coco->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 30]], ['client_request_id' => $key])
            ->assertStatus(422)
            ->assertJsonPath('code', 'client_request_conflict');
        $this->assertEquals(10, $this->stockOf($coco));
    }

    public function test_old_sales_screen_same_key_twice_is_one_sale(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $key = (string) Str::uuid();
        $payload = [
            'payment_method' => 'card',
            'details' => [$this->legacyLine($coco, 2)],
            'client_request_id' => $key,
        ];

        $first = $this->legacySale($this->user, $payload)->assertCreated();
        $second = $this->legacySale($this->user, $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.total'), $second->json('data.total'));
        $this->assertSame(1, Sale::count());
        $this->assertEquals(8, $this->stockOf($coco));
    }

    public function test_old_sales_screen_simultaneous_duplicate_answers_with_the_winner(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $key = (string) Str::uuid();
        $this->app->instance(SaleService::class, new class extends SaleService {
            public bool $raced = false;

            public function create(array $data, ?\App\Models\CashRegisterSession $session = null): Sale
            {
                if (!$this->raced) {
                    $this->raced = true;
                    parent::create($data, $session);
                }

                return parent::create($data, $session);
            }
        });

        $this->legacySale($this->user, [
            'payment_method' => 'card',
            'details' => [$this->legacyLine($coco, 1)],
            'client_request_id' => $key,
        ])->assertOk()->assertJsonPath('data.id', Sale::sole()->id);

        $this->assertEquals(9, $this->stockOf($coco));
    }

    public function test_order_creation_with_the_same_key_is_one_order(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $payload = [
            'channel' => 'phone', 'service_mode' => 'counter',
            'details' => [$this->orderLine($coco, 2)],
            'client_request_id' => (string) Str::uuid(),
        ];

        $first = $this->actingInBranch($this->user)->postJson('/api/auth/admin/sales-orders', $payload)->assertOk();
        $second = $this->actingInBranch($this->user)->postJson('/api/auth/admin/sales-orders', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.order_number'), $second->json('data.order_number'));
        $this->assertSame(1, SalesOrder::count());
    }

    public function test_order_checkout_with_the_same_key_charges_once(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $order = $this->makeOrder($coco, 2, 30);
        $this->openShiftFor($this->user);
        $payload = ['payment_method' => 'cash', 'paid_amount' => 100, 'client_request_id' => (string) Str::uuid()];

        $first = $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", $payload)->assertOk();
        $second = $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", $payload)->assertOk();

        $this->assertSame($first->json('sale'), $second->json('sale'));
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, $this->cashMovements());
        $this->assertEquals(8, $this->stockOf($coco));

        // Otra llave sobre el mismo pedido ya cobrado sigue siendo un rechazo.
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales-orders/{$order->id}/checkout", ['payment_method' => 'card', 'client_request_id' => (string) Str::uuid()])
            ->assertStatus(422);
        $this->assertSame(1, Sale::count());
    }

    public function test_an_abono_with_the_same_key_is_registered_once(): void
    {
        $coco = $this->createProduct(['sale_price' => 30], 10);
        $saleId = $this->legacySale($this->user, [
            'payment_method' => 'credit',
            'details' => [$this->legacyLine($coco, 4)],
        ])->assertCreated()->json('data.id');
        $key = (string) Str::uuid();
        $abono = ['amount' => 50, 'payment_method' => 'card', 'client_request_id' => $key];

        $first = $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales/{$saleId}/add-payment", $abono)->assertOk();
        // El reintento llega aunque ya no quepa otro abono de 100 en el saldo.
        $second = $this->actingInBranch($this->user)->postJson("/api/auth/admin/sales/{$saleId}/add-payment", ['amount' => 100] + $abono)->assertOk();

        $this->assertSame($first->json('data.paid_amount'), $second->json('data.paid_amount'));
        $this->assertSame(1, SalePayment::where('sale_id', $saleId)->count());
        $this->assertEquals(50, Sale::find($saleId)->paid_amount);
        $this->assertSame(1, DB::table('cash_movements')->where('source_id', $saleId)->where('type', 'income')->count());

        // Otra llave es otro abono.
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/sales/{$saleId}/add-payment", ['amount' => 20, 'payment_method' => 'card', 'client_request_id' => (string) Str::uuid()])
            ->assertOk();
        $this->assertSame(2, SalePayment::where('sale_id', $saleId)->count());
        $this->assertEquals(70, Sale::find($saleId)->paid_amount);
    }
}
