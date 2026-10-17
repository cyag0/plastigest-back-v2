<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Enums\AdjustmentReasonCode;
use App\Enums\StockLedgerReason;
use App\Models\ActivityLog;
use App\Models\Admin\Location;
use App\Models\InventoryAdjustmentDetail;
use App\Models\InventoryCount;
use App\Models\InventoryTransfer;
use App\Models\Operations\ProductionOrder;
use App\Models\Product;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Reports\AdjustmentsReport;
use App\Reports\ReportPeriod;
use App\Reports\ReportScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Ajustes de inventario: deshacer un ajuste del día, ajuste por conteo
 * ("cuántos hay en realidad"), reglas de motivo y fecha, de dónde salió cada
 * ajuste y los filtros nuevos del listado.
 */
class AdjustmentUndoCountTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const URL = '/api/auth/admin/inventory-adjustments';
    private const MANAGER = ['inventory_list', 'inventory_read', 'inventory_manage'];

    private const COUNT_MESSAGE = 'Este ajuste lo generó un conteo de inventario y no puede modificarse ni eliminarse. Si hay un error, registra un ajuste nuevo.';
    private const PRODUCTION_MESSAGE = 'Este ajuste es una merma de una orden de producción y no puede modificarse ni eliminarse aquí. Corrígelo desde la orden.';
    private const TRANSFER_MESSAGE = 'Este ajuste se generó al recibir una transferencia y no puede modificarse ni eliminarse aquí.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Storage::fake('public');
    }

    // ─── Ayudantes ──────────────────────────────────────────────────────────

    /** Alta de un renglón como la manda la app. */
    private function adjust(array $detail, array $payload = [], ?User $user = null, ?Location $location = null): TestResponse
    {
        return $this->actingInBranch($user ?? $this->user, $location)
            ->postJson(self::URL, array_merge(['details' => [$detail]], $payload));
    }

    /** Ajuste manual registrado por la API (por defecto «Daño» si resta, «Encontrado» si suma). */
    private function manualAdjustment(Product $product, string $direction, float $qty, ?string $reason = null, ?Location $location = null): InventoryAdjustmentDetail
    {
        $id = $this->adjust([
            'product_id' => $product->id,
            'direction' => $direction,
            'quantity' => $qty,
            'unit_id' => $product->unit_id,
            'reason_code' => $reason ?? ($direction === 'in' ? 'found' : 'damage'),
        ], [], null, $location)->assertCreated()->json('data.0.id');

        return InventoryAdjustmentDetail::findOrFail($id);
    }

    /** "Cuántos hay" de un producto con el motivo «Diferencia de conteo». */
    private function counted(Product $product, float $counted, ?int $unitId = null, array $extra = []): TestResponse
    {
        return $this->adjust(array_merge([
            'product_id' => $product->id,
            'unit_id' => $unitId ?? $product->unit_id,
            'reason_code' => 'count_diff',
            'counted_quantity' => $counted,
        ], $extra));
    }

    private function undo(InventoryAdjustmentDetail $detail, ?User $user = null, ?Location $location = null): TestResponse
    {
        return $this->actingInBranch($user ?? $this->user, $location)->postJson(self::URL . "/{$detail->id}/undo");
    }

    private function show(InventoryAdjustmentDetail $detail, ?User $user = null, ?Location $location = null): TestResponse
    {
        return $this->actingInBranch($user ?? $this->user, $location)->getJson(self::URL . "/{$detail->id}");
    }

    /** Renglones del listado (los filtros van en $query), por id. */
    private function listed(string $query = '', ?User $user = null): Collection
    {
        return collect(
            $this->actingInBranch($user ?? $this->user)
                ->getJson(self::URL . "?paginated=1&per_page=100{$query}")
                ->assertOk()
                ->json('data')
        )->keyBy('id');
    }

    /** Ajuste como lo crean los conteos, la producción y las transferencias. */
    private function generated(Product $product, array $attributes): InventoryAdjustmentDetail
    {
        return InventoryAdjustmentDetail::create(array_merge([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'created_by' => $this->user->id,
            'product_id' => $product->id, 'direction' => 'out', 'quantity' => 3, 'unit_id' => $product->unit_id,
            'previous_stock' => 53, 'new_stock' => 50, 'reason_code' => AdjustmentReasonCode::DAMAGE, 'applied_at' => now(),
        ], $attributes));
    }

    private function productionOrder(): ProductionOrder
    {
        return ProductionOrder::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'production_date' => now()->toDateString(),
        ]);
    }

    private function productionWaste(Product $product, ?ProductionOrder $order = null, array $content = []): InventoryAdjustmentDetail
    {
        return $this->generated($product, [
            'reference_type' => 'production_order',
            'reference_id' => ($order ?? $this->productionOrder())->id,
            'reason_code' => AdjustmentReasonCode::PRODUCTION_WASTE,
            'content' => $content ?: null,
        ]);
    }

    private function inventoryCount(string $name = 'Semanal 14 oct'): InventoryCount
    {
        return InventoryCount::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => $name,
            'count_date' => now()->toDateString(),
            'status' => 'completed',
            'user_id' => $this->user->id,
        ]);
    }

    private function countDifference(Product $product, ?InventoryCount $count = null): InventoryAdjustmentDetail
    {
        return $this->generated($product, [
            'reference_type' => 'inventory_count',
            'reference_id' => ($count ?? $this->inventoryCount())->id,
            'reason_code' => AdjustmentReasonCode::COUNT_DIFF,
        ]);
    }

    /** Merma registrada al recibir una transferencia (mismo content que InventoryTransferController::receive). */
    private function transferDamage(Product $product, ?InventoryTransfer $transfer = null): InventoryAdjustmentDetail
    {
        $transfer ??= InventoryTransfer::create([
            'company_id' => $this->company->id,
            'from_location_id' => $this->createBranch()->id,
            'to_location_id' => $this->location->id,
            'requested_by' => $this->user->id,
        ]);

        return $this->generated($product, ['content' => [
            'source' => 'inventory_transfer_receive_difference',
            'transfer_id' => $transfer->id,
            'transfer_detail_id' => 1,
            'adjustment_comment' => 'Se rompieron en el camino',
            'evidence_files' => [],
            'created_from_transfer' => true,
        ]]);
    }

    /** Usuario asignado a una sola sucursal; con assignAlso queda también en otra, con el mismo rol. */
    private function branchManager(Location $location, ?Location $assignAlso = null): User
    {
        $manager = $this->createBranchUser($location, self::MANAGER);

        if ($assignAlso) {
            DB::table('user_location_roles')->insert([
                'user_id' => $manager->id,
                'location_id' => $assignAlso->id,
                'role_id' => DB::table('user_location_roles')->where('user_id', $manager->id)->value('role_id'),
            ]);
        }

        return $manager;
    }

    // ─── Deshacer ───────────────────────────────────────────────────────────

    public function test_undo_returns_the_stock_and_keeps_the_adjustment_marked_as_undone(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero'], 50);
        $merma = $this->manualAdjustment($coco, 'out', 5);
        $this->assertEquals(45, $this->stockOf($coco));

        $this->undo($merma)
            ->assertOk()
            ->assertJsonPath('message', 'Ajuste deshecho')
            ->assertJsonPath('data.id', $merma->id)
            ->assertJsonPath('data.origin', ['type' => 'manual', 'id' => null, 'label' => null])
            ->assertJsonPath('data.reverted_by_user', ['id' => $this->user->id, 'name' => $this->user->name])
            ->assertJsonPath('data.can_undo', false)
            ->assertJsonPath('data.reverted_at', fn($at) => is_string($at) && Carbon::parse($at)->isToday());

        $this->assertEquals(50, $this->stockOf($coco));

        // No se borra: queda con quién y cuándo lo deshizo, y con su evidencia.
        $merma->refresh();
        $this->assertSame($this->user->id, $merma->content['reverted_by']);
        $this->assertNotEmpty($merma->content['reverted_at']);
        $this->assertSame(0, $merma->content['evidence_count']);
        $this->assertEquals(5, (float) $merma->quantity);

        // En el kárdex, como entrada que regresa la merma, ligada al ajuste.
        $entry = StockLedgerEntry::where('reference_type', InventoryAdjustmentDetail::class)
            ->where('reference_id', $merma->id)->sole();
        $this->assertSame('in', $entry->direction);
        $this->assertSame(StockLedgerReason::ADJUSTMENT, $entry->reason);
        $this->assertSame('Ajuste deshecho', $entry->notes);
        $this->assertEquals(5, (float) $entry->quantity);
        $this->assertEquals(45, (float) $entry->previous_stock);
        $this->assertEquals(50, (float) $entry->new_stock);

        // Y en la bitácora.
        $this->assertSame(
            'Deshizo el ajuste de Coco entero: −5 pz (Daño)',
            ActivityLog::where('subject_type', InventoryAdjustmentDetail::class)->where('subject_id', $merma->id)
                ->where('event', 'reverted')->value('description')
        );
    }

    public function test_undoing_twice_returns_the_stock_only_once_and_an_undone_adjustment_cannot_be_edited_or_deleted(): void
    {
        $coco = $this->createProduct([], 50);
        $merma = $this->manualAdjustment($coco, 'out', 5);
        $url = self::URL . "/{$merma->id}";

        $this->undo($merma)->assertOk();
        // Doble toque.
        $this->undo($merma)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este ajuste ya se deshizo.')
            ->assertJsonPath('code', 'adjustment_reverted');

        // Editarlo o eliminarlo regresaría la existencia otra vez.
        $this->actingInBranch($this->user)->putJson($url, ['quantity' => 8])
            ->assertStatus(422)->assertJsonPath('code', 'adjustment_reverted');
        $this->actingInBranch($this->user)->deleteJson($url)
            ->assertStatus(422)->assertJsonPath('code', 'adjustment_reverted');

        $this->assertEquals(50, $this->stockOf($coco));
        $this->assertDatabaseHas('inventory_adjustment_details', ['id' => $merma->id]);
        $this->assertSame(1, StockLedgerEntry::where('reference_id', $merma->id)
            ->where('reference_type', InventoryAdjustmentDetail::class)->count());
    }

    public function test_undoing_an_entry_takes_the_stock_back_out(): void
    {
        $coco = $this->createProduct([], 10);
        $entrada = $this->manualAdjustment($coco, 'in', 4);
        $this->assertEquals(14, $this->stockOf($coco));

        $this->undo($entrada)->assertOk()->assertJsonPath('data.can_undo', false);

        $this->assertEquals(10, $this->stockOf($coco));
        $this->assertSame('out', StockLedgerEntry::where('reference_type', InventoryAdjustmentDetail::class)
            ->where('reference_id', $entrada->id)->value('direction'));
    }

    public function test_undoing_an_entry_whose_stock_was_already_used_says_how_much_is_left(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero'], 0);
        $entrada = $this->manualAdjustment($coco, 'in', 6);
        $this->setStock($coco, $this->location, 2); // se vendieron 4

        $this->undo($entrada)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ya no hay suficiente existencia de «Coco entero» para deshacer esta entrada (hay 2 pz, se necesitan 6 pz).')
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('details.available', 2)
            ->assertJsonPath('details.requested', 6);

        $this->assertEquals(2, $this->stockOf($coco));
        $this->assertFalse($entrada->fresh()->isReverted());
    }

    public function test_adjustments_generated_by_other_documents_cannot_be_undone(): void
    {
        $coco = $this->createProduct([], 50);
        $cases = [
            [$this->countDifference($coco), self::COUNT_MESSAGE],
            [$this->productionWaste($coco), self::PRODUCTION_MESSAGE],
            [$this->transferDamage($coco), self::TRANSFER_MESSAGE],
        ];

        foreach ($cases as [$adjustment, $message]) {
            $this->undo($adjustment)
                ->assertStatus(422)
                ->assertJsonPath('message', $message)
                ->assertJsonPath('code', 'generated_adjustment');

            $this->show($adjustment)->assertOk()->assertJsonPath('data.can_undo', false);
            $this->assertFalse($adjustment->fresh()->isReverted());
        }

        $this->assertEquals(50, $this->stockOf($coco));
        $this->assertSame(0, StockLedgerEntry::count());
    }

    public function test_an_adjustment_from_another_day_cannot_be_undone(): void
    {
        $coco = $this->createProduct([], 50);

        $this->travelTo(now()->subDay());
        $ayer = $this->manualAdjustment($coco, 'out', 5);
        $this->travelBack();

        $this->show($ayer)->assertOk()->assertJsonPath('data.can_undo', false);

        $this->undo($ayer)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Solo se puede deshacer el mismo día. Para corregirlo, registra un ajuste nuevo.')
            ->assertJsonPath('code', 'adjustment_not_today');

        $this->assertEquals(45, $this->stockOf($coco));
        $this->assertFalse($ayer->fresh()->isReverted());
    }

    public function test_can_undo_follows_the_permission_and_branch_rules_of_undo(): void
    {
        $otra = $this->createBranch();
        $coco = $this->createProduct([], 50);
        $this->setStock($coco, $otra, 50);
        $aqui = $this->manualAdjustment($coco, 'out', 1);
        $alla = $this->manualAdjustment($coco, 'out', 2, null, $otra);

        // El administrador (todas las sucursales) puede, aunque sea de otra sucursal.
        $this->assertTrue($this->listed()[$aqui->id]['can_undo']);
        $this->assertTrue($this->listed()[$alla->id]['can_undo']);

        // Sin inventory_manage ve el ajuste pero no puede deshacerlo.
        $lector = $this->createBranchUser($this->location, ['inventory_list', 'inventory_read']);
        $this->assertFalse($this->listed('', $lector)[$aqui->id]['can_undo']);
        $this->undo($aqui, $lector)->assertForbidden();

        // Asignado a las dos sucursales: desde una no deshace los de la otra
        // (cambiarlo mueve el stock de esa sucursal, con el rol de la actual).
        $encargado = $this->branchManager($this->location, $otra);
        $this->show($alla, $encargado)->assertOk()->assertJsonPath('data.can_undo', false);
        $this->undo($alla, $encargado)->assertNotFound();
        $this->show($alla, $encargado, $otra)->assertOk()->assertJsonPath('data.can_undo', true);
        $this->assertEquals(48, $this->stockOf($coco, $otra));

        $this->assertTrue($this->listed('', $encargado)[$aqui->id]['can_undo']);
        $this->undo($aqui, $encargado)->assertOk();
        $this->assertEquals(50, $this->stockOf($coco));
    }

    // ─── Ajuste por conteo ──────────────────────────────────────────────────

    public function test_count_mode_adjusts_the_difference_against_the_stock(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero'], 10);

        // Hay 12: sobran 2.
        $this->counted($coco, 12)
            ->assertCreated()
            ->assertJsonPath('data.0.direction', 'in')
            ->assertJsonPath('data.0.quantity', 2)
            ->assertJsonPath('data.0.previous_stock', 10)
            ->assertJsonPath('data.0.new_stock', 12)
            ->assertJsonPath('data.0.reason_code', 'count_diff')
            ->assertJsonPath('data.0.can_undo', true);
        $this->assertEquals(12, $this->stockOf($coco));

        // Hay 7: faltan 5.
        $this->counted($coco, 7)
            ->assertCreated()
            ->assertJsonPath('data.0.direction', 'out')
            ->assertJsonPath('data.0.quantity', 5)
            ->assertJsonPath('data.0.new_stock', 7);
        $this->assertEquals(7, $this->stockOf($coco));

        // Ya hay 7: nada que ajustar.
        $this->counted($coco, 7)
            ->assertStatus(422)
            ->assertJsonPath('message', '«Coco entero» ya tiene 7 pz: no hay diferencia que ajustar.')
            ->assertJsonPath('code', 'no_difference');
        $this->assertSame(2, InventoryAdjustmentDetail::count());

        // No queda ninguno.
        $this->counted($coco, 0)->assertCreated()->assertJsonPath('data.0.quantity', 7);
        $this->assertEquals(0, $this->stockOf($coco));

        // Lo contado manda aunque la app mande también dirección y cantidad.
        $this->counted($coco, 3, null, ['direction' => 'out', 'quantity' => 99])
            ->assertCreated()
            ->assertJsonPath('data.0.direction', 'in')
            ->assertJsonPath('data.0.quantity', 3);
        $this->assertEquals(3, $this->stockOf($coco));
    }

    public function test_count_mode_converts_from_the_chosen_unit(): void
    {
        $pulpa = $this->createProduct(['name' => 'Pulpa'], 800, 'Gramo');

        // Se contó medio kilo: faltan 300 g.
        $this->counted($pulpa, 0.5, $this->unitId('Kilogramo'))
            ->assertCreated()
            ->assertJsonPath('data.0.direction', 'out')
            ->assertJsonPath('data.0.quantity', 300)
            ->assertJsonPath('data.0.unit_id', $this->unitId('Gramo'));
        $this->assertEqualsWithDelta(500, $this->stockOf($pulpa), 0.001);

        $this->counted($pulpa, 1.25, $this->unitId('Kilogramo'))
            ->assertCreated()
            ->assertJsonPath('data.0.direction', 'in')
            ->assertJsonPath('data.0.quantity', 750);
        $this->assertEqualsWithDelta(1250, $this->stockOf($pulpa), 0.001);

        // Lo mismo en gramos o en kilos: ya no hay diferencia.
        $this->counted($pulpa, 1250)
            ->assertStatus(422)
            ->assertJsonPath('message', '«Pulpa» ya tiene 1,250 g: no hay diferencia que ajustar.');
        $this->counted($pulpa, 1.25, $this->unitId('Kilogramo'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_difference');
    }

    public function test_count_mode_validation(): void
    {
        $coco = $this->createProduct([], 10);

        // Solo con «Diferencia de conteo».
        $this->adjust(['product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'reason_code' => 'damage', 'counted_quantity' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.counted_quantity' => 'La cantidad contada solo se usa con el motivo «Diferencia de conteo».']);

        $this->counted($coco, -1)->assertStatus(422)->assertJsonValidationErrors('details.0.counted_quantity');

        // Sin lo contado, dirección y cantidad son obligatorias.
        $this->adjust(['product_id' => $coco->id, 'unit_id' => $coco->unit_id, 'reason_code' => 'count_diff'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'details.0.direction' => 'Indica si el ajuste suma o resta existencia.',
                'details.0.quantity' => 'Indica la cantidad del ajuste.',
            ]);

        $this->assertEquals(10, $this->stockOf($coco));
        $this->assertSame(0, InventoryAdjustmentDetail::count());
    }

    // ─── Reglas del motivo y la fecha ───────────────────────────────────────

    public function test_the_reason_decides_the_direction(): void
    {
        $coco = $this->createProduct([], 10);
        $line = fn(string $direction, string $reason) => [
            'product_id' => $coco->id, 'direction' => $direction, 'quantity' => 1,
            'unit_id' => $coco->unit_id, 'reason_code' => $reason,
        ];

        $this->adjust($line('out', 'found'))
            ->assertStatus(422)
            ->assertJsonPath('message', '«Encontrado» siempre suma existencia.')
            ->assertJsonValidationErrors('details.0.direction');

        $this->adjust($line('in', 'damage'))
            ->assertStatus(422)
            ->assertJsonPath('message', '«Daño» siempre resta existencia.');

        foreach (['loss', 'expiry', 'theft'] as $reason) {
            $this->adjust($line('in', $reason))->assertStatus(422)->assertJsonValidationErrors('details.0.direction');
        }
        $this->assertEquals(10, $this->stockOf($coco));

        // «Otro» y «Diferencia de conteo» van en cualquier sentido.
        $this->adjust($line('in', 'other'))->assertCreated();
        $this->adjust($line('out', 'other'))->assertCreated();
        $this->adjust($line('in', 'count_diff'))->assertCreated();
        $this->adjust($line('out', 'count_diff'))->assertCreated();
        $this->assertEquals(10, $this->stockOf($coco));
    }

    public function test_applied_at_today_keeps_the_time_a_past_day_starts_at_midnight_and_the_future_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 15:30:00'));
        $coco = $this->createProduct([], 50);
        $appliedAt = fn(?string $date) => InventoryAdjustmentDetail::findOrFail(
            $this->adjust(
                ['product_id' => $coco->id, 'direction' => 'out', 'quantity' => 1, 'unit_id' => $coco->unit_id, 'reason_code' => 'damage'],
                $date === null ? [] : ['applied_at' => $date],
            )->assertCreated()->json('data.0.id')
        )->applied_at->format('Y-m-d H:i:s');

        // Hoy: con la hora (antes quedaba a las 00:00).
        $this->assertSame('2026-10-17 15:30:00', $appliedAt('2026-10-17'));
        $this->assertSame('2026-10-17 15:30:00', $appliedAt(null));
        // Un día pasado: ese día, sin hora.
        $this->assertSame('2026-10-14 00:00:00', $appliedAt('2026-10-14'));
        // Mañana por el reloj del celular (zona horaria vieja): hoy.
        $this->assertSame('2026-10-17 15:30:00', $appliedAt('2026-10-18'));

        $this->adjust(
            ['product_id' => $coco->id, 'direction' => 'out', 'quantity' => 1, 'unit_id' => $coco->unit_id, 'reason_code' => 'damage'],
            ['applied_at' => '2026-10-19'],
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['applied_at' => 'La fecha del ajuste no puede ser posterior a hoy.']);

        $this->assertEquals(46, $this->stockOf($coco));
    }

    // ─── De dónde salió ─────────────────────────────────────────────────────

    public function test_origin_says_where_each_adjustment_came_from(): void
    {
        $coco = $this->createProduct([], 50);
        $manual = $this->manualAdjustment($coco, 'out', 1);
        $order = $this->productionOrder();
        $waste = $this->productionWaste($coco, $order);
        $count = $this->inventoryCount('Semanal 14 oct');
        $difference = $this->countDifference($coco, $count);
        $damage = $this->transferDamage($coco);
        $transfer = InventoryTransfer::findOrFail($damage->content['transfer_id']);
        $orphan = $this->generated($coco, ['reference_type' => 'inventory_count', 'reference_id' => 999999, 'reason_code' => AdjustmentReasonCode::COUNT_DIFF]);

        $rows = $this->listed();

        $this->assertSame(['type' => 'manual', 'id' => null, 'label' => null], $rows[$manual->id]['origin']);
        $this->assertSame(['type' => 'production_order', 'id' => $order->id, 'label' => "Orden {$order->folio}"], $rows[$waste->id]['origin']);
        $this->assertSame(['type' => 'inventory_count', 'id' => $count->id, 'label' => 'Conteo «Semanal 14 oct»'], $rows[$difference->id]['origin']);
        $this->assertSame(['type' => 'transfer', 'id' => $transfer->id, 'label' => "Transferencia {$transfer->transfer_number}"], $rows[$damage->id]['origin']);
        // El documento ya no existe: queda su número.
        $this->assertSame(['type' => 'inventory_count', 'id' => 999999, 'label' => 'Conteo #999999'], $rows[$orphan->id]['origin']);
        $this->assertStringStartsWith('Orden PROD-', $rows[$waste->id]['origin']['label']);

        // Solo el manual se puede deshacer.
        $this->assertSame([$manual->id], $rows->where('can_undo', true)->keys()->all());

        // El mismo origen en el detalle.
        $this->show($damage)->assertOk()->assertJsonPath('data.origin.label', "Transferencia {$transfer->transfer_number}");

        // Filtro por origen.
        $ids = fn(string $origin) => $this->listed("&origin={$origin}")->keys()->sort()->values()->all();
        $this->assertSame([$manual->id], $ids('manual'));
        $this->assertSame([$waste->id], $ids('production_order'));
        $this->assertSame([$difference->id, $orphan->id], $ids('inventory_count'));
        $this->assertSame([$damage->id], $ids('transfer'));
        $this->assertCount(5, $this->listed('&origin=cualquiera'));
    }

    public function test_the_list_loads_origins_without_a_query_per_row(): void
    {
        $coco = $this->createProduct([], 100);
        $oneOfEach = function () use ($coco): void {
            $this->manualAdjustment($coco, 'out', 1);
            $this->productionWaste($coco, null, [
                'reverted_at' => now()->toIso8601String(),
                'reverted_by' => $this->createUserWithRole($this->location)->id,
            ]);
            $this->countDifference($coco);
            $this->transferDamage($coco);
        };

        $queriesFor = function (int $rows): int {
            $client = $this->actingInBranch($this->user);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $client->getJson(self::URL . '?paginated=1&per_page=100')->assertOk()->assertJsonCount($rows, 'data');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $oneOfEach();
        $this->listed(); // lo que se guarda en memoria en la primera consulta no cuenta
        $withFour = $queriesFor(4);
        $oneOfEach();
        $oneOfEach();

        $this->assertSame($withFour, $queriesFor(12));
    }

    // ─── Filtros: estado y fecha ────────────────────────────────────────────

    public function test_status_filter_separates_active_from_undone_and_reverted(): void
    {
        $coco = $this->createProduct([], 50);
        $vigente = $this->manualAdjustment($coco, 'out', 1);
        $deshecho = $this->manualAdjustment($coco, 'out', 2);
        $this->undo($deshecho)->assertOk();
        $cancelador = $this->createUserWithRole($this->location);
        $revertida = $this->productionWaste($coco, null, [
            'reverted_at' => '2026-10-16T18:05:00-06:00',
            'reverted_by' => $cancelador->id,
            'reverted_reason' => 'production_order_cancelled',
        ]);

        $ids = fn(string $query) => $this->listed($query)->keys()->sort()->values()->all();
        $this->assertSame([$vigente->id], $ids('&status=active'));
        $this->assertSame([$deshecho->id, $revertida->id], $ids('&status=reverted'));
        $this->assertSame([$vigente->id, $deshecho->id, $revertida->id], $ids(''));

        // La merma revertida al cancelar la orden también dice cuándo y quién.
        $row = $this->listed()[$revertida->id];
        $this->assertSame('2026-10-16T18:05:00-06:00', $row['reverted_at']);
        $this->assertSame(['id' => $cancelador->id, 'name' => $cancelador->name], $row['reverted_by_user']);
        $this->assertFalse($row['can_undo']);
        $this->assertNull($this->listed()[$vigente->id]['reverted_at']);
        $this->assertNull($this->listed()[$vigente->id]['reverted_by_user']);
    }

    public function test_date_filter_uses_the_adjustment_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 12:00:00'));
        $coco = $this->createProduct([], 50);
        $line = ['product_id' => $coco->id, 'direction' => 'out', 'quantity' => 1, 'unit_id' => $coco->unit_id, 'reason_code' => 'damage'];

        $deLunes = $this->adjust($line, ['applied_at' => '2026-10-12'])->assertCreated()->json('data.0.id');
        $deHoy = $this->adjust($line)->assertCreated()->json('data.0.id');
        // Sin applied_at cuenta la fecha de registro.
        $viejo = $this->generated($coco, ['applied_at' => null]);
        InventoryAdjustmentDetail::whereKey($viejo->id)->update(['created_at' => '2026-10-10 09:00:00']);

        $ids = fn(string $query) => $this->listed($query)->keys()->sort()->values()->all();
        // Registrado hoy, pero de lunes: sale el lunes y no hoy.
        $this->assertSame([$deLunes], $ids('&date_from=2026-10-12&date_to=2026-10-12'));
        $this->assertSame([$deHoy], $ids('&date_from=2026-10-17'));
        $this->assertSame([$viejo->id], $ids('&date_to=2026-10-10'));
        $this->assertSame([$deLunes], $ids('&start_date=2026-10-11&end_date=2026-10-16'));

        $this->actingInBranch($this->user)->getJson(self::URL . '?date_from=ayer')
            ->assertStatus(422)->assertJsonValidationErrors('date_from');
    }

    // ─── Reporte de mermas ──────────────────────────────────────────────────

    public function test_the_adjustments_report_leaves_out_undone_adjustments_and_reverted_wastes(): void
    {
        $coco = $this->createProduct(['name' => 'Coco', 'purchase_price' => 8], 50);
        $this->manualAdjustment($coco, 'out', 2, 'expiry');
        $this->undo($this->manualAdjustment($coco, 'out', 5, 'damage'))->assertOk();
        $this->productionWaste($coco, null, ['reverted_at' => now()->toIso8601String(), 'reverted_by' => $this->user->id]);

        $data = (new AdjustmentsReport(
            new ReportScope($this->company, null),
            ReportPeriod::between(now()->toDateString(), now()->toDateString()),
        ))->data();

        $this->assertSame(1, $data['totals']['loss_count']);
        $this->assertEqualsWithDelta(16.0, $data['totals']['loss_cost'], 0.001);
        $this->assertCount(1, $data['detail']);
        $this->assertSame('Vencimiento', $data['by_reason'][0]['name']);
    }
}
