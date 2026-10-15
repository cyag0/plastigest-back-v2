<?php

namespace Tests\Concerns;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\InventoryType;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Contexto de empresa listo para probar la API como la usa la app: empresa,
 * sucursal, usuario asignado a esa sucursal con un rol, y los headers
 * X-Company-ID / X-Location-ID.
 *
 * El usuario tiene que estar asignado a la sucursal (user_location_roles):
 * SetCurrentLocation responde 403 si no lo está.
 *
 * Para aislamiento entre sucursales y empresas: createBranch(),
 * createCompanyWithBranch(), createBranchUser() (usuario de una sola
 * sucursal con permisos explícitos), presetPermissions() (las plantillas del
 * editor de roles) y actingInBranch() / contextHeaders() para las peticiones.
 */
trait CreatesTenant
{
    protected Company $company;
    protected Location $location;
    protected User $user;

    private bool $tenantContextResetsAfterRequests = false;

    /**
     * @param string[]|null $permissions null = todos los permisos (administrador).
     */
    protected function createTenant(?array $permissions = null): void
    {
        // La empresa y la sucursal "actuales" son estáticas y no se borran
        // solas: sin esto una prueba arrancaba con las de la anterior.
        $this->resetTenantContext();

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

    /**
     * Headers de la sucursal dada (por defecto la del contexto). X-Company-ID
     * es la empresa de ESA sucursal, así que sirve también para la sucursal de
     * otra empresa (createCompanyWithBranch()).
     */
    protected function tenantHeaders(?Location $location = null): array
    {
        $location ??= $this->location;

        return [
            'X-Company-ID' => (string) $location->company_id,
            'X-Location-ID' => (string) $location->id,
            'Accept' => 'application/json',
        ];
    }

    // ─── Varias sucursales y empresas ────────────────────────────────────────

    /**
     * Otra sucursal, por defecto de la empresa del contexto.
     */
    protected function createBranch(array $attributes = [], ?Company $company = null): Location
    {
        return Location::factory()->create(array_merge(
            ['company_id' => ($company ?? $this->company)->id],
            $attributes
        ));
    }

    /**
     * Otra empresa con una sucursal, para probar el aislamiento entre empresas.
     *
     * @return array{0: Company, 1: Location}
     */
    protected function createCompanyWithBranch(array $companyAttributes = []): array
    {
        $company = Company::factory()->create($companyAttributes);

        return [$company, $this->createBranch([], $company)];
    }

    /**
     * Usuario asignado solo a esa sucursal, con exactamente los permisos
     * dados. A diferencia de createUserWithRole(), cada nombre tiene que
     * existir (con un typo el usuario se quedaba sin el permiso y la prueba
     * pasaba por la razón equivocada) y el usuario no puede quedar con acceso
     * a todas las sucursales (CurrentWorker::canAccessAllLocations()).
     *
     * @param string[] $permissions Nombres reales, p. ej. ['sales_pos', 'products_list'].
     */
    protected function createBranchUser(Location $location, array $permissions): User
    {
        $existing = DB::table('permissions')->whereIn('name', $permissions)->pluck('name')->all();
        $missing = array_values(array_diff($permissions, $existing));
        $this->assertSame([], $missing, 'Permisos que no existen: ' . implode(', ', $missing));

        $user = $this->createUserWithRole($location, $permissions);

        $this->assertFalse(
            CurrentWorker::canAccessAllLocations($user),
            'Un usuario de sucursal no debe tener acceso a todas las sucursales.'
        );

        return $user;
    }

    /**
     * Permisos de una plantilla del editor de roles de la app (PRESETS en
     * app/(tabs)/home/roles/form.tsx), con la misma regla, para probar con los
     * roles que el dueño arma en producción:
     * - 'all' ("Acceso total"): todos.
     * - 'operativo': create, read, update, list, view y export de los módulos
     *   que no son de administración (users, roles, permissions, companies,
     *   locations).
     * - 'lectura' ("Solo lectura"): read, list y view de esos mismos módulos.
     *
     * @return string[]
     */
    protected function presetPermissions(string $preset): array
    {
        $adminResources = ['users', 'roles', 'permissions', 'companies', 'locations'];
        $actions = match ($preset) {
            'all' => null,
            'operativo' => ['create', 'read', 'update', 'list', 'view', 'export'],
            'lectura' => ['read', 'list', 'view'],
        };

        return DB::table('permissions')
            ->orderBy('name')
            ->get(['name', 'resource'])
            ->filter(function ($permission) use ($adminResources, $actions) {
                if ($actions === null) {
                    return true;
                }

                $resource = (string) ($permission->resource ?? '');
                if (in_array($resource, $adminResources, true)) {
                    return false;
                }

                // Como getAction() en la app: lo que sigue al prefijo del
                // recurso o, si no lo tiene, lo que sigue al último "_".
                $name = $permission->name;
                if ($resource !== '' && str_starts_with($name, $resource . '_')) {
                    $action = substr($name, strlen($resource) + 1);
                } else {
                    $underscore = strrpos($name, '_');
                    $action = $underscore === false ? $name : substr($name, $underscore + 1);
                }

                return in_array($action, $actions, true);
            })
            ->pluck('name')
            ->values()
            ->all();
    }

    // ─── Peticiones con contexto ─────────────────────────────────────────────

    /**
     * Headers X-Company-ID / X-Location-ID explícitos (modelos o ids). null
     * omite ese header: pruebas de "sin empresa" o "sin sucursal".
     */
    protected function contextHeaders(Company|int|null $company, Location|int|null $location): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($company !== null) {
            $headers['X-Company-ID'] = (string) ($company instanceof Company ? $company->id : $company);
        }
        if ($location !== null) {
            $headers['X-Location-ID'] = (string) ($location instanceof Location ? $location->id : $location);
        }

        return $headers;
    }

    /**
     * Las siguientes peticiones salen como $user desde la sucursal dada (por
     * defecto la del contexto), con la empresa de esa sucursal:
     *
     *   $this->actingInBranch($cajero, $otra)->getJson($url)->assertNotFound();
     *   $this->actingInBranch($cajero)->withoutHeader('X-Company-ID')->getJson($url);
     *
     * Reemplaza los headers puestos antes con withHeaders(). Además deja sin
     * empresa ni sucursal "actuales" (estáticas) antes y después de cada
     * petición, como en producción: si no, una petición sin header heredaba
     * las de la petición anterior de la misma prueba.
     */
    protected function actingInBranch(User $user, ?Location $location = null): static
    {
        $location ??= $this->location;

        Sanctum::actingAs($user, ['*']);
        $this->resetTenantContext();

        if (!$this->tenantContextResetsAfterRequests) {
            $this->tenantContextResetsAfterRequests = true;
            $this->app['events']->listen(RequestHandled::class, fn() => $this->resetTenantContext());
        }

        return $this->flushHeaders()->withHeaders($this->tenantHeaders($location));
    }

    /**
     * Borra la empresa y la sucursal "actuales" (CurrentCompany /
     * CurrentLocation). Son estáticas: entre peticiones de una misma prueba,
     * o entre pruebas, no se borran solas.
     */
    protected function resetTenantContext(): void
    {
        CurrentCompany::clear();
        CurrentLocation::clear();
    }

    /**
     * Laravel lo llama al terminar cada prueba que usa este trait: la
     * siguiente no hereda la empresa ni la sucursal de esta.
     */
    protected function tearDownCreatesTenant(): void
    {
        $this->resetTenantContext();
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
