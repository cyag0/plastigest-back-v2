<?php

namespace Tests\Feature\ComprasProduccion;

use App\Models\Admin\Location;
use App\Models\InventoryType;
use App\Models\Operations\ProductionOrder;
use App\Models\Operations\ProductionOrderPackaging;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductionOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Producción: las acciones sobre una orden se limitan a la empresa y a la
 * sucursal de quien las hace, los errores dicen el motivo en `message`, el
 * formulario obtiene sus responsables sin pedir la lista de usuarios y una
 * sección que se vacía en un borrador se borra.
 */
class ComprasProduccionProductionTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Rol de planta: registra y opera producción en su sucursal. */
    private const PRODUCER = [
        'production_orders_list', 'production_orders_read', 'production_orders_create',
        'production_orders_update', 'production_orders_delete',
    ];

    private Product $coco;
    private Product $granel;
    private Location $pitillal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        // Releído para traer is_active: el middleware rechaza cuentas inactivas.
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);

        $this->coco = $this->createProduct(['name' => 'Coco'], 50);
        $this->granel = $this->createProduct(['name' => 'Dulce de coco granel'], 30, 'Kilogramo', InventoryType::FINISHED_PRODUCT);
        $this->pitillal = $this->createBranch(['name' => 'Pitillal']);
        $this->setStock($this->coco, $this->pitillal, 50);
        $this->setStock($this->granel, $this->pitillal, 30);
    }

    // ─── Ayudas ─────────────────────────────────────────────────────────────

    /** 14 cocos → 10 kg de granel, y lo que se sobrescriba. */
    private function payload(array $overrides = [], ?Product $coco = null, ?Product $granel = null): array
    {
        return array_merge([
            'production_date' => now()->toDateString(),
            'responsible_user_id' => $this->user->id,
            'consumptions' => [['product_id' => ($coco ?? $this->coco)->id, 'unit_id' => $this->unitId('Pieza'), 'quantity' => 14]],
            'outputs' => [['product_id' => ($granel ?? $this->granel)->id, 'unit_id' => $this->unitId('Kilogramo'), 'quantity' => 10]],
        ], $overrides);
    }

    private function draft(?Location $location = null, ?User $user = null, array $overrides = [], ?Product $coco = null, ?Product $granel = null): int
    {
        $user ??= $this->user;

        return $this->actingInBranch($user, $location)
            ->postJson('/api/auth/admin/production-orders', $this->payload(['responsible_user_id' => $user->id] + $overrides, $coco, $granel))
            ->assertCreated()
            ->json('data.id');
    }

    private function completed(?Location $location = null, ?User $user = null, ?Product $coco = null, ?Product $granel = null): int
    {
        $id = $this->draft($location, $user, [], $coco, $granel);
        $this->actingInBranch($user ?? $this->user, $location)
            ->postJson("/api/auth/admin/production-orders/{$id}/complete")
            ->assertOk();

        return $id;
    }

    /** Frasco de 250 g que se empaca del granel. */
    private function jar(?Product $granel = null, array $attributes = []): Product
    {
        return $this->createProduct(array_merge([
            'name' => 'Dulce de coco 250 g',
            'requires_packaging' => true,
            'packaging_source_product_id' => ($granel ?? $this->granel)->id,
            'packaging_source_quantity' => 0.25,
            'packaging_source_unit_id' => $this->unitId('Kilogramo'),
        ], $attributes), 0, 'Pieza', InventoryType::FINISHED_PRODUCT);
    }

    private function producer(?Location $location = null, array $permissions = self::PRODUCER): User
    {
        return $this->createBranchUser($location ?? $this->location, $permissions)->fresh();
    }

    /**
     * Acciones por id sobre una orden, con lo que piden. Todas deben responder
     * 404 si la orden no es del usuario.
     *
     * @return array<string, array{0: string, 1: string, 2: array}>
     */
    private function actionsOn(int $orderId): array
    {
        $base = "/api/auth/admin/production-orders/{$orderId}";

        return [
            'ver' => ['getJson', $base, []],
            'editar' => ['putJson', $base, ['notes' => 'ajena']],
            'eliminar' => ['deleteJson', $base, []],
            'completar' => ['postJson', "{$base}/complete", []],
            'cancelar' => ['postJson', "{$base}/cancel", []],
            'variación' => ['getJson', "{$base}/variance", []],
        ];
    }

    // ─── Aislamiento ────────────────────────────────────────────────────────

    public function test_a_branch_producer_cannot_touch_another_branch_order(): void
    {
        $jar = $this->jar();
        $draft = $this->draft($this->pitillal);
        // Registrada con su envasado en el mismo paso.
        $packed = $this->actingInBranch($this->user, $this->pitillal)
            ->postJson('/api/auth/admin/production-orders', $this->payload([
                'packagings' => [['product_id' => $jar->id, 'quantity' => 4]],
                'affect_stock' => true,
            ]))
            ->assertCreated()
            ->json('data.id');

        $stock = fn() => [$this->stockOf($this->coco, $this->pitillal), $this->stockOf($this->granel, $this->pitillal), $this->stockOf($jar, $this->pitillal)];
        $before = $stock();
        $producer = $this->producer();

        foreach ([$draft, $packed] as $orderId) {
            $status = ProductionOrder::findOrFail($orderId)->status;

            foreach ($this->actionsOn($orderId) as $action => [$method, $url, $payload]) {
                $this->actingInBranch($producer)->{$method}($url, $payload)
                    ->assertNotFound("{$action} una orden de otra sucursal");
            }

            $this->assertSame($status, ProductionOrder::findOrFail($orderId)->status, 'el estado no cambia');
        }

        $this->assertSame($before, $stock(), 'el stock de la otra sucursal no se mueve');
        $this->assertSame(1, ProductionOrderPackaging::where('production_order_id', $packed)->count());
    }

    public function test_orders_of_another_company_are_not_found_even_for_an_admin(): void
    {
        [, $branch] = $this->createCompanyWithBranch();
        $owner = $this->createUserWithRole($branch)->fresh();
        $coco = $this->createProduct(['name' => 'Coco B', 'company_id' => $branch->company_id], 0);
        $granel = $this->createProduct(['name' => 'Granel B', 'company_id' => $branch->company_id], 0, 'Kilogramo', InventoryType::FINISHED_PRODUCT);
        $this->setStock($coco, $branch, 50);
        $order = $this->completed($branch, $owner, $coco, $granel);

        foreach ($this->actionsOn($order) as $action => [$method, $url, $payload]) {
            $this->actingInBranch($this->user)->{$method}($url, $payload)
                ->assertNotFound("{$action} una orden de otra empresa");
        }

        $this->assertSame('completed', ProductionOrder::findOrFail($order)->status->value);
        $this->assertEquals(36, $this->stockOf($coco, $branch));
        $this->assertEquals(10, $this->stockOf($granel, $branch));
    }

    public function test_packaging_later_is_limited_to_the_branch_stock(): void
    {
        // La matriz no tiene granel; Pitillal sí (30 kg).
        $this->setStock($this->granel, $this->location, 0);
        $jar = $this->jar();
        $payload = [
            'kind' => ProductionOrder::KIND_PACKAGING,
            'production_date' => now()->toDateString(),
            'packagings' => [['product_id' => $jar->id, 'quantity' => 4]],
            'affect_stock' => true,
        ];

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/production-orders', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_bulk');

        $this->actingInBranch($this->user, $this->pitillal)
            ->postJson('/api/auth/admin/production-orders', $payload)
            ->assertCreated();

        $this->assertEquals(4, $this->stockOf($jar, $this->pitillal));
        $this->assertEquals(29, $this->stockOf($this->granel, $this->pitillal));
        $this->assertSame(1, ProductionOrderPackaging::count());
    }

    public function test_an_all_branches_admin_can_still_complete_another_branch_order(): void
    {
        $order = $this->draft($this->pitillal);

        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/production-orders/{$order}/complete")
            ->assertOk();

        $this->assertEquals(36, $this->stockOf($this->coco, $this->pitillal));
        $this->assertEquals(50, $this->stockOf($this->coco), 'la sucursal actual no se toca');
    }

    public function test_products_and_formulas_of_another_company_are_rejected(): void
    {
        [$other] = $this->createCompanyWithBranch();
        $foreign = $this->createProduct(['name' => 'Coco ajeno', 'company_id' => $other->id], 0);
        $formulaId = DB::table('formulas')->insertGetId([
            'company_id' => $other->id, 'name' => 'Fórmula ajena', 'version' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/production-orders', $this->payload(['formula_id' => $formulaId], $foreign))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consumptions.0.product_id', 'formula_id']);

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/production-orders', $this->payload([
                'packagings' => [['product_id' => $foreign->id, 'quantity' => 1]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('packagings.0.product_id');

        $this->assertSame(0, ProductionOrder::count());
    }

    // ─── Motivo de los errores ──────────────────────────────────────────────

    public function test_a_failed_action_says_why_in_message(): void
    {
        $this->setStock($this->coco, $this->location, 5);
        $order = $this->draft();

        // Sin cocos suficientes: el motivo en message (y en error, donde lo
        // leen las pantallas de empaque), con su code.
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/production-orders/{$order}/complete")
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', fn($message) => str_contains($message, 'Stock insuficiente para "Coco"'))
            ->assertJsonPath('error', fn($error) => str_contains($error, 'Stock insuficiente'));

        $this->actingInBranch($this->user)->postJson("/api/auth/admin/production-orders/{$order}/cancel")->assertOk();

        // Antes: "Error al cancelar producción" y "No se puede cambiar de
        // estado cancelled a cancelled" escondido en error.
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/production-orders/{$order}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_status')
            ->assertJsonPath('message', 'La producción ya está cancelada.');

        // Registrar una cancelada: el motivo en palabras de la app.
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/production-orders/{$order}/complete")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_status')
            ->assertJsonPath('message', 'Solo se puede registrar una producción pendiente (esta está «Cancelada»).');
    }

    public function test_registering_without_stock_explains_the_reason_and_keeps_errors_stock(): void
    {
        $this->setStock($this->coco, $this->location, 5);

        $this->actingInBranch($this->user)
            ->postJson('/api/auth/admin/production-orders', $this->payload(['affect_stock' => true]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', fn($message) => str_contains($message, 'Stock insuficiente para "Coco"'))
            ->assertJsonValidationErrors('stock');

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_an_internal_error_is_not_shown_to_the_user(): void
    {
        $order = $this->draft();

        $service = Mockery::mock(ProductionOrderService::class);
        $service->shouldReceive('complete')->andThrow(
            new QueryException('mysql', 'update `secret_table` set x = 1', [], new \Exception('SQLSTATE[HY000] secreto'))
        );
        $this->app->instance(ProductionOrderService::class, $service);

        $response = $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/production-orders/{$order}/complete")
            ->assertStatus(500)
            ->assertJsonPath('message', 'No se pudo completar la producción.');

        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ─── Responsables del formulario ────────────────────────────────────────

    public function test_a_production_role_without_users_list_gets_its_responsibles(): void
    {
        $producer = $this->producer($this->location, ['production_orders_create']);
        $sameBranch = $this->producer();
        $otherBranch = $this->producer($this->pitillal);
        $inactive = $this->producer();
        $inactive->update(['is_active' => false]);
        [, $foreignBranch] = $this->createCompanyWithBranch();
        $foreign = $this->createUserWithRole($foreignBranch);

        $responsibles = $this->actingInBranch($producer)
            ->getJson('/api/auth/admin/production-orders/initial-data')
            ->assertOk()
            ->json('data.responsibles');

        $ids = array_column($responsibles, 'id');
        sort($ids);
        // Los de la sucursal y el administrador de la empresa (ve todas).
        $expected = [$this->user->id, $producer->id, $sameBranch->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains($otherBranch->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->assertSame(['id', 'name'], array_keys($responsibles[0]), 'sin correo');

        // Sin permiso de producción, nada.
        $this->actingInBranch($this->producer($this->location, ['sales_list']))
            ->getJson('/api/auth/admin/production-orders/initial-data')
            ->assertForbidden();
    }

    public function test_the_responsible_defaults_to_who_registers_and_must_be_of_the_company(): void
    {
        $producer = $this->producer($this->location, ['production_orders_create', 'production_orders_read']);

        // Sin responsable (o en 0, el valor inicial del formulario): quien registra.
        foreach ([['responsible_user_id' => 0], []] as $overrides) {
            $payload = array_merge($this->payload(), $overrides);
            if ($overrides === []) {
                unset($payload['responsible_user_id']);
            }

            $id = $this->actingInBranch($producer)
                ->postJson('/api/auth/admin/production-orders', $payload)
                ->assertCreated()
                ->json('data.id');
            $this->assertSame($producer->id, ProductionOrder::findOrFail($id)->responsible_user_id);
        }

        [, $foreignBranch] = $this->createCompanyWithBranch();
        $foreign = $this->createUserWithRole($foreignBranch);
        $this->actingInBranch($producer)
            ->postJson('/api/auth/admin/production-orders', $this->payload(['responsible_user_id' => $foreign->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('responsible_user_id');
    }

    public function test_a_branch_producer_only_sees_the_kpis_of_their_branch(): void
    {
        $this->completed($this->pitillal);
        $producer = $this->producer();

        $this->actingInBranch($producer)
            ->getJson("/api/auth/admin/production-orders/today-stats?location_id={$this->pitillal->id}")
            ->assertOk()
            ->assertJsonPath('data.productions_count_today', 0);

        $this->actingInBranch($this->user)
            ->getJson("/api/auth/admin/production-orders/today-stats?location_id={$this->pitillal->id}")
            ->assertOk()
            ->assertJsonPath('data.productions_count_today', 1);
    }

    // ─── Borradores: secciones vaciadas ─────────────────────────────────────

    private function waste(float $quantity = 2): array
    {
        return ['product_id' => $this->coco->id, 'unit_id' => $this->unitId('Pieza'), 'quantity' => $quantity, 'reason' => 'damage'];
    }

    public function test_removing_every_waste_of_a_draft_clears_them(): void
    {
        $id = $this->draft(null, null, ['wastes' => [$this->waste()]]);
        $url = "/api/auth/admin/production-orders/{$id}";

        // Sin la marca, una lista que no viene se queda como está.
        $this->actingInBranch($this->user)->putJson($url, ['notes' => 'Solo cambio la nota'])->assertOk();
        $this->assertCount(1, ProductionOrder::findOrFail($id)->getAttribute('wastes'));

        // La app quitó la única merma: en FormData no viaja la lista vacía y
        // manda wastes_empty=1. Se registra sin descontar esa merma.
        $payload = $this->payload(['wastes_empty' => '1', 'affect_stock' => true]);
        $this->actingInBranch($this->user)->putJson($url, $payload)->assertOk();

        $this->assertSame('completed', ProductionOrder::findOrFail($id)->status->value);
        $this->assertEquals(36, $this->stockOf($this->coco), 'solo los 14 consumidos, no la merma quitada');
        $this->assertSame(0, ProductionOrder::findOrFail($id)->wastes()->count(), 'no queda ajuste de merma');
    }

    public function test_emptied_consumptions_and_outputs_of_a_draft_are_deleted(): void
    {
        $id = $this->draft();
        $url = "/api/auth/admin/production-orders/{$id}";

        $this->actingInBranch($this->user)->putJson($url, ['consumptions_empty' => 'true', 'outputs_empty' => 1])->assertOk();

        $order = ProductionOrder::findOrFail($id);
        $this->assertSame(0, $order->consumptions()->count());
        $this->assertSame(0, $order->outputs()->count());

        // Con renglones, la marca no estorba: mandan los renglones.
        $this->actingInBranch($this->user)->putJson($url, $this->payload(['consumptions_empty' => 1]))->assertOk();
        $this->assertSame(1, $order->consumptions()->count());
    }

    // ─── Folios ─────────────────────────────────────────────────────────────

    public function test_each_company_numbers_its_own_production_orders(): void
    {
        $prefix = 'PROD-' . date('Y') . '-';
        [, $branch] = $this->createCompanyWithBranch();
        $owner = $this->createUserWithRole($branch)->fresh();
        $coco = $this->createProduct(['name' => 'Coco B', 'company_id' => $branch->company_id], 0);
        $granel = $this->createProduct(['name' => 'Granel B', 'company_id' => $branch->company_id], 0, 'Kilogramo', InventoryType::FINISHED_PRODUCT);

        $first = $this->draft();
        // La primera orden de la otra empresa chocaba con el índice único.
        $foreign = $this->draft($branch, $owner, [], $coco, $granel);
        $second = $this->draft();

        $this->assertSame("{$prefix}0001", ProductionOrder::findOrFail($first)->folio);
        $this->assertSame("{$prefix}0001", ProductionOrder::findOrFail($foreign)->folio);
        $this->assertSame("{$prefix}0002", ProductionOrder::findOrFail($second)->folio);
    }

    public function test_numbering_continues_after_the_folios_already_issued(): void
    {
        $prefix = 'PROD-' . date('Y') . '-';
        ProductionOrder::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'folio' => "{$prefix}0041",
            'production_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $this->assertSame("{$prefix}0042", ProductionOrder::findOrFail($this->draft())->folio);
    }

    // ─── Cancelación: mermas revertidas una sola vez ────────────────────────

    public function test_cancelling_marks_the_waste_adjustments_as_reverted(): void
    {
        $id = $this->draft(null, null, ['wastes' => [$this->waste(2)]]);
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/production-orders/{$id}/complete")->assertOk();
        $this->assertEquals(34, $this->stockOf($this->coco), '14 consumidos y 2 de merma');

        $this->actingInBranch($this->user)->postJson("/api/auth/admin/production-orders/{$id}/cancel")->assertOk();

        $this->assertEquals(50, $this->stockOf($this->coco));
        $waste = ProductionOrder::findOrFail($id)->wastes()->sole();
        $this->assertNotEmpty($waste->content['reverted_at'] ?? null);
        $this->assertSame('production_order_cancelled', $waste->content['reverted_reason']);
        $this->assertSame($this->user->id, $waste->content['reverted_by']);
    }

    public function test_a_waste_already_reverted_is_not_reverted_again(): void
    {
        $id = $this->draft(null, null, ['wastes' => [$this->waste(2)]]);
        $this->actingInBranch($this->user)->postJson("/api/auth/admin/production-orders/{$id}/complete")->assertOk();

        $waste = ProductionOrder::findOrFail($id)->wastes()->sole();
        $waste->update(['content' => ['reverted_at' => now()->toIso8601String()]]);

        $this->actingInBranch($this->user)->postJson("/api/auth/admin/production-orders/{$id}/cancel")->assertOk();

        // Vuelven los 14 consumidos; la merma ya revertida no se devuelve otra vez.
        $this->assertEquals(48, $this->stockOf($this->coco));
    }
}
