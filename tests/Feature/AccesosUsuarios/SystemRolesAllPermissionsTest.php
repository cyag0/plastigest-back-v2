<?php

namespace Tests\Feature\AccesosUsuarios;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Los roles de sistema (Super Admin) tienen todos los permisos. La migración
 * de cash_movements_* no se los asignó: en una base sembrada antes de ella el
 * Super Admin se quedaba sin "Caja". La migración
 * grant_all_permissions_to_system_roles le da a cada rol de sistema los que
 * le falten.
 */
class SystemRolesAllPermissionsTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const MIGRATION = 'migrations/2026_10_16_000001_grant_all_permissions_to_system_roles.php';

    private const CASH_MOVEMENTS = [
        'cash_movements_list',
        'cash_movements_create',
        'cash_movements_update',
        'cash_movements_delete',
        'cash_movements_read',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    /** Rol con los permisos dados (por nombre). */
    private function role(string $name, array $permissions, bool $system): int
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => $name,
            'company_id' => $system ? null : $this->company->id,
            'is_system' => $system,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rol_permission')->insert(
            DB::table('permissions')->whereIn('name', $permissions)->pluck('id')
                ->map(fn($permissionId) => ['role_id' => $roleId, 'permission_id' => $permissionId])
                ->all()
        );

        return $roleId;
    }

    private function permissionsOf(int $roleId): array
    {
        return DB::table('rol_permission')
            ->join('permissions', 'permissions.id', '=', 'rol_permission.permission_id')
            ->where('rol_permission.role_id', $roleId)
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->all();
    }

    private function runMigration(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $migration->up(); // idempotente
    }

    public function test_a_system_role_seeded_before_the_cash_movements_permissions_gets_them(): void
    {
        $everyPermission = DB::table('permissions')->orderBy('name')->pluck('name')->all();
        foreach (self::CASH_MOVEMENTS as $permission) {
            $this->assertContains($permission, $everyPermission);
        }

        // Base sembrada antes de cash_movements_*: el Super Admin tiene todo lo demás.
        $superAdmin = $this->role('Super Admin', array_diff($everyPermission, self::CASH_MOVEMENTS), true);

        $this->runMigration();

        $this->assertSame($everyPermission, $this->permissionsOf($superAdmin));
        // Sin asignaciones repetidas aunque la migración corra dos veces.
        $this->assertSame(
            count($everyPermission),
            DB::table('rol_permission')->where('role_id', $superAdmin)->count()
        );
    }

    public function test_every_system_role_gets_every_missing_permission_and_company_roles_are_untouched(): void
    {
        $everyPermission = DB::table('permissions')->orderBy('name')->pluck('name')->all();

        $empty = $this->role('Soporte', [], true);
        $partial = $this->role('Super Admin', ['sales_list', 'products_list'], true);
        $cashier = $this->role('Cajero', ['sales_pos', 'cash_movements_list'], false);

        $this->runMigration();

        $this->assertSame($everyPermission, $this->permissionsOf($empty));
        $this->assertSame($everyPermission, $this->permissionsOf($partial));
        $this->assertSame(['cash_movements_list', 'sales_pos'], $this->permissionsOf($cashier));
    }
}
