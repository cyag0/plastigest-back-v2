<?php

namespace Tests\Feature\Revision;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Con roles_update se podía vaciar el rol de un superior (sync() reemplaza
 * todos sus permisos) y, ya degradado, UserController dejaba cambiarle la
 * contraseña y entrar con su cuenta. Ahora nadie quita permisos que no tiene
 * ni edita un rol que tiene alguien con permisos que él no tiene.
 */
class RevisionRolesTakeoverTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Supervisor de sucursal que puede editar usuarios y roles. */
    private const MANAGER = ['users_list', 'users_read', 'users_update', 'roles_list', 'roles_read', 'roles_update'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    /** @param string[]|null $permissions null = todos */
    private function role(string $name, ?array $permissions, bool $system = false): int
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => $name, 'company_id' => $system ? null : $this->company->id, 'is_system' => $system,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rol_permission')->insert(
            DB::table('permissions')->when($permissions !== null, fn($q) => $q->whereIn('name', $permissions))->pluck('id')
                ->map(fn($id) => ['role_id' => $roleId, 'permission_id' => $id])->all()
        );

        return $roleId;
    }

    private function holder(int $roleId): User
    {
        $user = User::factory()->create(['password' => Hash::make('Clave-original-1')]);
        DB::table('user_location_roles')->insert(['user_id' => $user->id, 'location_id' => $this->location->id, 'role_id' => $roleId]);
        DB::table('user_company')->insert(['user_id' => $user->id, 'company_id' => $this->company->id]);

        return $user;
    }

    /** permissions como los manda el editor de roles: {id: true}. */
    private function payload(string $name, array $permissionNames): array
    {
        $ids = DB::table('permissions')->whereIn('name', $permissionNames)->pluck('id');

        return ['name' => $name, 'permissions' => $ids->mapWithKeys(fn($id) => [$id => true])->all()];
    }

    private function permissionNames(int $roleId): array
    {
        return DB::table('rol_permission')->join('permissions', 'permissions.id', '=', 'rol_permission.permission_id')
            ->where('role_id', $roleId)->orderBy('permissions.name')->pluck('permissions.name')->all();
    }

    public function test_a_manager_cannot_demote_a_superior_role_and_then_take_over_the_account(): void
    {
        $manager = $this->createBranchUser($this->location, self::MANAGER);
        $allButSystem = array_values(array_diff($this->presetPermissions('all'), ['system_admin']));
        $gerenteRole = $this->role('Gerente general', $allButSystem);
        $gerente = $this->holder($gerenteRole);
        $before = $this->permissionNames($gerenteRole);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$gerente->id}", ['password' => 'Takeover123!'])
            ->assertForbidden();

        // Mismos permisos que el supervisor: lo demás se quitaba en silencio.
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/roles/{$gerenteRole}", $this->payload('Gerente general', self::MANAGER))
            ->assertForbidden();
        $this->assertSame($before, $this->permissionNames($gerenteRole), 'el rol del gerente perdió permisos');

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$gerente->id}", ['password' => 'Takeover123!'])
            ->assertForbidden();
        $this->assertTrue(Hash::check('Clave-original-1', $gerente->fresh()->password), 'le cambiaron la contraseña al gerente');
    }

    public function test_a_role_held_by_a_superior_cannot_be_edited_even_without_removing_permissions(): void
    {
        $manager = $this->createBranchUser($this->location, [...self::MANAGER, 'sales_pos']);
        // El rol solo tiene sales_pos, pero quien lo tiene suma más permisos
        // por su rol en otra sucursal: editarlo le cambia el acceso a él.
        $cashierRole = $this->role('Cajero', ['sales_pos']);
        $superior = $this->holder($cashierRole);
        $otherBranch = $this->createBranch();
        $allButSystem = array_values(array_diff($this->presetPermissions('all'), ['system_admin']));
        DB::table('user_location_roles')->insert([
            'user_id' => $superior->id, 'location_id' => $otherBranch->id, 'role_id' => $this->role('Gerente general', $allButSystem),
        ]);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/roles/{$cashierRole}", $this->payload('Cajero', []))
            ->assertForbidden()
            ->assertJsonPath('message', 'No puedes editar un rol que tiene un usuario con permisos que tú no tienes.');
        $this->assertSame(['sales_pos'], $this->permissionNames($cashierRole));
    }

    public function test_a_role_held_by_the_system_admin_cannot_be_edited_by_a_manager(): void
    {
        $allButSystem = array_values(array_diff($this->presetPermissions('all'), ['system_admin']));
        $manager = $this->createUserWithRole($this->location, $allButSystem);
        $customRole = $this->role('Encargado', ['sales_pos']);
        $owner = $this->holder($customRole);
        DB::table('users_roles')->insert(['user_id' => $owner->id, 'role_id' => $this->role('Super Admin', null, true)]);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/roles/{$customRole}", $this->payload('Encargado', ['sales_pos', 'sales_list']))
            ->assertForbidden();
        $this->assertSame(['sales_pos'], $this->permissionNames($customRole));
    }

    public function test_permissions_the_actor_lacks_cannot_be_removed_from_an_unassigned_role(): void
    {
        $manager = $this->createBranchUser($this->location, [...self::MANAGER, 'sales_pos']);
        $role = $this->role('Auditor', ['sales_pos', 'companies_delete']);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/roles/{$role}", $this->payload('Auditor', ['sales_pos']))
            ->assertForbidden()
            ->assertJsonPath('message', 'No puedes quitar permisos que tú no tienes.');
        $this->assertSame(['companies_delete', 'sales_pos'], $this->permissionNames($role));

        // Vaciarlo también es quitar.
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/roles/{$role}", $this->payload('Auditor', []))
            ->assertForbidden();
        $this->assertSame(['companies_delete', 'sales_pos'], $this->permissionNames($role));
    }

    public function test_a_manager_still_edits_the_roles_of_the_staff_they_supervise(): void
    {
        $manager = $this->createBranchUser($this->location, [...self::MANAGER, 'sales_pos', 'sales_list']);
        $cashierRole = $this->role('Cajero', ['sales_pos']);
        $this->holder($cashierRole);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/roles/{$cashierRole}", $this->payload('Cajero', ['sales_pos', 'sales_list']))
            ->assertOk();
        $this->assertSame(['sales_list', 'sales_pos'], $this->permissionNames($cashierRole));

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/roles/{$cashierRole}", $this->payload('Cajero de mostrador', ['sales_pos']))
            ->assertOk();
        $this->assertSame(['sales_pos'], $this->permissionNames($cashierRole));
    }

    public function test_an_edit_without_permissions_keeps_the_role_permissions(): void
    {
        $role = $this->role('Cajero', ['sales_pos', 'sales_list']);

        // Administrador de la empresa (todos los permisos).
        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/roles/{$role}", ['name' => 'Cajero matutino'])
            ->assertOk();

        $this->assertSame('Cajero matutino', DB::table('roles')->where('id', $role)->value('name'));
        $this->assertSame(['sales_list', 'sales_pos'], $this->permissionNames($role));
    }

    public function test_the_system_admin_can_edit_any_role_and_the_system_role_stays_immutable(): void
    {
        $superAdminRole = $this->role('Super Admin', null, true);
        $owner = User::factory()->create();
        DB::table('users_roles')->insert(['user_id' => $owner->id, 'role_id' => $superAdminRole]);
        DB::table('user_company')->insert(['user_id' => $owner->id, 'company_id' => $this->company->id]);
        DB::table('user_location_roles')->insert(['user_id' => $owner->id, 'location_id' => $this->location->id, 'role_id' => $superAdminRole]);

        $allButSystem = array_values(array_diff($this->presetPermissions('all'), ['system_admin']));
        $gerenteRole = $this->role('Gerente general', $allButSystem);
        $this->holder($gerenteRole);

        $this->actingInBranch($owner)
            ->putJson("/api/auth/admin/roles/{$gerenteRole}", $this->payload('Gerente general', ['sales_pos']))
            ->assertOk();
        $this->assertSame(['sales_pos'], $this->permissionNames($gerenteRole));

        $this->actingInBranch($owner)
            ->putJson("/api/auth/admin/roles/{$superAdminRole}", $this->payload('Super Admin', ['sales_pos']))
            ->assertStatus(422);

        $manager = $this->createUserWithRole($this->location, $allButSystem);
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/roles/{$superAdminRole}", $this->payload('Super Admin', ['sales_pos']))
            ->assertNotFound();
        $this->assertGreaterThan(1, count($this->permissionNames($superAdminRole)));
    }
}
