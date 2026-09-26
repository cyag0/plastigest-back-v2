<?php

namespace Tests\Concerns;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\InventoryType;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Contexto de empresa listo para probar la API como la usa la app: empresa,
 * sucursal, usuario asignado a esa sucursal con un rol, y los headers
 * X-Company-ID / X-Location-ID.
 *
 * El usuario tiene que estar asignado a la sucursal (user_location_roles):
 * SetCurrentLocation responde 403 si no lo está.
 */
trait CreatesTenant
{
    protected Company $company;
    protected Location $location;
    protected User $user;

    /**
     * @param string[]|null $permissions null = todos los permisos (administrador).
     */
    protected function createTenant(?array $permissions = null): void
    {
        $this->seed([PermissionSeeder::class, UnitsSeeder::class]);

        $this->company = Company::factory()->create();
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->user = $this->createUserWithRole($this->location, $permissions);

        Sanctum::actingAs($this->user, ['*']);
    }

    /**
     * Usuario asignado a una sucursal con un rol que tiene los permisos dados.
     *
     * @param string[]|null $permissions null = todos los permisos.
     */
    protected function createUserWithRole(Location $location, ?array $permissions = null): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Rol de prueba ' . uniqid(),
            'company_id' => $location->company_id,
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionIds = DB::table('permissions')
            ->when($permissions !== null, fn($q) => $q->whereIn('name', $permissions))
            ->pluck('id');

        DB::table('rol_permission')->insert(
            $permissionIds->map(fn($id) => ['role_id' => $roleId, 'permission_id' => $id])->all()
        );

        $user = User::factory()->create();

        DB::table('user_location_roles')->insert([
            'user_id' => $user->id,
            'location_id' => $location->id,
            'role_id' => $roleId,
        ]);

        DB::table('user_company')->insert([
            'user_id' => $user->id,
            'company_id' => $location->company_id,
        ]);

        return $user;
    }

    protected function tenantHeaders(?Location $location = null): array
    {
        return [
            'X-Company-ID' => (string) $this->company->id,
            'X-Location-ID' => (string) ($location ?? $this->location)->id,
            'Accept' => 'application/json',
        ];
    }

    protected function unitId(string $name): int
    {
        return (int) DB::table('units')->where('name', $name)->value('id');
    }

    /**
     * Producto activo en las sucursales dadas, con el stock indicado en la
     * sucursal principal del contexto.
     */
    protected function createProduct(array $attributes = [], float $stock = 0, string $unit = 'Pieza', string $typeCode = InventoryType::RAW_MATERIAL): Product
    {
        $product = Product::create(array_merge([
            'name' => 'Producto ' . uniqid(),
            'code' => 'P-' . uniqid(),
            'company_id' => $this->company->id,
            'unit_id' => $this->unitId($unit),
            'inventory_type_id' => InventoryType::idForCode($typeCode),
            'product_type' => InventoryType::CODE_TO_LEGACY_TYPE[$typeCode] ?? 'commercial',
            'purchase_price' => 10,
            'sale_price' => 20,
            'for_sale' => true,
        ], $attributes));

        $this->setStock($product, $this->location, $stock);

        return $product;
    }

    protected function setStock(Product $product, Location $location, float $stock): void
    {
        DB::table('product_location')->updateOrInsert(
            ['product_id' => $product->id, 'location_id' => $location->id],
            ['current_stock' => $stock, 'active' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    protected function stockOf(Product $product, ?Location $location = null): float
    {
        return (float) DB::table('product_location')
            ->where('product_id', $product->id)
            ->where('location_id', ($location ?? $this->location)->id)
            ->value('current_stock');
    }
}
