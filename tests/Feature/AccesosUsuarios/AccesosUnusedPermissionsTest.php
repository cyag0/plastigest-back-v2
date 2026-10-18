<?php

namespace Tests\Feature\AccesosUsuarios;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * users_manage, companies_manage y settings_manage no los revisaba ningún
 * endpoint ni pantalla: en el editor de roles eran casillas que no hacían
 * nada. Ya no se crean, y la migración los borra con sus asignaciones.
 */
class AccesosUnusedPermissionsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const MIGRATION = 'migrations/2026_10_17_200000_remove_unused_manage_permissions.php';

    private const REMOVED = ['users_manage', 'companies_manage', 'settings_manage'];

    /** Los «gestionar» que sí hacen algo. */
    private const KEPT = ['inventory_manage', 'purchases_manage', 'notifications_manage'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function permissionNames(): array
    {
        return DB::table('permissions')->pluck('name')->all();
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    public function test_the_seeder_no_longer_creates_them(): void
    {
        $names = $this->permissionNames();

        foreach (self::REMOVED as $permission) {
            $this->assertNotContains($permission, $names);
        }
        foreach (self::KEPT as $permission) {
            $this->assertContains($permission, $names);
        }
    }

    public function test_the_role_editor_no_longer_offers_them(): void
    {
        $catalog = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/permissions/by-resource')
            ->assertOk()
            ->json('permissions_by_resource');

        $names = collect($catalog)->flatten(1)->pluck('name');
        $this->assertNotEmpty($names);
        foreach (self::REMOVED as $permission) {
            $this->assertNotContains($permission, $names);
        }
        // Usuarios, empresas y configuración siguen con sus demás permisos.
        foreach (['users_update', 'companies_update', 'settings_update', 'system_admin'] as $permission) {
            $this->assertContains($permission, $names);
        }
    }

    public function test_the_migration_removes_them_with_their_role_assignments(): void
    {
        // Base en uso: los permisos existen y hay roles que los tienen.
        $ids = collect(self::REMOVED)->mapWithKeys(fn($name) => [$name => DB::table('permissions')->insertGetId([
            'name' => $name, 'description' => 'Gestionar', 'resource' => explode('_', $name)[0],
            'created_at' => now(), 'updated_at' => now(),
        ])]);
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Gerente', 'company_id' => $this->company->id, 'is_system' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $kept = DB::table('permissions')->whereIn('name', self::KEPT)->pluck('id');
        DB::table('rol_permission')->insert($ids->values()->merge($kept)
            ->map(fn($id) => ['role_id' => $roleId, 'permission_id' => $id])->all());

        $migration = $this->migration();
        $migration->up();
        $migration->up(); // se puede correr dos veces

        foreach (self::REMOVED as $permission) {
            $this->assertNotContains($permission, $this->permissionNames());
        }
        $this->assertSame(0, DB::table('rol_permission')->whereIn('permission_id', $ids->values())->count());
        // Lo demás del rol se queda.
        $this->assertEqualsCanonicalizing(
            $kept->map(fn($id) => (int) $id)->all(),
            DB::table('rol_permission')->where('role_id', $roleId)->pluck('permission_id')->map(fn($id) => (int) $id)->all()
        );
    }

    public function test_rolling_back_brings_them_back_for_the_system_roles(): void
    {
        $superAdmin = DB::table('roles')->insertGetId([
            'name' => 'Super Admin', 'company_id' => null, 'is_system' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = $this->migration();
        $migration->down();
        $migration->down(); // sin repetir

        $ids = DB::table('permissions')->whereIn('name', self::REMOVED)->pluck('id');
        $this->assertCount(3, $ids);
        $this->assertSame(3, DB::table('rol_permission')->where('role_id', $superAdmin)
            ->whereIn('permission_id', $ids)->count());

        $migration->up();
        $this->assertSame(0, DB::table('permissions')->whereIn('name', self::REMOVED)->count());
    }
}
