<?php

namespace Tests\Feature\Flows;

use App\Models\ActivityLog;
use App\Models\Admin\Location;
use App\Models\CashRegister;
use App\Models\Product;
use App\Models\PurchaseV2;
use App\Models\StockLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * "Mi actividad" (bitácora) y kárdex general.
 */
class ActivityAndKardexTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const CASHIER = ['sales_pos', 'sales_list', 'sales_read', 'products_list', 'products_read', 'inventory_list'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        Sanctum::actingAs($this->user = $this->user->fresh(), ['*']);
    }

    private function descriptions(?int $userId = null): array
    {
        return ActivityLog::when($userId, fn($q) => $q->where('user_id', $userId))
            ->orderBy('id')->pluck('description')->all();
    }

    /**
     * Los datos que arma la prueba se crean con el usuario autenticado y
     * también quedan en la bitácora; no son lo que se mide. Antes no
     * aparecían solo porque la empresa "actual" de la prueba anterior seguía
     * puesta y el renglón fallaba en silencio por la llave foránea.
     */
    private function forgetFixtureActivity(): void
    {
        ActivityLog::query()->delete();
    }

    // ─── Bitácora ────────────────────────────────────────────────────────────

    public function test_a_cashier_shift_reads_as_plain_sentences(): void
    {
        $coca = $this->createProduct(['name' => 'Cocada', 'sale_price' => 18], 10);
        $register = CashRegister::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'name' => 'Caja 1', 'code' => 'C1',
        ]);
        $cashier = $this->createUserWithRole($this->location, self::CASHIER);
        Sanctum::actingAs($cashier, ['*']);
        $h = $this->tenantHeaders();

        $sessionId = $this->withHeaders($h)->postJson('/api/auth/admin/cash-register-sessions/open', [
            'cash_register_id' => $register->id, 'opening_amount' => 500,
        ])->assertCreated()->json('data.id');

        $saleNumber = $this->withHeaders($h)->postJson('/api/auth/admin/pos/sales', [
            'details' => [['product_id' => $coca->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 36, 'tendered' => 50]],
        ])->assertCreated()->json('data.sale_number');

        $this->withHeaders($h)->postJson("/api/auth/admin/cash-register-sessions/{$sessionId}/close", ['counted_cash' => 536])
            ->assertOk();

        $this->assertSame([
            'Abrió turno en Caja 1 con fondo de $500.00',
            "Registró la venta {$saleNumber} por $36.00",
            'Cerró el turno de Caja 1: contó $536.00 (cuadró)',
        ], $this->descriptions($cashier->id));

        // Y la cajera lo ve en "Mi actividad", del más reciente al más viejo.
        $this->withHeaders($h)->getJson('/api/auth/admin/activity')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.module', 'caja')
            ->assertJsonPath('data.1.subject.type', 'sale')
            ->assertJsonPath('data.1.subject.label', $saleNumber)
            ->assertJsonPath('data.0.location.id', $this->location->id);
    }

    public function test_edits_show_what_changed_and_noise_is_left_out(): void
    {
        $h = $this->tenantHeaders();
        $product = $this->createProduct(['name' => 'Cocada', 'sale_price' => 18]);
        $this->forgetFixtureActivity();

        $this->withHeaders($h)->putJson("/api/auth/admin/products/{$product->id}", ['sale_price' => 20])->assertOk();
        $this->withHeaders($h)->putJson("/api/auth/admin/products/{$product->id}", ['is_active' => false])->assertOk();

        // Una compra en borrador se sincroniza en vivo: no cuenta hasta confirmarse.
        $purchase = PurchaseV2::create([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'user_id' => $this->user->id,
            'purchase_number' => 'PUR000001', 'purchase_date' => now(), 'status' => 'draft', 'total' => 100,
        ]);
        $purchase->update(['total' => 150]);
        $purchase->update(['status' => 'ordered']);

        // Lo que hace el sistema sin un usuario (seeders, comandos) no es actividad de nadie.
        $this->app['auth']->forgetGuards();
        Product::whereKey($product->id)->first()->update(['sale_price' => 25]);

        $this->assertSame([
            'Editó el producto «Cocada»: precio de venta: $18.00 → $20.00',
            "Desactivó el producto «Cocada» en {$this->location->name}",
            'Confirmó la compra PUR000001',
        ], $this->descriptions());
    }

    public function test_permission_and_branch_changes_are_logged(): void
    {
        $h = $this->tenantHeaders();
        $other = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Guayabitos']);
        $this->forgetFixtureActivity();
        $roleId = $this->withHeaders($h)->postJson('/api/auth/admin/roles', [
            'name' => 'Cajero', 'permissions' => $this->permissionMap(['sales_pos']),
        ])->assertCreated()->json('data.id');

        $this->withHeaders($h)->putJson("/api/auth/admin/roles/{$roleId}", [
            'name' => 'Cajero', 'permissions' => $this->permissionMap(['sales_pos', 'sales_list', 'sales_read']),
        ])->assertOk();

        $userId = $this->withHeaders($h)->postJson('/api/auth/admin/users', [
            'name' => 'Ana', 'email' => 'ana@test.com', 'password' => 'Secreta123',
            'location_roles' => [['location_id' => $this->location->id, 'role_id' => $roleId]],
        ])->assertCreated()->json('data.id');
        $this->withHeaders($h)->putJson("/api/auth/admin/users/{$userId}", [
            'location_roles' => [['location_id' => $other->id, 'role_id' => $roleId]],
        ])->assertOk();
        $this->withHeaders($h)->putJson("/api/auth/admin/users/{$userId}", ['password' => 'OtraSecreta123'])->assertOk();

        $this->assertSame([
            'Creó el rol «Cajero»',
            'Cambió los permisos del rol «Cajero»: 2 agregados',
            'Creó el usuario «Ana»',
            'Cambió las sucursales de «Ana»: Guayabitos (Cajero)',
            'Cambió la contraseña del usuario «Ana»',
        ], $this->descriptions());
    }

    public function test_only_managers_see_other_peoples_activity(): void
    {
        $h = $this->tenantHeaders();
        $this->withHeaders($h)->postJson('/api/auth/admin/categories', ['name' => 'Dulces'])->assertCreated();

        $cashier = $this->createUserWithRole($this->location, self::CASHIER);
        Sanctum::actingAs($cashier, ['*']);
        $this->withHeaders($h)->getJson('/api/auth/admin/activity')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($h)->getJson("/api/auth/admin/activity?user_id={$this->user->id}")->assertForbidden();
        $this->withHeaders($h)->getJson('/api/auth/admin/activity?user_id=all')->assertForbidden();

        Sanctum::actingAs($this->user, ['*']);
        $all = collect($this->withHeaders($h)->getJson('/api/auth/admin/activity?user_id=all')->assertOk()->json('data'))
            ->pluck('description');
        $this->assertContains('Creó la categoría «Dulces»', $all);
        $this->withHeaders($h)->getJson('/api/auth/admin/activity?user_id=all&module=ventas')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // ─── Kárdex ──────────────────────────────────────────────────────────────

    private function ledger(Product $product, Location $location, string $reason, string $direction, float $qty, ?int $userId = null): void
    {
        StockLedgerEntry::create([
            'company_id' => $this->company->id, 'location_id' => $location->id, 'product_id' => $product->id,
            'direction' => $direction, 'reason' => $reason, 'quantity' => $qty,
            'previous_stock' => 0, 'new_stock' => 0, 'user_id' => $userId ?? $this->user->id,
        ]);
    }

    public function test_kardex_filters_and_respects_the_branch_of_the_user(): void
    {
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $coco = $this->createProduct(['name' => 'Coco entero']);
        $agua = $this->createProduct(['name' => 'Agua de coco']);
        $this->ledger($coco, $this->location, 'purchase', 'in', 50);
        $this->ledger($coco, $this->location, 'sale', 'out', 3);
        $this->ledger($agua, $this->location, 'sale', 'out', 2);
        $this->ledger($agua, $other, 'sale', 'out', 7);

        $h = $this->tenantHeaders();
        $this->withHeaders($h)->getJson('/api/auth/admin/inventory-movements?location_id=all')
            ->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('summary.entries_out', 3);
        $this->withHeaders($h)->getJson("/api/auth/admin/inventory-movements?location_id={$this->location->id}&reason=sale")
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->withHeaders($h)->getJson("/api/auth/admin/inventory-movements?location_id=all&product_id={$coco->id}")
            ->assertOk()->assertJsonPath('summary.product.net', 47)
            ->assertJsonPath('data.0.product.name', 'Coco entero');
        $this->withHeaders($h)->getJson('/api/auth/admin/inventory-movements?location_id=all&search=agua')
            ->assertOk()->assertJsonPath('meta.total', 2);

        // Sin "ver todas las sucursales" sólo ve la suya, aunque pida todas.
        Sanctum::actingAs($this->createUserWithRole($this->location, ['inventory_list']), ['*']);
        $this->withHeaders($h)->getJson('/api/auth/admin/inventory-movements?location_id=all')
            ->assertOk()->assertJsonPath('meta.total', 3);
        $this->withHeaders($h)->getJson("/api/auth/admin/inventory-movements?location_id={$other->id}")
            ->assertOk()->assertJsonPath('meta.total', 3);

        Sanctum::actingAs($this->createUserWithRole($this->location, ['sales_pos']), ['*']);
        $this->withHeaders($h)->getJson('/api/auth/admin/inventory-movements')->assertForbidden();
    }

    public function test_kardex_exports_csv_through_a_signed_url(): void
    {
        $coco = $this->createProduct(['name' => 'Coco entero', 'code' => 'COC-1']);
        $this->ledger($coco, $this->location, 'purchase', 'in', 50);

        $url = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/inventory-movements/export-url?location_id=all')
            ->assertOk()->json('url');

        $response = $this->get($url)->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('"Coco entero",COC-1,Compra,50', $csv);

        // La empresa va dentro de la firma: cambiarla invalida el enlace.
        $this->get(str_replace("company_id={$this->company->id}", 'company_id=999', $url))->assertForbidden();
        $this->get(URL::temporarySignedRoute('inventory-movements.export', now()->subMinute(), ['company_id' => $this->company->id]))
            ->assertForbidden();
    }

    private function permissionMap(array $names): array
    {
        return DB::table('permissions')->whereIn('name', $names)->pluck('id')
            ->mapWithKeys(fn($id) => [$id => true])->all();
    }
}
