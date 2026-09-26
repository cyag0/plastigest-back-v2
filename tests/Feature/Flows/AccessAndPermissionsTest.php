<?php

namespace Tests\Feature\Flows;

use App\Models\Admin\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Acceso por sucursal (punto 16) e integridad de permisos (punto 12).
 */
class AccessAndPermissionsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Permisos de un usuario de sucursal: opera, pero no administra sucursales. */
    private const BASIC = ['inventory_list', 'inventory_read', 'inventory_manage', 'products_list', 'products_read', 'sales_list', 'sales_create'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    public function test_basic_user_cannot_use_a_branch_they_are_not_assigned_to(): void
    {
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($this->createUserWithRole($this->location, self::BASIC), ['*']);

        $this->withHeaders($this->tenantHeaders($other))
            ->getJson('/api/auth/admin/inventory-adjustments')
            ->assertForbidden()
            ->assertJsonPath('code', 'location_forbidden');

        // La sesión no depende de la sucursal: /auth/me sigue respondiendo para
        // que la app pida otra sucursal en vez de cerrar la sesión.
        $this->withHeaders($this->tenantHeaders($other))
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/inventory-adjustments')
            ->assertOk();
    }

    public function test_basic_user_only_sees_counts_of_their_branch(): void
    {
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        foreach ([$this->location, $other] as $loc) {
            DB::table('inventory_counts')->insert([
                'company_id' => $this->company->id, 'name' => 'Conteo ' . $loc->id, 'count_date' => now()->toDateString(),
                'location_id' => $loc->id, 'status' => 'planning', 'user_id' => $this->user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($this->createUserWithRole($this->location, self::BASIC), ['*']);
        $basic = $this->withHeaders($this->tenantHeaders())->getJson('/api/auth/admin/inventory-counts?per_page=50')->assertOk();
        $this->assertSame(['Conteo ' . $this->location->id], collect($basic->json('data'))->pluck('name')->all());

        // El administrador (locations_list) ve las de todas las sucursales.
        Sanctum::actingAs($this->user, ['*']);
        $admin = $this->withHeaders($this->tenantHeaders())->getJson('/api/auth/admin/inventory-counts?per_page=50')->assertOk();
        $this->assertCount(2, $admin->json('data'));
    }

    public function test_admin_has_their_permissions_in_branches_they_are_not_assigned_to(): void
    {
        // Puede entrar a cualquier sucursal; antes ahí todo le respondía 403.
        $other = Location::factory()->create(['company_id' => $this->company->id]);

        $this->withHeaders($this->tenantHeaders($other))
            ->getJson('/api/auth/admin/inventory-adjustments')
            ->assertOk();
    }

    /**
     * Cada permiso que el código revisa debe existir después de sembrar.
     * Así se detectaron reports_* e inventory_types_*, que nadie tenía.
     */
    public function test_every_permission_checked_in_code_exists(): void
    {
        $existing = DB::table('permissions')->pluck('name')->all();

        // Recursos que el seeder deja fuera a propósito.
        $intentionallyMissing = [
            'workers_list', 'workers_read', 'workers_create', 'workers_update', 'workers_delete', // módulo retirado
            'permissions_create', 'permissions_update', 'permissions_delete', // catálogo de solo lectura
            'movements_update', 'movements_delete', // los movimientos no se editan
        ];

        $used = [];
        foreach ((new Finder())->files()->in(app_path())->name('*.php') as $file) {
            $code = $file->getContents();

            preg_match_all("/hasPermission\\('([a-z_]+)'\\)/", $code, $m);
            $used = array_merge($used, $m[1]);

            if (preg_match("/permissionPrefix = '([a-z_]+)'/", $code, $p)) {
                foreach (['list', 'read', 'create', 'update', 'delete'] as $action) {
                    $used[] = "{$p[1]}_{$action}";
                }
            }
        }

        // Si la búsqueda dejara de encontrar permisos, la prueba pasaría sin revisar nada.
        $this->assertGreaterThan(50, count(array_unique($used)));

        $missing = array_values(array_diff(array_unique($used), $existing, $intentionallyMissing));
        sort($missing);

        $this->assertSame([], $missing, 'Permisos usados en el código que no existen: ' . implode(', ', $missing));
    }
}
