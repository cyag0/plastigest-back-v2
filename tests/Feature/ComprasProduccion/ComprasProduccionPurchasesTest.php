<?php

namespace Tests\Feature\ComprasProduccion;

use App\Models\Admin\Location;
use App\Models\Product;
use App\Models\PurchaseDetailV2;
use App\Models\PurchaseV2;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
