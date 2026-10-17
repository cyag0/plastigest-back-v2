<?php

namespace Tests\Feature\ComprasProduccion;

use App\Enums\StockLedgerReason;
use App\Models\ActivityLog;
use App\Models\Admin\Location;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\PurchaseDetailV2;
use App\Models\PurchaseV2;
use App\Models\StockLedgerEntry;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use App\Services\CashRegisterSessionService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Compras: cada acción sobre una compra por id se limita a la empresa y a la
 * sucursal de quien la hace (quien ve todas las sucursales, a las de su
 * empresa). Antes confirmar, marcar en tránsito, cancelar y editar o quitar
 * renglones buscaban la compra solo por id.
 */
class ComprasProduccionPurchasesTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Encargado de compras de una sola sucursal. */
    private const BUYER = ['purchases_list', 'purchases_read', 'purchases_create', 'purchases_update', 'purchases_delete'];

    private Product $coco;
    private Location $pitillal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        // Releído para traer is_active: el middleware rechaza cuentas inactivas.
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);

        $this->coco = $this->createProduct(['name' => 'Coco'], 0);
        $this->pitillal = $this->createBranch(['name' => 'Pitillal']);
    }

    // ─── Ayudas ─────────────────────────────────────────────────────────────

    /**
     * Compra en el estado dado con renglones [cantidad, precio] de $product
     * (por defecto el coco de la empresa del contexto).
     */
    private function purchase(Location $location, string $status, array $lines = [[10, 15]], ?Product $product = null): PurchaseV2
    {
        $purchase = PurchaseV2::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'purchase_number' => PurchaseV2::generatePurchaseNumber(),
            'purchase_date' => now(),
            'status' => $status,
            'payment_method' => 'cash',
            'paid_from_cash' => true,
            'user_id' => $this->user->id,
        ]);

        foreach ($lines as [$quantity, $price]) {
            $purchase->details()->create([
                'product_id' => ($product ?? $this->coco)->id,
                'quantity' => $quantity,
                'unit_id' => $this->unitId('Pieza'),
                'unit_price' => $price,
            ]);
        }

        return $purchase->fresh('details');
    }

    /** Compra de otra empresa, con un producto de esa empresa. */
    private function foreignPurchase(string $status): PurchaseV2
    {
        [$company, $branch] = $this->createCompanyWithBranch();
        $product = $this->createProduct(['name' => 'Coco ajeno', 'company_id' => $company->id], 0);

        return $this->purchase($branch, $status, [[10, 15]], $product);
    }

    private function buyer(?Location $location = null): User
    {
        return $this->createBranchUser($location ?? $this->location, self::BUYER)->fresh();
    }

    /**
     * Todas las acciones por id sobre $purchase, con lo que piden. Cada una
     * debe responder 404 si la compra no es del usuario.
     *
     * @return array<string, array{0: string, 1: string, 2: array}>
     */
    private function actionsOn(PurchaseV2 $purchase): array
    {
        $detail = $purchase->details->first();
        $base = "/api/auth/admin/purchases-v2/{$purchase->id}";

        return [
            'ver' => ['getJson', $base, []],
            'confirmar' => ['postJson', "{$base}/confirm", []],
            'registrar recibida' => ['postJson', "{$base}/confirm", ['receive_now' => true]],
            'marcar en tránsito' => ['postJson', "{$base}/mark-in-transit", []],
            'recibir' => ['postJson', "{$base}/receive", ['details' => [['id' => $detail->id, 'quantity_received' => 10]]]],
            'cancelar' => ['postJson', "{$base}/cancel", []],
            'resolver discrepancia' => ['postJson', "{$base}/resolve-discrepancy", ['resolution' => 'no_action']],
            'url del pdf' => ['getJson', "{$base}/pdf-url", []],
            'editar renglón' => ['postJson', "/api/auth/admin/purchases-v2/details/{$detail->id}/update", ['quantity' => 999, 'price' => 0.01]],
            'quitar renglón' => ['deleteJson', "/api/auth/admin/purchases-v2/details/{$detail->id}", []],
            'eliminar' => ['deleteJson', $base, []],
        ];
    }

    private function assertUntouched(PurchaseV2 $before): void
    {
        $after = PurchaseV2::with('details')->findOrFail($before->id);

        $this->assertSame($before->status, $after->status, 'el estado no cambia');
        $this->assertEquals((float) $before->total, (float) $after->total, 'el total no cambia');
        $this->assertSame(
            $before->details->map(fn($d) => [$d->id, (float) $d->quantity, (float) $d->unit_price])->all(),
            $after->details->map(fn($d) => [$d->id, (float) $d->quantity, (float) $d->unit_price])->all(),
            'los renglones no cambian'
        );
    }

    // ─── Aislamiento ────────────────────────────────────────────────────────

    public function test_a_branch_buyer_cannot_touch_another_branch_purchase(): void
    {
        $buyer = $this->buyer();

        foreach ([PurchaseV2::STATUS_DRAFT, PurchaseV2::STATUS_ORDERED, PurchaseV2::STATUS_IN_TRANSIT] as $status) {
            $purchase = $this->purchase($this->pitillal, $status);

            foreach ($this->actionsOn($purchase) as $action => [$method, $url, $payload]) {
                $this->actingInBranch($buyer)->{$method}($url, $payload)
                    ->assertNotFound("{$action} una compra {$status} de otra sucursal");
            }

            $this->assertUntouched($purchase);
        }

        $this->assertEquals(0, $this->stockOf($this->coco, $this->pitillal), 'nadie recibió la compra ajena');
    }

    public function test_purchases_of_another_company_are_not_found_even_for_an_admin(): void
    {
        // $this->user ve todas las sucursales de SU empresa, no de otra.
        foreach ([PurchaseV2::STATUS_DRAFT, PurchaseV2::STATUS_IN_TRANSIT] as $status) {
            $purchase = $this->foreignPurchase($status);

            foreach ($this->actionsOn($purchase) as $action => [$method, $url, $payload]) {
                $this->actingInBranch($this->user)->{$method}($url, $payload)
                    ->assertNotFound("{$action} una compra {$status} de otra empresa");
            }

            $this->assertUntouched($purchase);
        }
    }

    public function test_an_all_branches_admin_can_still_handle_another_branch_purchase(): void
    {
        $purchase = $this->purchase($this->pitillal, PurchaseV2::STATUS_DRAFT);

        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/confirm")
            ->assertOk();

        $this->assertSame(PurchaseV2::STATUS_ORDERED, $purchase->fresh()->status);
    }

    public function test_confirm_saves_the_form_notes_and_keeps_them_when_not_sent(): void
    {
        $buyer = $this->buyer();

        $withNotes = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT);
        $this->actingInBranch($buyer)
            ->postJson("/api/auth/admin/purchases-v2/{$withNotes->id}/confirm", ['notes' => 'Entregar en bodega'])
            ->assertOk();
        $this->assertSame('Entregar en bodega', $withNotes->fresh()->notes);

        // Una app anterior no manda notas: se quedan las del borrador (las
        // que puso Reabastecimiento, por ejemplo).
        $suggested = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT);
        $suggested->update(['notes' => 'Sugerida por reabastecimiento']);
        $this->actingInBranch($buyer)
            ->postJson("/api/auth/admin/purchases-v2/{$suggested->id}/confirm")
            ->assertOk();
        $this->assertSame('Sugerida por reabastecimiento', $suggested->fresh()->notes);

        // Mandarlas vacías las borra.
        $cleared = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT);
        $cleared->update(['notes' => 'Vieja']);
        $this->actingInBranch($buyer)
            ->postJson("/api/auth/admin/purchases-v2/{$cleared->id}/confirm", ['notes' => ''])
            ->assertOk();
        $this->assertNull($cleared->fresh()->notes);
    }

    public function test_the_buyer_runs_their_own_branch_purchase_flow(): void
    {
        $buyer = $this->buyer();
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15], [2, 30]]);
        $base = "/api/auth/admin/purchases-v2/{$purchase->id}";
        [$first, $second] = $purchase->details->all();

        $this->actingInBranch($buyer)
            ->postJson("/api/auth/admin/purchases-v2/details/{$first->id}/update", ['quantity' => 12])
            ->assertOk()
            ->assertJsonPath('data.total', '240.00');

        $this->actingInBranch($buyer)
            ->deleteJson("/api/auth/admin/purchases-v2/details/{$second->id}")
            ->assertOk();
        $this->assertSame(1, PurchaseDetailV2::where('purchase_id', $purchase->id)->count());

        $this->actingInBranch($buyer)->postJson("{$base}/confirm")->assertOk();
        $this->assertSame(PurchaseV2::STATUS_ORDERED, $purchase->fresh()->status);

        // Confirmar dos veces ya no vuelve a mandar el pedido: la compra ya no
        // es borrador.
        $this->actingInBranch($buyer)->postJson("{$base}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Solo se puede confirmar una compra en borrador.');

        // Ya no es borrador: sus renglones no se tocan.
        $this->actingInBranch($buyer)
            ->postJson("/api/auth/admin/purchases-v2/details/{$first->id}/update", ['quantity' => 1])
            ->assertStatus(400);

        $this->actingInBranch($buyer)->postJson("{$base}/mark-in-transit")->assertOk();
        $this->assertSame(PurchaseV2::STATUS_IN_TRANSIT, $purchase->fresh()->status);
        $this->actingInBranch($buyer)->postJson("{$base}/mark-in-transit")->assertStatus(422);

        $this->actingInBranch($buyer)->postJson("{$base}/cancel", ['reason' => 'No llegó'])->assertOk();
        $this->assertSame(PurchaseV2::STATUS_CANCELLED, $purchase->fresh()->status);
        $this->actingInBranch($buyer)->postJson("{$base}/cancel")->assertStatus(422);

        $this->actingInBranch($buyer)->deleteJson($base)->assertOk();
        $this->assertNull(PurchaseV2::find($purchase->id));
    }

    public function test_only_drafts_and_cancelled_purchases_can_be_deleted(): void
    {
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_ORDERED);

        $this->actingInBranch($this->buyer())
            ->deleteJson("/api/auth/admin/purchases-v2/{$purchase->id}")
            ->assertStatus(422);

        $this->assertNotNull(PurchaseV2::find($purchase->id));
    }

    public function test_a_draft_only_takes_products_suppliers_and_packages_of_the_company(): void
    {
        [$other] = $this->createCompanyWithBranch();
        $foreign = $this->createProduct(['name' => 'Coco ajeno', 'company_id' => $other->id], 0);

        $line = fn(Product $product) => [
            'id' => "product_{$product->id}",
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_id' => $this->unitId('Pieza'),
            'price' => 10,
        ];

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/purchases-v2/upsert-draft', ['items' => [$line($foreign)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/purchases-v2/add-detail', [
                'product_id' => $foreign->id, 'quantity' => 1, 'unit_id' => $this->unitId('Pieza'), 'price' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');

        $this->assertSame(0, PurchaseV2::count(), 'no se creó ningún borrador');

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/purchases-v2/upsert-draft', ['items' => [$line($this->coco)]])
            ->assertOk();
    }

    // ─── Recepción ──────────────────────────────────────────────────────────

    private function receive(PurchaseV2 $purchase, array $lines, ?User $user = null)
    {
        return $this->actingInBranch($user ?? $this->buyer())
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/receive", ['details' => $lines]);
    }

    private function cashOut(PurchaseV2 $purchase): ?float
    {
        $amount = CashMovement::where('source_type', 'purchase_v2')->where('source_id', $purchase->id)->value('amount');

        return $amount === null ? null : (float) $amount;
    }

    /** [de, a] de cada cambio del historial de estados. */
    private function statusSteps(PurchaseV2 $purchase): array
    {
        return collect($purchase->fresh()->metadata ?? [])->map(fn(array $entry) => [$entry['from'], $entry['to']])->all();
    }

    private function openTasks(PurchaseV2 $purchase, string $type): int
    {
        return Task::where('related_type', PurchaseV2::class)
            ->where('related_id', $purchase->id)
            ->where('type', $type)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();
    }

    private function purchaseLedger(PurchaseV2 $purchase)
    {
        return StockLedgerEntry::where('reference_type', PurchaseV2::class)->where('reference_id', $purchase->id)->get();
    }

    public function test_a_short_delivery_books_in_the_register_only_what_arrived(): void
    {
        $water = $this->createProduct(['name' => 'Agua'], 0);
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_IN_TRANSIT, [[100, 15]]);
        $waterLine = $purchase->details()->create([
            'product_id' => $water->id, 'quantity' => 10, 'unit_id' => $this->unitId('Pieza'), 'unit_price' => 20,
        ]);
        $cocoLine = $purchase->details->first();

        // De 100 cocos llegaron 90 y el agua no llegó.
        $this->receive($purchase, [
            ['id' => $cocoLine->id, 'quantity_received' => 90],
            ['id' => $waterLine->id, 'quantity_received' => 0],
        ])->assertOk();

        $this->assertSame(PurchaseV2::STATUS_RECEIVED, $purchase->fresh()->status);
        $this->assertEquals(90, $this->stockOf($this->coco));
        $this->assertEquals(0, $this->stockOf($water));
        $this->assertEquals(1350, $this->cashOut($purchase), 'se paga lo recibido: 90 × 15, no los 1700 pedidos');
        $this->assertEquals(1700, (float) $purchase->fresh()->total, 'el total sigue siendo lo pedido');
        $this->assertSame(
            1,
            StockLedgerEntry::where('reference_type', PurchaseV2::class)->where('reference_id', $purchase->id)->count(),
            'lo que no llegó no deja un movimiento en cero'
        );
        $this->assertNotNull(app(TaskService::class)->findOpenPurchaseDiscrepancyTask($purchase->fresh()));
    }

    public function test_more_than_was_ordered_cannot_be_received(): void
    {
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_IN_TRANSIT, [[100, 15]]);

        $this->receive($purchase, [['id' => $purchase->details->first()->id, 'quantity_received' => 101]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.quantity_received')
            ->assertJsonPath('message', fn($message) => str_contains($message, 'Coco') && str_contains($message, '100'));

        $this->assertSame(PurchaseV2::STATUS_IN_TRANSIT, $purchase->fresh()->status);
        $this->assertEquals(0, $this->stockOf($this->coco));
        $this->assertNull($this->cashOut($purchase));
    }

    public function test_a_line_of_another_purchase_cannot_be_received_here(): void
    {
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_IN_TRANSIT, [[10, 15]]);
        $other = $this->purchase($this->location, PurchaseV2::STATUS_IN_TRANSIT, [[50, 15]]);

        $this->receive($purchase, [['id' => $other->details->first()->id, 'quantity_received' => 50]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.id');

        $this->assertSame(PurchaseV2::STATUS_IN_TRANSIT, $purchase->fresh()->status);
        $this->assertNull($other->details()->first()->quantity_received);
        $this->assertEquals(0, $this->stockOf($this->coco));
    }

    public function test_a_package_line_is_received_and_paid_by_package(): void
    {
        $box = ProductPackage::create([
            'product_id' => $this->coco->id,
            'company_id' => $this->company->id,
            'package_name' => 'Caja con 12',
            'barcode' => 'CAJA-12-' . uniqid(),
            'quantity_per_package' => 12,
        ]);
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_IN_TRANSIT, []);
        $line = $purchase->details()->create([
            'product_id' => $this->coco->id, 'package_id' => $box->id, 'quantity' => 2,
            'unit_id' => $this->unitId('Pieza'), 'unit_price' => 100,
        ]);

        $this->receive($purchase, [['id' => $line->id, 'quantity_received' => 1]])->assertOk();

        $this->assertEquals(12, $this->stockOf($this->coco), 'una caja son 12 cocos');
        $this->assertEquals(100, $this->cashOut($purchase), 'se paga una caja');
    }

    public function test_an_ordered_purchase_is_received_without_marking_it_in_transit(): void
    {
        $buyer = $this->buyer();
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15]]);

        $this->actingInBranch($buyer)
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/confirm")
            ->assertOk()
            ->assertJsonPath('received', false);

        // «En tránsito» es opcional: de 10 llegaron 8 y se recibe directo.
        $this->receive($purchase, [['id' => $purchase->details->first()->id, 'quantity_received' => 8]], $buyer)
            ->assertOk();

        $purchase->refresh();
        $this->assertSame(PurchaseV2::STATUS_RECEIVED, $purchase->status);
        $this->assertEquals(8, $this->stockOf($this->coco));
        $this->assertEquals(120, $this->cashOut($purchase), 'se pagan los 8 que llegaron');
        $this->assertSame([['draft', 'ordered'], ['ordered', 'received']], $this->statusSteps($purchase));
        $this->assertNotNull(app(TaskService::class)->findOpenPurchaseDiscrepancyTask($purchase), 'el faltante deja su tarea');
        $this->assertSame(0, $this->openTasks($purchase, 'receive_purchase'), 'no queda una tarea de recibirla');
    }

    public function test_drafts_and_cancelled_purchases_still_cannot_be_received(): void
    {
        foreach ([PurchaseV2::STATUS_DRAFT, PurchaseV2::STATUS_CANCELLED] as $status) {
            $purchase = $this->purchase($this->location, $status, [[10, 15]]);

            $this->receive($purchase, [['id' => $purchase->details->first()->id, 'quantity_received' => 10]])
                ->assertStatus(422)
                ->assertJsonPath('message', 'La compra ya fue recibida, o no está pedida ni en tránsito.');

            $this->assertUntouched($purchase);
            $this->assertNull($purchase->details()->first()->quantity_received, "una compra {$status} no se recibe");
        }

        $this->assertEquals(0, $this->stockOf($this->coco));
        $this->assertSame(0, CashMovement::where('source_type', 'purchase_v2')->count());
    }

    // ─── Registrar una compra ya recibida ───────────────────────────────────

    private function confirmAsReceived(PurchaseV2 $purchase, User $user, array $payload = [])
    {
        return $this->actingInBranch($user)
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/confirm", $payload + ['receive_now' => true]);
    }

    private function box(): ProductPackage
    {
        return ProductPackage::create([
            'product_id' => $this->coco->id,
            'company_id' => $this->company->id,
            'package_name' => 'Caja con 12',
            'barcode' => 'CAJA-12-' . uniqid(),
            'quantity_per_package' => 12,
        ]);
    }

    /** Turno abierto del usuario en una caja nueva de la sucursal del contexto. */
    private function openShiftFor(User $user): CashRegisterSession
    {
        $register = CashRegister::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Caja ' . uniqid(),
            'code' => 'C' . uniqid(),
            'is_active' => true,
        ]);

        return app(CashRegisterSessionService::class)->open($register, 500, $user);
    }

    public function test_a_purchase_registered_as_received_enters_the_inventory_at_once(): void
    {
        $buyer = $this->buyer();
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15]]);
        $purchase->details()->create([
            'product_id' => $this->coco->id, 'package_id' => $this->box()->id, 'quantity' => 2,
            'unit_id' => $this->unitId('Pieza'), 'unit_price' => 100,
        ]);

        $this->confirmAsReceived($purchase, $buyer, ['notes' => 'Compra en Sam\'s'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('received', true)
            ->assertJsonPath('whatsapp_sent', false)
            // Quien la registra no tiene turno: el pago no entra a ningún corte.
            ->assertJsonPath('cash_out_of_shift', true)
            ->assertJsonPath('data.status', PurchaseV2::STATUS_RECEIVED);

        $purchase->refresh();
        $this->assertSame(PurchaseV2::STATUS_RECEIVED, $purchase->status);
        $this->assertNotNull($purchase->delivery_date);
        $this->assertSame($buyer->id, $purchase->received_by);
        $this->assertSame('Compra en Sam\'s', $purchase->notes, 'el encabezado se guarda igual que al confirmar');

        // Llegó todo: 10 cocos sueltos y 2 cajas de 12.
        $this->assertEquals(34, $this->stockOf($this->coco));
        foreach ($purchase->details()->get() as $detail) {
            $this->assertEquals((float) $detail->quantity, (float) $detail->quantity_received);
            $this->assertNotNull($detail->received_at);
        }
        $ledger = $this->purchaseLedger($purchase);
        $this->assertCount(2, $ledger, 'un movimiento de kárdex por renglón');
        $this->assertSame([StockLedgerReason::PURCHASE], $ledger->pluck('reason')->unique()->values()->all());
        $this->assertEquals([10, 24], $ledger->pluck('quantity')->map(fn($q) => (float) $q)->sort()->values()->all());

        // De la caja sale el total (150 + 200), como al recibir.
        $this->assertEquals(350, $this->cashOut($purchase));

        // En el historial y la bitácora, un solo paso de borrador a recibida:
        // nunca fue un pedido (ni «pedida» ni «en tránsito»).
        $this->assertSame([['draft', 'received']], $this->statusSteps($purchase));
        $this->assertSame([$buyer->name], collect($purchase->metadata)->pluck('by_user_name')->all());
        $this->assertSame(
            ["Recibió la compra {$purchase->purchase_number}"],
            ActivityLog::where('subject_type', PurchaseV2::class)->where('subject_id', $purchase->id)
                ->orderBy('id')->pluck('description')->all()
        );

        // Nada pendiente: ni recibirla ni aclarar diferencias.
        $this->assertSame(0, $this->openTasks($purchase, 'receive_purchase'));
        $this->assertNull(app(TaskService::class)->findOpenPurchaseDiscrepancyTask($purchase));
    }

    public function test_a_purchase_registered_as_received_cannot_be_confirmed_or_received_again(): void
    {
        $buyer = $this->buyer();
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15]]);

        $this->confirmAsReceived($purchase, $buyer)->assertOk();

        // Doble toque o dos dispositivos: ni stock, ni caja, ni historial por
        // duplicado.
        $this->confirmAsReceived($purchase, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Solo se puede confirmar una compra en borrador.');
        $this->receive($purchase, [['id' => $purchase->details->first()->id, 'quantity_received' => 10]], $buyer)
            ->assertStatus(422)
            ->assertJsonPath('message', 'La compra ya fue recibida, o no está pedida ni en tránsito.');

        $this->assertEquals(10, $this->stockOf($this->coco));
        $this->assertCount(1, $this->purchaseLedger($purchase));
        $this->assertSame(1, CashMovement::where('source_type', 'purchase_v2')->where('source_id', $purchase->id)->count());
        $this->assertEquals(150, $this->cashOut($purchase));
        $this->assertCount(1, $this->statusSteps($purchase));
    }

    public function test_receive_now_comes_as_form_data_and_books_cash_only_when_paid_from_the_register(): void
    {
        $buyer = $this->buyer();

        // FormData: todo llega como texto. La pagó el dueño: no sale de la caja.
        $paidByOwner = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15]]);
        $this->actingInBranch($buyer)
            ->post("/api/auth/admin/purchases-v2/{$paidByOwner->id}/confirm", ['receive_now' => 'true', 'paid_from_cash' => 'false'])
            ->assertOk()
            ->assertJsonPath('received', true);

        $this->assertSame(PurchaseV2::STATUS_RECEIVED, $paidByOwner->fresh()->status);
        $this->assertFalse($paidByOwner->fresh()->paid_from_cash);
        $this->assertEquals(10, $this->stockOf($this->coco));
        $this->assertNull($this->cashOut($paidByOwner));

        // "false" es false: solo se confirma el pedido; nada entra todavía.
        $order = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[5, 15]]);
        $this->actingInBranch($buyer)
            ->post("/api/auth/admin/purchases-v2/{$order->id}/confirm", ['receive_now' => 'false'])
            ->assertOk()
            ->assertJsonPath('received', false);

        $this->assertSame(PurchaseV2::STATUS_ORDERED, $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_date);
        $this->assertEquals(10, $this->stockOf($this->coco), 'el pedido no sube stock');
        $this->assertNull($this->cashOut($order));
        $this->assertCount(0, $this->purchaseLedger($order));
    }

    public function test_receive_now_books_the_cash_out_in_the_buyer_shift_locking_it_before_the_stock(): void
    {
        $buyer = $this->buyer();
        $shift = $this->openShiftFor($buyer);
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15]]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->confirmAsReceived($purchase, $buyer)->assertOk()->assertJsonPath('cash_out_of_shift', false);
        $locks = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn(string $sql) => str_contains(strtolower($sql), 'for update'))
            ->map(fn(string $sql) => match (true) {
                str_contains($sql, '`purchases`') => 'compra',
                str_contains($sql, '`cash_register_sessions`') => 'turno',
                str_contains($sql, '`product_location`') => 'existencias',
                default => null,
            })
            ->filter()->unique()->values()->all();
        DB::disableQueryLog();

        // Mismo orden que una venta (turno → existencias), con la compra antes.
        $this->assertSame(['compra', 'turno', 'existencias'], $locks);
        $this->assertSame(
            $shift->id,
            CashMovement::where('source_type', 'purchase_v2')->where('source_id', $purchase->id)->value('cash_register_session_id')
        );
    }

    public function test_registering_a_received_purchase_needs_purchases_update(): void
    {
        $clerk = $this->createBranchUser($this->location, ['purchases_list', 'purchases_read', 'purchases_create'])->fresh();
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT);

        $this->confirmAsReceived($purchase, $clerk)->assertForbidden();

        $this->assertUntouched($purchase);
        $this->assertEquals(0, $this->stockOf($this->coco));
        $this->assertNull($this->cashOut($purchase));
    }

    public function test_a_purchase_without_products_cannot_be_registered_as_received(): void
    {
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, []);

        $this->confirmAsReceived($purchase, $this->buyer())
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede confirmar una compra sin productos');

        $this->assertSame(PurchaseV2::STATUS_DRAFT, $purchase->fresh()->status);
        $this->assertSame([], $this->statusSteps($purchase));
    }

    public function test_the_response_says_when_the_payment_left_the_register_out_of_shift(): void
    {
        $buyer = $this->buyer();

        // Sin turno propio, el egreso no entra a ningún corte: la app lo avisa.
        $order = $this->purchase($this->location, PurchaseV2::STATUS_ORDERED, [[10, 15]]);
        $this->receive($order, [['id' => $order->details->first()->id, 'quantity_received' => 10]], $buyer)
            ->assertOk()
            ->assertJsonPath('cash_out_of_shift', true);
        $this->assertNull(CashMovement::where('source_type', 'purchase_v2')->where('source_id', $order->id)->value('cash_register_session_id'));

        // Si no salió de la caja, no hay egreso que avisar.
        $paidByOwner = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15]]);
        $this->confirmAsReceived($paidByOwner, $buyer, ['paid_from_cash' => false])
            ->assertOk()
            ->assertJsonPath('cash_out_of_shift', false);

        // Con turno, entra a su corte.
        $shift = $this->openShiftFor($buyer);
        $inShift = $this->purchase($this->location, PurchaseV2::STATUS_IN_TRANSIT, [[10, 15]]);
        $this->receive($inShift, [['id' => $inShift->details->first()->id, 'quantity_received' => 10]], $buyer)
            ->assertOk()
            ->assertJsonPath('cash_out_of_shift', false);
        $this->assertSame($shift->id, CashMovement::where('source_type', 'purchase_v2')->where('source_id', $inShift->id)->value('cash_register_session_id'));
    }

    /** Productos cuya existencia se bloquea (FOR UPDATE) durante $action, en orden. */
    private function stockLockOrder(callable $action): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return collect($queries)
            ->filter(fn(array $query) => str_contains($query['query'], '`product_location`')
                && str_contains(strtolower($query['query']), 'for update')
                && str_contains($query['query'], '`product_id` = ?'))
            ->map(fn(array $query) => (int) $query['bindings'][substr_count(strstr($query['query'], '`product_id` = ?', true), '?')])
            ->unique()->values()->all();
    }

    public function test_a_reception_locks_the_stock_in_product_order_like_a_sale(): void
    {
        // El agua se agrega primero, pero el coco es el producto menor: una
        // venta de los dos bloquea el coco y luego el agua (por producto), y la
        // recepción tiene que tomarlos en ese mismo orden o se traban.
        $water = $this->createProduct(['name' => 'Agua'], 0);
        $this->assertLessThan($water->id, $this->coco->id);
        $buyer = $this->buyer();
        $cocoLine = fn(PurchaseV2 $purchase) => $purchase->details()->create([
            'product_id' => $this->coco->id, 'quantity' => 10, 'unit_id' => $this->unitId('Pieza'), 'unit_price' => 15,
        ]);

        $draft = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[5, 20]], $water);
        $cocoLine($draft);
        $this->assertSame(
            [$this->coco->id, $water->id],
            $this->stockLockOrder(fn() => $this->confirmAsReceived($draft, $buyer)->assertOk())
        );

        // Al recibir un pedido, aunque la petición traiga primero el agua.
        $order = $this->purchase($this->location, PurchaseV2::STATUS_ORDERED, [[5, 20]], $water);
        $orderCoco = $cocoLine($order);
        $this->assertSame(
            [$this->coco->id, $water->id],
            $this->stockLockOrder(fn() => $this->receive($order, [
                ['id' => $order->details->first()->id, 'quantity_received' => 5],
                ['id' => $orderCoco->id, 'quantity_received' => 10],
            ], $buyer)->assertOk())
        );

        $this->assertEquals(20, $this->stockOf($this->coco));
        $this->assertEquals(10, $this->stockOf($water));
    }

    public function test_adding_a_product_locks_the_draft_and_a_confirmed_purchase_takes_no_more(): void
    {
        $buyer = $this->buyer();
        $water = $this->createProduct(['name' => 'Agua'], 0);
        $line = ['product_id' => $water->id, 'quantity' => 3, 'unit_id' => $this->unitId('Pieza'), 'price' => 12];

        // El borrador se lee bloqueado, como al editar un renglón: si otro
        // dispositivo lo está confirmando, el producto espera y ya no entra.
        $draft = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 5]]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingInBranch($buyer)
            ->postJson('/api/auth/admin/purchases-v2/add-detail', ['purchase_id' => $draft->id] + $line)
            ->assertOk()
            ->assertJsonPath('data.purchase_id', $draft->id);
        $lockedRead = collect(DB::getQueryLog())->contains(fn(array $query) => str_contains($query['query'], 'from `purchases`')
            && str_contains(strtolower($query['query']), 'for update'));
        DB::disableQueryLog();
        $this->assertTrue($lockedRead, 'add-detail bloquea el borrador');
        $this->assertSame(2, $draft->details()->count());

        // Ya no es borrador: ni el renglón entra ni se crea otro borrador en
        // silencio (la app seguiría con la compra que tenía abierta).
        foreach ([PurchaseV2::STATUS_ORDERED, PurchaseV2::STATUS_RECEIVED, PurchaseV2::STATUS_CANCELLED] as $status) {
            $purchase = $this->purchase($this->location, $status, [[10, 5]]);

            $this->actingInBranch($buyer)
                ->postJson('/api/auth/admin/purchases-v2/add-detail', ['purchase_id' => $purchase->id] + $line)
                ->assertStatus(422)
                ->assertJsonPath('message', 'Esta compra ya no está en borrador: sus productos ya no se pueden cambiar.');
            $this->actingInBranch($buyer)
                ->postJson('/api/auth/admin/purchases-v2/upsert-draft', [
                    'purchase_id' => $purchase->id,
                    'items' => [['id' => "product_{$water->id}"] + $line],
                ])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Esta compra ya no está en borrador: sus productos ya no se pueden cambiar.');

            $this->assertUntouched($purchase);
        }

        $this->assertSame(4, PurchaseV2::count(), 'no se creó ningún borrador');
    }

    public function test_a_purchase_registered_as_received_cannot_be_dated_after_today(): void
    {
        $buyer = $this->buyer();
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15]]);

        $this->confirmAsReceived($purchase, $buyer, ['purchase_date' => now()->addDays(2)->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchase_date')
            ->assertJsonPath('errors.purchase_date.0', 'Una compra que ya llegó no puede tener fecha posterior a hoy.');

        $this->assertUntouched($purchase);
        $this->assertEquals(0, $this->stockOf($this->coco));
        $this->assertNull($this->cashOut($purchase));

        // Con la fecha de hoy sí, y el egreso queda en hoy.
        $this->confirmAsReceived($purchase, $buyer, ['purchase_date' => now()->toDateString()])->assertOk();
        $this->assertSame(now()->toDateString(), $this->cashOutDate($purchase));
    }

    public function test_tomorrow_by_the_phone_clock_is_registered_as_today(): void
    {
        $buyer = $this->buyer();
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15]]);

        // Un celular con la zona horaria vieja (aún adelanta la hora en
        // verano) ya está en mañana entre las 11 y las 12 de la noche.
        $this->confirmAsReceived($purchase, $buyer, ['purchase_date' => now()->addDay()->toDateString()])
            ->assertOk();

        $this->assertSame(PurchaseV2::STATUS_RECEIVED, $purchase->fresh()->status);
        $this->assertSame(now()->toDateString(), $purchase->fresh()->purchase_date->toDateString());
        $this->assertSame(now()->toDateString(), $this->cashOutDate($purchase));
    }

    private function cashOutDate(PurchaseV2 $purchase): ?string
    {
        return CashMovement::where('source_type', 'purchase_v2')->where('source_id', $purchase->id)
            ->first()?->movement_date?->toDateString();
    }

    public function test_an_order_dated_ahead_books_its_cash_out_on_the_day_it_arrives(): void
    {
        $buyer = $this->buyer();
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15]]);
        $ahead = now()->addDays(20)->toDateString();

        // Un pedido sí puede llevar fecha adelantada...
        $this->actingInBranch($buyer)
            ->postJson("/api/auth/admin/purchases-v2/{$purchase->id}/confirm", ['purchase_date' => $ahead])
            ->assertOk();

        // ...pero si llega hoy, el dinero sale hoy.
        $this->receive($purchase, [['id' => $purchase->details->first()->id, 'quantity_received' => 10]], $buyer)->assertOk();

        $this->assertSame(now()->toDateString(), $this->cashOutDate($purchase));
        $this->assertSame($ahead, $purchase->fresh()->purchase_date->toDateString(), 'la fecha de la compra no cambia');
    }

    public function test_the_price_paid_is_captured_when_an_order_arrives(): void
    {
        $water = $this->createProduct(['name' => 'Agua'], 0);
        $buyer = $this->buyer();
        // El proveedor da el precio del coco al entregar: el pedido iba en $0.
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_ORDERED, [[10, 0]]);
        $cocoLine = $purchase->details->first();
        $waterLine = $purchase->details()->create([
            'product_id' => $water->id, 'quantity' => 10, 'unit_id' => $this->unitId('Pieza'), 'unit_price' => 20,
        ]);

        $this->receive($purchase, [['id' => $cocoLine->id, 'quantity_received' => 10, 'unit_price' => -1]], $buyer)
            ->assertStatus(422)
            ->assertJsonValidationErrors('details.0.unit_price');
        $this->assertSame(PurchaseV2::STATUS_ORDERED, $purchase->fresh()->status);

        $this->receive($purchase, [
            ['id' => $cocoLine->id, 'quantity_received' => 10, 'unit_price' => 12.5],
            // Sin precio se queda el del pedido. De 10 llegaron 8.
            ['id' => $waterLine->id, 'quantity_received' => 8],
        ], $buyer)->assertOk();

        $this->assertEquals(12.5, (float) $cocoLine->fresh()->unit_price);
        $this->assertEquals(125, (float) $cocoLine->fresh()->total);
        $this->assertEquals(20, (float) $waterLine->fresh()->unit_price);
        $this->assertEquals(325, (float) $purchase->fresh()->total, 'lo pedido al precio pagado: 10 × 12.50 + 10 × 20');
        $this->assertEquals(285, $this->cashOut($purchase), 'lo que llegó al precio pagado: 10 × 12.50 + 8 × 20');

        // Y es el que se propone en la siguiente compra.
        $this->assertEquals(12.5, $this->priceMap($this->lastPrices('', $buyer))["{$this->coco->id}/-"]);
    }

    // ─── Lista y estadísticas ───────────────────────────────────────────────

    public function test_the_list_is_paginated_like_the_other_lists_and_searchable(): void
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Cocos del Pacífico']);

        for ($i = 0; $i < 24; $i++) {
            $this->purchase($this->location, PurchaseV2::STATUS_RECEIVED);
        }
        $fromSupplier = $this->purchase($this->location, PurchaseV2::STATUS_ORDERED, [[10, 15], [5, 8]]);
        $fromSupplier->update(['supplier_id' => $supplier->id, 'document_number' => 'FAC-778']);
        $this->purchase($this->pitillal, PurchaseV2::STATUS_RECEIVED);
        $this->foreignPurchase(PurchaseV2::STATUS_RECEIVED);

        // Lo que pide AppList: paginated, per_page y page.
        $this->actingInBranch($this->user)
            ->getJson('/api/auth/admin/purchases-v2?paginated=1&per_page=20&page=2')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'purchase_number', 'status', 'payment_method', 'purchase_date', 'products_count']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
            ])
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(5, 'data');

        // La más reciente primero, con su proveedor y cuántos productos lleva.
        $first = $this->actingInBranch($this->user)
            ->getJson('/api/auth/admin/purchases-v2?paginated=1&per_page=20')
            ->assertOk()
            ->json('data.0');
        $this->assertSame($fromSupplier->id, $first['id']);
        $this->assertSame('Cocos del Pacífico', $first['supplier']['name']);
        $this->assertSame(2, $first['products_count']);
        $this->assertArrayNotHasKey('email', $first['user'] ?? [], 'no expone el correo de quien la hizo');

        foreach (['Pacífico', 'FAC-778', $fromSupplier->purchase_number] as $search) {
            $this->actingInBranch($this->user)
                ->getJson('/api/auth/admin/purchases-v2?paginated=1&search=' . urlencode($search))
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id', $fromSupplier->id);
        }

        $this->actingInBranch($this->user)
            ->getJson('/api/auth/admin/purchases-v2?status=ordered')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_a_branch_user_only_lists_and_counts_their_own_branch(): void
    {
        $this->purchase($this->location, PurchaseV2::STATUS_RECEIVED);
        $this->purchase($this->pitillal, PurchaseV2::STATUS_RECEIVED);
        $this->purchase($this->pitillal, PurchaseV2::STATUS_RECEIVED);
        $buyer = $this->buyer();
        $pitillal = $this->pitillal->id;

        // El filtro de sucursal no le abre las compras de otra.
        foreach (["?location_id={$pitillal}", '?location_id=all', ''] as $query) {
            $this->actingInBranch($buyer)
                ->getJson("/api/auth/admin/purchases-v2{$query}")
                ->assertOk()
                ->assertJsonPath('meta.total', 1);

            $this->actingInBranch($buyer)
                ->getJson("/api/auth/admin/purchases-v2/stats{$query}")
                ->assertOk()
                ->assertJsonPath('data.total_purchases', 1)
                ->assertJsonPath('data.total_amount', 150);
        }

        // Quien ve todas las sucursales sí elige: la actual por defecto, otra,
        // o todas.
        $expected = ['' => 1, "?location_id={$pitillal}" => 2, '?location_id=all' => 3];
        foreach ($expected as $query => $total) {
            $this->actingInBranch($this->user)
                ->getJson("/api/auth/admin/purchases-v2{$query}")
                ->assertOk()
                ->assertJsonPath('meta.total', $total);
            $this->actingInBranch($this->user)
                ->getJson("/api/auth/admin/purchases-v2/stats{$query}")
                ->assertOk()
                ->assertJsonPath('data.total_purchases', $total);
        }

        // Una sucursal de otra empresa no existe para él.
        [, $foreignBranch] = $this->createCompanyWithBranch();
        $this->actingInBranch($this->user)
            ->getJson("/api/auth/admin/purchases-v2?location_id={$foreignBranch->id}")
            ->assertNotFound();
    }

    // ─── Últimos precios pagados ────────────────────────────────────────────

    /**
     * Compra con renglones [producto, precio, empaque, recibido] (10 pedidos;
     * por defecto llegaron todos) recibidos en $at.
     */
    private function boughtAt(Carbon $at, array $lines, ?Supplier $supplier = null, string $status = PurchaseV2::STATUS_RECEIVED, ?Location $location = null): PurchaseV2
    {
        $location ??= $this->location;
        $purchase = PurchaseV2::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'supplier_id' => $supplier?->id,
            'purchase_number' => PurchaseV2::generatePurchaseNumber(),
            'purchase_date' => $at,
            'delivery_date' => $at,
            'status' => $status,
            'user_id' => $this->user->id,
        ]);

        foreach ($lines as $line) {
            $purchase->details()->create([
                'product_id' => $line[0]->id,
                'package_id' => $line[2] ?? null,
                'quantity' => 10,
                'unit_id' => $this->unitId('Pieza'),
                'unit_price' => $line[1],
                'quantity_received' => $line[3] ?? 10,
                'received_at' => $at,
            ]);
        }

        return $purchase;
    }

    private function lastPrices(string $query = '', ?User $user = null)
    {
        return $this->actingInBranch($user ?? $this->buyer())
            ->getJson('/api/auth/admin/purchases-v2/last-prices' . $query);
    }

    /** "producto/empaque" => precio. */
    private function priceMap($response): array
    {
        return collect($response->json('data'))
            ->mapWithKeys(fn(array $row) => [$row['product_id'] . '/' . ($row['package_id'] ?? '-') => $row['unit_price']])
            ->all();
    }

    public function test_last_prices_take_the_latest_received_line_of_each_product_and_package(): void
    {
        $water = $this->createProduct(['name' => 'Agua'], 0);
        $box = $this->box();
        $huerteros = Supplier::create(['company_id' => $this->company->id, 'name' => 'Huerteros']);
        $sams = Supplier::create(['company_id' => $this->company->id, 'name' => "Sam's"]);

        $old = $this->boughtAt(now()->subDays(10), [[$this->coco, 12], [$this->coco, 100, $box->id], [$water, 20]], $huerteros);
        $this->boughtAt(now()->subDays(2), [[$this->coco, 14]], $sams);
        // Misma recepción: gana el renglón más nuevo.
        $latest = $this->boughtAt(now()->subDays(2), [[$this->coco, 15.5]], $sams);
        // Un precio en 0 no sirve de referencia, ni un renglón que no llegó.
        $this->boughtAt(now()->subDay(), [[$this->coco, 0], [$water, 25, null, 0]], $huerteros);
        // Lo que no está recibido no cuenta, aunque traiga cantidades.
        foreach ([PurchaseV2::STATUS_DRAFT, PurchaseV2::STATUS_ORDERED, PurchaseV2::STATUS_IN_TRANSIT, PurchaseV2::STATUS_CANCELLED] as $status) {
            $this->boughtAt(now(), [[$this->coco, 99], [$water, 99]], $huerteros, $status);
        }
        // Ni lo de otra empresa, aunque sea con el mismo producto.
        [, $foreignBranch] = $this->createCompanyWithBranch();
        $this->boughtAt(now(), [[$this->coco, 77]], null, PurchaseV2::STATUS_RECEIVED, $foreignBranch);

        $coco = "{$this->coco->id}/-";
        $cocoBox = "{$this->coco->id}/{$box->id}";
        $agua = "{$water->id}/-";

        $all = $this->lastPrices()->assertOk()->assertJsonPath('success', true);
        $this->assertEquals([$coco => 15.5, $cocoBox => 100, $agua => 20], $this->priceMap($all));
        $rows = collect($all->json('data'));
        $this->assertSame([
            'product_id' => $this->coco->id,
            'package_id' => null,
            'unit_id' => $this->unitId('Pieza'),
            'unit_price' => 15.5,
            'purchase_id' => $latest->id,
            'purchase_number' => $latest->purchase_number,
            'received_at' => now()->subDays(2)->toDateString(),
        ], $rows->first(fn(array $row) => $row['product_id'] === $this->coco->id && $row['package_id'] === null));
        $this->assertSame($old->id, $rows->firstWhere('package_id', $box->id)['purchase_id']);

        // Por proveedor: solo sus compras.
        $this->assertEquals(
            [$coco => 12, $cocoBox => 100, $agua => 20],
            $this->priceMap($this->lastPrices("?supplier_id={$huerteros->id}")->assertOk())
        );
        $this->assertEquals(
            [$coco => 15.5],
            $this->priceMap($this->lastPrices("?supplier_id={$sams->id}")->assertOk())
        );
    }

    public function test_last_prices_only_take_suppliers_of_the_company_and_need_purchases_create(): void
    {
        [$other] = $this->createCompanyWithBranch();
        $foreignSupplier = Supplier::create(['company_id' => $other->id, 'name' => 'Ajeno']);

        $this->lastPrices("?supplier_id={$foreignSupplier->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');

        // Sin purchases_create no los ve (con purchases_read, show habría
        // respondido 404: la ruta no la toma show).
        $reader = $this->createBranchUser($this->location, ['purchases_list', 'purchases_read'])->fresh();
        $this->lastPrices('', $reader)
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');

        // Con solo purchases_create sí (show pediría purchases_read).
        $this->boughtAt(now(), [[$this->coco, 18]]);
        $clerk = $this->createBranchUser($this->location, ['purchases_create'])->fresh();
        $this->assertEquals(
            ["{$this->coco->id}/-" => 18],
            $this->priceMap($this->lastPrices('', $clerk)->assertOk())
        );
    }

    // ─── Detalle ────────────────────────────────────────────────────────────

    public function test_the_detail_says_when_it_arrived_and_who_made_and_received_it(): void
    {
        // A media tarde: la hora de la recepción no es la medianoche de la
        // fecha (delivery_date guarda solo la fecha).
        $this->travelTo(now()->setTime(14, 35));
        $buyer = $this->buyer();
        $purchase = $this->purchase($this->location, PurchaseV2::STATUS_DRAFT, [[10, 15]]);
        $base = "/api/auth/admin/purchases-v2/{$purchase->id}";
        $expected = now()->addDays(2)->toDateString();

        $this->actingInBranch($buyer)->postJson("{$base}/confirm", ['expected_delivery_date' => $expected])->assertOk();

        $this->actingInBranch($buyer)->getJson($base)
            ->assertOk()
            ->assertJsonPath('data.data.expected_delivery_date', $expected)
            ->assertJsonPath('data.data.delivery_date', null)
            ->assertJsonPath('data.data.created_by_name', $this->user->name)
            ->assertJsonPath('data.data.received_by_name', null)
            ->assertJsonPath('data.data.details.0.received_at', null);

        $this->receive($purchase, [['id' => $purchase->details->first()->id, 'quantity_received' => 10]], $buyer)->assertOk();

        $this->actingInBranch($buyer)->getJson($base)
            ->assertOk()
            ->assertJsonPath('data.data.delivery_date', now()->format('Y-m-d H:i'))
            ->assertJsonPath('data.data.received_by_name', $buyer->name)
            ->assertJsonPath('data.data.details.0.received_at', now()->format('Y-m-d H:i'))
            // Lo que ya traía sigue igual.
            ->assertJsonPath('data.data.status', PurchaseV2::STATUS_RECEIVED)
            ->assertJsonStructure(['data' => ['data' => [
                'id', 'supplier_id', 'supplier_name', 'purchase_date', 'purchase_number', 'notes', 'document_number',
                'status', 'total', 'payment_method', 'paid_from_cash', 'company_id', 'location_id', 'details_count',
                'status_history', 'discrepancy_resolution',
                'details' => [['id', 'product_id', 'product_name', 'quantity', 'quantity_received', 'unit_id', 'unit_price', 'subtotal']],
            ]]]);
    }
}
