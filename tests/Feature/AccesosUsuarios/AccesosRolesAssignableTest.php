<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Models\Admin\Role;
use App\Support\CurrentWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * La lista de roles dice, rol por rol, si el usuario actual puede asignarlo
 * (assignable): la app bloquea en el selector lo que UserController
 * rechazaría con 403 al guardar. Es la misma regla
 * (CurrentWorker::canAssignRole / roleAssignmentDenial).
 */
class AccesosRolesAssignableTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Encargado de sucursal que administra usuarios y ve los roles. */
    private const BRANCH_MANAGER = [
        'users_list', 'users_read', 'users_update', 'roles_list', 'roles_read', 'sales_pos', 'inventory_manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant(); // $this->user: todos los permisos, incluido system_admin
    }

    /** @param string[]|null $permissions null = todos */
    private function role(string $name, ?array $permissions, bool $system = false, ?int $companyId = -1): int
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => $name, 'company_id' => $companyId === -1 ? $this->company->id : $companyId,
            'is_system' => $system, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rol_permission')->insert(
            DB::table('permissions')->when($permissions !== null, fn($q) => $q->whereIn('name', $permissions))->pluck('id')
                ->map(fn($id) => ['role_id' => $roleId, 'permission_id' => $id])->all()
        );

        return $roleId;
    }

    /** @return array<int, bool> assignable de cada rol de la lista, por id */
    private function assignableByRole(): array
    {
        return collect($this->getJson('/api/auth/admin/roles?per_page=100')->assertOk()->json('data'))
            ->mapWithKeys(fn($role) => [$role['id'] => $role['assignable']])
            ->all();
    }

    public function test_the_system_admin_can_assign_every_role(): void
    {
        $everything = $this->role('Gerente general', null);
        $cashier = $this->role('Cajero', ['sales_pos']);
        $companySystem = $this->role('Administrador', null, true);
        $globalSystem = $this->role('Super Admin', null, true, null);

        $assignable = $this->actingInBranch($this->user)->assignableByRole();

        $this->assertTrue($assignable[$everything]);
        $this->assertTrue($assignable[$cashier]);
        $this->assertTrue($assignable[$companySystem]);

        $this->actingInBranch($this->user)->getJson("/api/auth/admin/roles/{$globalSystem}")
            ->assertOk()
            ->assertJsonPath('data.assignable', true);
    }

    public function test_a_branch_manager_can_only_assign_roles_within_their_permissions(): void
    {
        $manager = $this->createBranchUser($this->location, self::BRANCH_MANAGER);
        $cashier = $this->role('Cajero', ['sales_pos']);
        $accountant = $this->role('Contador', ['sales_pos', 'reports_export']);
        $companySystem = $this->role('Administrador', null, true);

        $assignable = $this->actingInBranch($manager)->assignableByRole();

        $this->assertTrue($assignable[$cashier], 'un rol con parte de sus permisos');
        $this->assertFalse($assignable[$accountant], 'un rol con un permiso que no tiene');
        $this->assertFalse($assignable[$companySystem], 'un rol del sistema');

        $this->actingInBranch($manager)->getJson("/api/auth/admin/roles/{$cashier}")
            ->assertOk()->assertJsonPath('data.assignable', true);
        $this->actingInBranch($manager)->getJson("/api/auth/admin/roles/{$accountant}")
            ->assertOk()->assertJsonPath('data.assignable', false);

        // Lo que la lista marca coincide con lo que acepta UserController.
        $user = $this->createBranchUser($this->location, ['sales_pos']);
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$user->id}", ['location_role_id' => $accountant])
            ->assertForbidden();
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$user->id}", ['location_role_id' => $companySystem])
            ->assertForbidden();
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$user->id}", ['location_role_id' => $cashier])
            ->assertOk();
    }

    public function test_system_roles_and_roles_of_other_companies_are_never_assignable_by_others(): void
    {
        $manager = $this->createBranchUser($this->location, self::BRANCH_MANAGER);
        [$otherCompany] = $this->createCompanyWithBranch();
        $foreign = Role::findOrFail($this->role('Cajero ajeno', ['sales_pos'], false, $otherCompany->id));
        $globalSystem = Role::findOrFail($this->role('Super Admin', null, true, null));
        $emptySystem = Role::findOrFail($this->role('Sistema vacío', [], true));

        $this->assertSame('company', CurrentWorker::roleAssignmentDenial($foreign, $manager));
        $this->assertFalse(CurrentWorker::canAssignRole($foreign, $manager));
        $this->assertFalse(CurrentWorker::canAssignRole($globalSystem, $manager));
        $this->assertSame('system', CurrentWorker::roleAssignmentDenial($emptySystem, $manager));
        $this->assertFalse(CurrentWorker::canAssignRole($emptySystem, $manager));

        $this->assertTrue(CurrentWorker::canAssignRole($foreign, $this->user));
        $this->assertTrue(CurrentWorker::canAssignRole($globalSystem, $this->user));
    }
}
