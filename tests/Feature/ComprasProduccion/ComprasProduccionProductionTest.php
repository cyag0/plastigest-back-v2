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
 * sucursal de quien las hace, y los errores dicen el motivo en `message`.
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
     * Acciones por id sobre una orden (y uno de sus empaques), con lo que
     * piden. Todas deben responder 404 si la orden no es del usuario.
     *
     * @return array<string, array{0: string, 1: string, 2: array}>
     */
    private function actionsOn(int $orderId, ?int $packagingId = null, ?Product $jar = null): array
    {
        $base = "/api/auth/admin/production-orders/{$orderId}";

        return array_filter([
            'ver' => ['getJson', $base, []],
            'editar' => ['putJson', $base, ['notes' => 'ajena']],
            'eliminar' => ['deleteJson', $base, []],
            'completar' => ['postJson', "{$base}/complete", []],
            'cancelar' => ['postJson', "{$base}/cancel", []],
            'variación' => ['getJson', "{$base}/variance", []],
            'plan de empaque' => ['getJson', "{$base}/packaging", []],
            'empacar' => $jar ? ['postJson', "{$base}/packaging", ['packagings' => [['product_id' => $jar->id, 'quantity' => 1]]]] : null,
            'revertir empaque' => $packagingId ? ['deleteJson', "{$base}/packaging/{$packagingId}", []] : null,
            'cerrar empaque' => ['postJson', "{$base}/packaging/finish", []],
        ]);
    }

    // ─── Aislamiento ────────────────────────────────────────────────────────

    public function test_a_branch_producer_cannot_touch_another_branch_order(): void
    {
        $jar = $this->jar();
        $draft = $this->draft($this->pitillal);
        $packed = $this->completed($this->pitillal);
        $this->actingInBranch($this->user, $this->pitillal)
            ->postJson("/api/auth/admin/production-orders/{$packed}/packaging", ['packagings' => [['product_id' => $jar->id, 'quantity' => 4]]])
            ->assertOk();
        $packaging = ProductionOrderPackaging::where('production_order_id', $packed)->value('id');

        $stock = fn() => [$this->stockOf($this->coco, $this->pitillal), $this->stockOf($this->granel, $this->pitillal), $this->stockOf($jar, $this->pitillal)];
        $before = $stock();
        $producer = $this->producer();

        foreach ([$draft => null, $packed => $packaging] as $orderId => $packagingId) {
            $status = ProductionOrder::findOrFail($orderId)->status;

            foreach ($this->actionsOn($orderId, $packagingId, $jar) as $action => [$method, $url, $payload]) {
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

    public function test_a_packaging_is_only_reverted_through_its_own_order(): void
    {
        $jar = $this->jar();
        $first = $this->completed();
        $second = $this->completed();
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/production-orders/{$first}/packaging", ['packagings' => [['product_id' => $jar->id, 'quantity' => 4]]])
            ->assertOk();
        $packaging = ProductionOrderPackaging::where('production_order_id', $first)->value('id');

        $this->actingInBranch($this->user)
            ->deleteJson("/api/auth/admin/production-orders/{$second}/packaging/{$packaging}")
            ->assertNotFound();

        $this->assertEquals(4, $this->stockOf($jar));
        $this->assertNotNull(ProductionOrderPackaging::find($packaging));
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

        $order = $this->completed();
        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/production-orders/{$order}/packaging", ['packagings' => [['product_id' => $foreign->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('packagings.0.product_id');

        $this->assertSame(1, ProductionOrder::count());
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

        $this->actingInBranch($this->user)
            ->postJson("/api/auth/admin/production-orders/{$order}/packaging", ['packagings' => [['product_id' => $this->jar()->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_status');
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
}
