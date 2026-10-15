<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Support\CurrentCompany;
use App\Support\CurrentWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * "Acceso a todas las sucursales" es un permiso propio (locations_all). Antes
 * lo daba locations_list, que el editor de roles muestra como "Ubicaciones ›
 * Listar": cualquier rol con ese permiso operaba todas las sucursales.
 */
class AccesosAllLocationsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function locationIdsListedFor($user): array
    {
        return collect($this->actingInBranch($user)->getJson('/api/auth/admin/locations?per_page=50')
            ->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_listing_locations_no_longer_opens_every_branch(): void
    {
        $other = $this->createBranch();
        // createBranchUser() ya comprueba que no ve todas las sucursales.
        $lister = $this->createBranchUser($this->location, ['locations_list', 'categories_list']);

        $this->actingInBranch($lister, $other)->getJson('/api/auth/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'location_forbidden');

        // El selector solo ofrece las sucursales a las que puede entrar.
        $this->assertSame([$this->location->id], $this->locationIdsListedFor($lister));
    }

    public function test_the_all_locations_permission_opens_every_branch_of_the_company(): void
    {
        $other = $this->createBranch();
        [$foreignCompany, $foreignBranch] = $this->createCompanyWithBranch();
        $manager = $this->createUserWithRole($this->location, ['locations_all', 'categories_list']);

        $this->assertTrue(CurrentWorker::canAccessAllLocations($manager));

        // En la otra sucursal tiene los permisos de su rol.
        $this->actingInBranch($manager, $other)->getJson('/api/auth/my-permissions')
            ->assertOk()
            ->assertJsonPath('location_id', $other->id);
        $this->actingInBranch($manager, $other)->getJson('/api/auth/admin/categories')->assertOk();

        $this->assertSame(
            collect([$this->location->id, $other->id])->sort()->values()->all(),
            $this->locationIdsListedFor($manager)
        );

        // Pero solo las de su empresa.
        $this->actingInBranch($manager, $foreignBranch)->getJson('/api/auth/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'company_forbidden');
    }

    public function test_a_role_of_another_company_does_not_open_this_company(): void
    {
        [$foreignCompany, $foreignBranch] = $this->createCompanyWithBranch();
        // Encargado de todas las sucursales en la otra empresa, cajero aquí.
        $user = $this->createUserWithRole($foreignBranch, ['locations_all']);
        DB::table('user_company')->insert(['user_id' => $user->id, 'company_id' => $this->company->id]);
        DB::table('user_location_roles')->insert([
            'user_id' => $user->id,
            'location_id' => $this->location->id,
            'role_id' => DB::table('roles')->insertGetId([
                'name' => 'Cajero', 'company_id' => $this->company->id, 'is_system' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]),
        ]);
        $other = $this->createBranch();

        $this->actingInBranch($user, $other)->getJson('/api/auth/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'location_forbidden');

        CurrentCompany::set($this->company);
        $this->assertFalse(CurrentWorker::canAccessAllLocations($user));
    }

    public function test_a_system_admin_reaches_every_branch(): void
    {
        $other = $this->createBranch();
        $admin = $this->createUserWithRole($this->location, ['system_admin', 'categories_list']);

        $this->assertTrue(CurrentWorker::canAccessAllLocations($admin));
        $this->actingInBranch($admin, $other)->getJson('/api/auth/admin/categories')->assertOk();
    }

    public function test_the_seeder_creates_the_permission_with_a_clear_label(): void
    {
        $permission = DB::table('permissions')->where('name', 'locations_all')->first();

        $this->assertNotNull($permission);
        $this->assertSame('Acceso a todas las sucursales', $permission->description);
        $this->assertSame('locations', $permission->resource);
    }

    public function test_only_the_full_access_template_includes_it(): void
    {
        $this->assertNotContains('locations_all', $this->presetPermissions('operativo'));
        $this->assertNotContains('locations_all', $this->presetPermissions('lectura'));
        $this->assertContains('locations_all', $this->presetPermissions('all'));
    }

    public function test_the_migration_keeps_access_for_roles_that_listed_locations(): void
    {
        // Base en uso: el permiso no existe todavía.
        $existingId = DB::table('permissions')->where('name', 'locations_all')->value('id');
        DB::table('rol_permission')->where('permission_id', $existingId)->delete();
        DB::table('permissions')->where('id', $existingId)->delete();

        $role = fn(string $name, array $permissions, bool $system = false) => tap(DB::table('roles')->insertGetId([
            'name' => $name, 'company_id' => $system ? null : $this->company->id, 'is_system' => $system,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]), fn($id) => DB::table('rol_permission')->insert(
            DB::table('permissions')->whereIn('name', $permissions)->pluck('id')
                ->map(fn($permissionId) => ['role_id' => $id, 'permission_id' => $permissionId])->all()
        ));

        $manager = $role('Encargado', ['locations_list', 'sales_list']);
        $cashier = $role('Cajero', ['sales_pos']);
        $superAdmin = $role('Super Admin', ['sales_list'], true);

        $migration = require database_path('migrations/2026_10_15_230100_add_locations_all_permission.php');
        $migration->up();
        $migration->up(); // idempotente

        $permissionId = DB::table('permissions')->where('name', 'locations_all')->value('id');
        $this->assertNotNull($permissionId);
        $holders = DB::table('rol_permission')->where('permission_id', $permissionId)->pluck('role_id');

        $this->assertTrue($holders->contains($manager));
        $this->assertTrue($holders->contains($superAdmin));
        $this->assertFalse($holders->contains($cashier));
        $this->assertSame($holders->count(), $holders->unique()->count());
    }

    // ─── Configuración de sucursal ───────────────────────────────────────────

    public function test_branch_settings_are_only_for_the_current_branch(): void
    {
        $other = $this->createBranch();
        $manager = $this->createBranchUser($this->location, ['settings_read', 'settings_update']);

        $this->actingInBranch($manager)->getJson("/api/auth/admin/locations/{$other->id}/settings")->assertNotFound();
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/locations/{$other->id}/settings", ['limits' => ['low_stock_threshold' => 1]])
            ->assertNotFound();
        $this->actingInBranch($manager)->postJson("/api/auth/admin/locations/{$other->id}/settings/reset")->assertNotFound();
        $this->assertNull(DB::table('locations')->where('id', $other->id)->value('settings'));

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/locations/{$this->location->id}/settings", ['limits' => ['low_stock_threshold' => 3]])
            ->assertOk()
            ->assertJsonPath('data.limits.low_stock_threshold', 3);
    }

    public function test_an_all_branches_manager_can_change_another_branch_settings(): void
    {
        $other = $this->createBranch();
        [, $foreignBranch] = $this->createCompanyWithBranch();
        $manager = $this->createUserWithRole($this->location, ['locations_all', 'settings_update']);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/locations/{$other->id}/settings", ['limits' => ['low_stock_threshold' => 5]])
            ->assertOk();

        // Nunca la de otra empresa.
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/locations/{$foreignBranch->id}/settings", ['limits' => ['low_stock_threshold' => 5]])
            ->assertNotFound();
    }
}
