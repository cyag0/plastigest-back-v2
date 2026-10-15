<?php

namespace Tests\Feature\Flows;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\InventoryType;
use Database\Seeders\CompaniesSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ResourceSeeder;
use Database\Seeders\UnitsSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Arranque real en producción: la base solo tiene lo que siembra el deploy
 * (empresa + sucursales, permisos, unidades y el Super Admin). Todo lo demás
 * lo da de alta el dueño desde la app, con tokens reales de login.
 */
class ProductionOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Location $matriz;

    /** ADMIN_PASSWORD con la que UserSeeder crea al Super Admin. */
    private const ADMIN_PASSWORD = 'Clave-del-dueno-2026';

    protected function setUp(): void
    {
        parent::setUp();

        // Como en producción: la contraseña del Super Admin viene del entorno.
        putenv('ADMIN_PASSWORD=' . self::ADMIN_PASSWORD);
        $_ENV['ADMIN_PASSWORD'] = $_SERVER['ADMIN_PASSWORD'] = self::ADMIN_PASSWORD;
        $this->beforeApplicationDestroyed(function () {
            putenv('ADMIN_PASSWORD');
            unset($_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_PASSWORD']);
        });

        $this->seed([ResourceSeeder::class, PermissionSeeder::class, UnitsSeeder::class, CompaniesSeeder::class, UserSeeder::class]);

        $this->company = Company::firstOrFail();
        $this->matriz = Location::where('company_id', $this->company->id)->orderBy('id')->firstOrFail();
    }

    private function login(string $email, string $password = self::ADMIN_PASSWORD): string
    {
        return $this->attemptLogin($email, $password)->assertOk()->json('access_token');
    }

    private function attemptLogin(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        // En un servidor real cada petición arranca de cero; en el test la app
        // se reutiliza y la petición anterior dejó sanctum como guard por defecto.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function as(string $token, ?Location $location = null): static
    {
        // Como en producción: la API no usa sesión, solo el token de cada petición.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Company-ID' => (string) $this->company->id,
            'X-Location-ID' => (string) ($location ?? $this->matriz)->id,
            'Accept' => 'application/json',
        ]);
    }

    private function permissionMap(array $names): array
    {
        return DB::table('permissions')->whereIn('name', $names)->pluck('id')
            ->mapWithKeys(fn($id) => [$id => true])->all();
    }

    private function unitId(string $name): int
    {
        return (int) DB::table('units')->where('name', $name)->value('id');
    }

    public function test_owner_sets_up_the_business_and_a_cashier_sells(): void
    {
        $admin = $this->login('admin@cocos-francisco.com');

        // ── Lo que ve el dueño al entrar ─────────────────────────────────────
        $this->as($admin)->getJson('/api/auth/admin/companies')->assertOk()
            ->assertJsonPath('data.0.id', $this->company->id);
        $branches = $this->as($admin)->getJson('/api/auth/admin/locations?per_page=50')->assertOk()->json('data');
        $this->assertCount(Location::count(), $branches);

        // ── Roles ────────────────────────────────────────────────────────────
        $cashierPerms = ['sales_pos', 'sales_list', 'sales_read', 'sales_create', 'products_list', 'products_read',
            'customers_list', 'customers_read', 'customers_create', 'inventory_list', 'cash_registers_list', 'dashboard_view'];
        $cashierRole = $this->as($admin)->postJson('/api/auth/admin/roles', [
            'name' => 'Cajero', 'description' => 'Vende en caja', 'permissions' => $this->permissionMap($cashierPerms),
        ])->assertCreated()->json('data.id');
        $this->assertSame(count($cashierPerms), DB::table('rol_permission')->where('role_id', $cashierRole)->count());

        // locations_all: es encargado de todas las sucursales (da de alta
        // cajeros con sus sucursales, location_roles).
        $managerPerms = ['users_list', 'users_read', 'users_create', 'users_update', 'roles_list', 'locations_list', 'locations_all',
            'products_list', 'products_read', 'products_create', 'products_update', 'inventory_list', 'inventory_manage',
            'cash_registers_list', 'cash_registers_create', 'sales_list', 'sales_read', 'dashboard_view',
            // Para dar de alta cajeros tiene que tener, al menos, los permisos del cajero.
            ...$cashierPerms];
        $managerRole = $this->as($admin)->postJson('/api/auth/admin/roles', [
            'name' => 'Encargado', 'permissions' => $this->permissionMap($managerPerms),
        ])->assertCreated()->json('data.id');

        // ── Usuarios: como lo manda la app (alta y luego sucursales) ────────
        $cashierId = $this->as($admin)->postJson('/api/auth/admin/users', [
            'name' => 'Ana Cajera', 'email' => 'ana@cocos.test', 'password' => 'Secreta123', 'is_active' => true,
        ])->assertCreated()->json('data.id');
        $this->as($admin)->putJson("/api/auth/admin/users/{$cashierId}", [
            'location_roles' => [['location_id' => $this->matriz->id, 'role_id' => $cashierRole]],
        ])->assertOk();

        $managerId = $this->as($admin)->postJson('/api/auth/admin/users', [
            'name' => 'Beto Encargado', 'email' => 'beto@cocos.test', 'password' => 'Secreta123',
            'location_roles' => [['location_id' => $this->matriz->id, 'role_id' => $managerRole]],
        ])->assertCreated()->json('data.id');

        $this->as($admin)->getJson("/api/auth/admin/users/{$cashierId}")->assertOk()
            ->assertJsonPath('data.location_roles.0.location_id', $this->matriz->id)
            ->assertJsonPath('data.location_roles.0.role_id', $cashierRole);
        $listed = collect($this->as($admin)->getJson('/api/auth/admin/users?per_page=50')->json('data'))->pluck('id');
        $this->assertTrue($listed->contains($cashierId) && $listed->contains($managerId));

        // ── Catálogo ─────────────────────────────────────────────────────────
        $categoryId = $this->as($admin)->postJson('/api/auth/admin/categories', [
            'name' => 'Dulces', 'company_id' => $this->company->id, 'is_active' => true,
        ])->assertCreated()->json('data.id');

        $supplierId = $this->as($admin)->postJson('/api/auth/admin/suppliers', [
            'name' => 'Cocos del Pacífico', 'company_id' => $this->company->id, 'is_active' => true,
        ])->assertCreated()->json('data.id');

        $this->as($admin)->postJson('/api/auth/admin/customers', [
            'name' => 'Abarrotes Lupita', 'company_id' => $this->company->id, 'is_active' => true,
        ])->assertCreated();

        $productId = $this->as($admin)->post('/api/auth/admin/products', [
            'name' => 'Cocada', 'code' => 'COC-001', 'category_id' => $categoryId, 'supplier_id' => $supplierId,
            'inventory_type_id' => InventoryType::idForCode(InventoryType::FINISHED_PRODUCT),
            'unit_id' => $this->unitId('Pieza'), 'current_location_id' => $this->matriz->id,
            'purchase_price' => '8', 'sale_price' => '20', 'is_active' => '1', 'for_sale' => '1',
            'minimum_stock' => '', 'maximum_stock' => '',
        ])->assertCreated()->json('data.id');

        $this->as($admin)->postJson('/api/auth/admin/product-packages', [
            'product_id' => $productId, 'package_name' => 'Caja', 'barcode' => 'CAJA-COC-12',
            'quantity_per_package' => 12, 'sale_price' => 216, 'is_active' => true,
        ])->assertCreated();

        // Existencia inicial con un ajuste de entrada.
        $this->as($admin)->postJson('/api/auth/admin/inventory-adjustments', [
            'details' => [['product_id' => $productId, 'direction' => 'in', 'quantity' => 50,
                'unit_id' => $this->unitId('Pieza'), 'reason_code' => 'found']],
        ])->assertCreated();

        $registerId = $this->as($admin)->postJson('/api/auth/admin/cash-registers', [
            'name' => 'Caja 1', 'code' => 'MTZ-1', 'location_id' => $this->matriz->id,
        ])->assertCreated()->json('data.id');

        // ── La cajera entra y vende ──────────────────────────────────────────
        $ana = $this->login('ana@cocos.test', 'Secreta123');
        $this->as($ana)->getJson('/api/auth/admin/companies')->assertOk()
            ->assertJsonPath('data.0.id', $this->company->id);
        $this->as($ana)->getJson('/api/auth/admin/pos/catalog')->assertOk();

        $sessionId = $this->as($ana)->postJson('/api/auth/admin/cash-register-sessions/open', [
            'cash_register_id' => $registerId, 'opening_amount' => 300,
        ])->assertCreated()->json('data.id');

        $this->as($ana)->postJson('/api/auth/admin/pos/sales', [
            'details' => [['product_id' => $productId, 'quantity' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 60, 'tendered' => 100]],
        ])->assertCreated();

        $this->assertEqualsWithDelta(47, (float) DB::table('product_location')
            ->where('product_id', $productId)->where('location_id', $this->matriz->id)->value('current_stock'), 0.001);

        $this->as($ana)->postJson("/api/auth/admin/cash-register-sessions/{$sessionId}/close", ['counted_cash' => 360])
            ->assertOk()->assertJsonPath('data.expected_cash', 360);

        // Lo que no le toca.
        $this->as($ana)->getJson('/api/auth/admin/users')->assertForbidden();
        $this->as($ana)->postJson('/api/auth/admin/roles', ['name' => 'X'])->assertForbidden();

        // ── El encargado da de alta a otro cajero, sin escalar privilegios ───
        $beto = $this->login('beto@cocos.test', 'Secreta123');
        $this->as($beto)->postJson('/api/auth/admin/users', [
            'name' => 'Caro Cajera', 'email' => 'caro@cocos.test', 'password' => 'Secreta123',
            'location_roles' => [['location_id' => $this->matriz->id, 'role_id' => $cashierRole]],
        ])->assertCreated();
        $superAdminRole = (int) DB::table('roles')->where('name', 'Super Admin')->value('id');
        $this->as($beto)->postJson('/api/auth/admin/users', [
            'name' => 'Intruso', 'email' => 'x@cocos.test', 'password' => 'Secreta123',
            'location_roles' => [['location_id' => $this->matriz->id, 'role_id' => $superAdminRole]],
        ])->assertForbidden();

        // ── Baja de un usuario: pierde el acceso de inmediato ───────────────
        $this->as($admin)->putJson("/api/auth/admin/users/{$cashierId}", ['is_active' => false])->assertOk();
        $this->as($ana)->getJson('/api/auth/admin/companies')->assertUnauthorized();
        $this->attemptLogin('ana@cocos.test', 'Secreta123')->assertStatus(403);
    }

    public function test_a_new_branch_is_usable_right_away(): void
    {
        $admin = $this->login('admin@cocos-francisco.com');

        $productId = $this->as($admin)->post('/api/auth/admin/products', [
            'name' => 'Agua de coco 1L', 'code' => 'AGU-1L',
            'inventory_type_id' => InventoryType::idForCode(InventoryType::FINISHED_PRODUCT),
            'unit_id' => $this->unitId('Pieza'), 'current_location_id' => $this->matriz->id,
            'sale_price' => '35', 'is_active' => '1', 'for_sale' => '1',
        ])->assertCreated()->json('data.id');

        $branchId = $this->as($admin)->postJson('/api/auth/admin/locations', [
            'name' => 'Sucursal Nueva', 'address' => 'Av. Siempre Viva 1', 'company_id' => $this->company->id, 'is_active' => true,
        ])->assertCreated()->json('data.id');
        $branch = Location::findOrFail($branchId);

        // El Super Admin entra a la sucursal nueva y el catálogo ya está ahí.
        $this->as($admin, $branch)->getJson('/api/auth/admin/pos/catalog')->assertOk();
        $this->assertDatabaseHas('product_location', ['product_id' => $productId, 'location_id' => $branchId, 'active' => 1]);

        $this->as($admin, $branch)->postJson('/api/auth/admin/cash-registers', [
            'name' => 'Caja 1', 'code' => 'NUE-1', 'location_id' => $branchId,
        ])->assertCreated();

        // Editar y desactivar la sucursal.
        $this->as($admin)->putJson("/api/auth/admin/locations/{$branchId}", [
            'name' => 'Sucursal Nueva Centro', 'address' => 'Av. Siempre Viva 1', 'company_id' => $this->company->id, 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.name', 'Sucursal Nueva Centro');
    }

    public function test_owner_edits_the_company_and_manages_roles(): void
    {
        $admin = $this->login('admin@cocos-francisco.com');

        $this->as($admin)->putJson("/api/auth/admin/companies/{$this->company->id}", [
            'name' => $this->company->name, 'business_name' => 'Cocos Francisco S.A. de C.V.',
            'rfc' => $this->company->rfc, 'email' => 'contacto@cocos.test', 'phone' => '322 123 4567',
        ])->assertOk()->assertJsonPath('data.business_name', 'Cocos Francisco S.A. de C.V.');

        $roleId = $this->as($admin)->postJson('/api/auth/admin/roles', [
            'name' => 'Temporal', 'permissions' => $this->permissionMap(['sales_list']),
        ])->assertCreated()->json('data.id');
        $this->as($admin)->putJson("/api/auth/admin/roles/{$roleId}", [
            'name' => 'Temporal 2', 'permissions' => $this->permissionMap(['sales_list', 'sales_read']),
        ])->assertOk();
        $this->assertSame(2, DB::table('rol_permission')->where('role_id', $roleId)->count());
        $this->as($admin)->deleteJson("/api/auth/admin/roles/{$roleId}")->assertOk();

        // El rol de sistema no se puede borrar ni modificar (el dueño se quedaba sin acceso).
        $superAdminRole = (int) DB::table('roles')->where('name', 'Super Admin')->value('id');
        $this->as($admin)->deleteJson("/api/auth/admin/roles/{$superAdminRole}")->assertStatus(422);
        $this->as($admin)->putJson("/api/auth/admin/roles/{$superAdminRole}", [
            'name' => 'Super Admin', 'permissions' => $this->permissionMap(['sales_list']),
        ])->assertStatus(422);
        $this->assertSame(DB::table('permissions')->count(), DB::table('rol_permission')->where('role_id', $superAdminRole)->count());
    }

    public function test_roles_in_use_are_kept_and_managers_cannot_escalate(): void
    {
        $admin = $this->login('admin@cocos-francisco.com');

        $managerRole = $this->as($admin)->postJson('/api/auth/admin/roles', [
            'name' => 'Encargado', 'permissions' => $this->permissionMap(['roles_list', 'roles_create', 'roles_update', 'sales_list']),
        ])->assertCreated()->json('data.id');
        $this->as($admin)->postJson('/api/auth/admin/users', [
            'name' => 'Beto', 'email' => 'beto@cocos.test', 'password' => 'Secreta123',
            'location_roles' => [['location_id' => $this->matriz->id, 'role_id' => $managerRole]],
        ])->assertCreated();

        // Un rol con usuarios no se borra (antes quedaban sin permisos sin aviso).
        $this->as($admin)->deleteJson("/api/auth/admin/roles/{$managerRole}")
            ->assertStatus(422)->assertJsonPath('message', 'No se puede eliminar: 1 usuario tiene este rol. Asígnale otro rol primero.');

        // El encargado no puede darse más permisos editando su propio rol...
        $beto = $this->login('beto@cocos.test', 'Secreta123');
        $this->as($beto)->putJson("/api/auth/admin/roles/{$managerRole}", [
            'name' => 'Encargado', 'permissions' => $this->permissionMap(['roles_list', 'roles_create', 'roles_update', 'sales_list', 'users_delete']),
        ])->assertForbidden();
        // ...ni crear uno con permisos que no tiene; con los suyos sí.
        $this->as($beto)->postJson('/api/auth/admin/roles', [
            'name' => 'Poderoso', 'permissions' => $this->permissionMap(['companies_delete']),
        ])->assertForbidden();
        $this->as($beto)->postJson('/api/auth/admin/roles', [
            'name' => 'Ventas', 'permissions' => $this->permissionMap(['sales_list']),
        ])->assertCreated();
    }

    public function test_records_with_history_cannot_be_deleted(): void
    {
        $admin = $this->login('admin@cocos-francisco.com');

        $categoryId = $this->as($admin)->postJson('/api/auth/admin/categories', ['name' => 'Dulces'])
            ->assertCreated()->json('data.id');
        $supplierId = $this->as($admin)->postJson('/api/auth/admin/suppliers', ['name' => 'Cocos del Pacífico'])
            ->assertCreated()->json('data.id');
        $customerId = $this->as($admin)->postJson('/api/auth/admin/customers', ['name' => 'Abarrotes Lupita'])
            ->assertCreated()->json('data.id');
        $this->assertSame($this->company->id, (int) DB::table('categories')->where('id', $categoryId)->value('company_id'));

        $productId = $this->as($admin)->post('/api/auth/admin/products', [
            'name' => 'Cocada', 'code' => 'COC-001', 'category_id' => $categoryId, 'supplier_id' => $supplierId,
            'inventory_type_id' => InventoryType::idForCode(InventoryType::FINISHED_PRODUCT),
            'unit_id' => $this->unitId('Pieza'), 'current_location_id' => $this->matriz->id,
            'sale_price' => '20', 'is_active' => '1', 'for_sale' => '1',
        ])->assertCreated()->json('data.id');

        $cashierId = $this->as($admin)->postJson('/api/auth/admin/users', [
            'name' => 'Ana', 'email' => 'ana@cocos.test', 'password' => 'Secreta123',
            'location_roles' => [['location_id' => $this->matriz->id]],
        ])->assertCreated()->json('data.id');

        DB::table('sales')->insert([
            'company_id' => $this->company->id, 'location_id' => $this->matriz->id, 'user_id' => $cashierId,
            'customer_id' => $customerId, 'sale_number' => 'SAL-TEST-0001', 'sale_date' => now()->toDateString(),
            'status' => 'closed', 'subtotal' => 20, 'total' => 20, 'payment_method' => 'cash',
            'payment_status' => 'paid', 'paid_amount' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Antes: la sucursal y el usuario se llevaban sus ventas en cascada; la
        // categoría, el proveedor y el cliente dejaban productos y ventas huérfanos.
        $this->as($admin)->deleteJson("/api/auth/admin/locations/{$this->matriz->id}")->assertStatus(422);
        $this->as($admin)->deleteJson("/api/auth/admin/users/{$cashierId}")->assertStatus(422);
        $this->as($admin)->deleteJson("/api/auth/admin/categories/{$categoryId}")->assertStatus(422);
        $this->as($admin)->deleteJson("/api/auth/admin/suppliers/{$supplierId}")->assertStatus(422);
        $this->as($admin)->deleteJson("/api/auth/admin/customers/{$customerId}")->assertStatus(422);
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame($categoryId, (int) DB::table('products')->where('id', $productId)->value('category_id'));

        // Sin historial sí se pueden borrar; y nadie se borra a sí mismo.
        $emptyBranch = $this->as($admin)->postJson('/api/auth/admin/locations', [
            'name' => 'Sucursal vacía', 'address' => 'Calle 1', 'company_id' => $this->company->id,
        ])->assertCreated()->json('data.id');
        $this->as($admin)->deleteJson("/api/auth/admin/locations/{$emptyBranch}")->assertOk();
        $adminId = (int) DB::table('users')->where('email', 'admin@cocos-francisco.com')->value('id');
        $this->as($admin)->deleteJson("/api/auth/admin/users/{$adminId}")->assertStatus(422);

        // Una unidad en uso tampoco; una sin uso sí (antes fallaba siempre).
        $this->as($admin)->deleteJson('/api/auth/admin/units/' . $this->unitId('Pieza'))->assertStatus(422);
        $unitId = $this->as($admin)->postJson('/api/auth/admin/units', [
            'name' => 'Bulto', 'abbreviation' => 'bto', 'unit_type' => 'quantity',
        ])->assertCreated()->json('data.id');
        $this->as($admin)->deleteJson("/api/auth/admin/units/{$unitId}")->assertOk();
    }

    public function test_cannot_create_records_in_another_company(): void
    {
        $admin = $this->login('admin@cocos-francisco.com');
        $other = Company::create(['name' => 'Otra', 'business_name' => 'Otra SA', 'rfc' => 'OTR010101AAA', 'email' => 'o@o.test']);

        // Un encargado de esta empresa no puede crear en la otra mandando su id.
        $role = $this->as($admin)->postJson('/api/auth/admin/roles', [
            'name' => 'Encargado', 'permissions' => $this->permissionMap(['categories_create', 'categories_list']),
        ])->assertCreated()->json('data.id');
        $this->as($admin)->postJson('/api/auth/admin/users', [
            'name' => 'Beto', 'email' => 'beto@cocos.test', 'password' => 'Secreta123',
            'location_roles' => [['location_id' => $this->matriz->id, 'role_id' => $role]],
        ])->assertCreated();
        $beto = $this->login('beto@cocos.test', 'Secreta123');

        $this->as($beto)->postJson('/api/auth/admin/categories', ['name' => 'Intrusa', 'company_id' => $other->id])
            ->assertForbidden();
        $this->assertDatabaseMissing('categories', ['name' => 'Intrusa']);
    }
}
