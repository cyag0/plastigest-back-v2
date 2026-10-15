<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Models\Admin\Location;
use App\Models\User;
use App\Support\CurrentWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Con users_update se le podía cambiar la contraseña, el correo o el estado
 * al dueño (administrador del sistema) y entrar con su cuenta, quitarle sus
 * roles o borrarlo. Ahora nadie modifica a quien tiene más permisos que él.
 */
class AccesosUserTakeoverTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Encargado que solo opera su sucursal (como en BranchAdministrationTest). */
    private const BRANCH_MANAGER = [
        'users_list', 'users_read', 'users_create', 'users_update', 'users_delete',
        'sales_pos', 'inventory_manage',
    ];

    private Location $otherBranch;
    private User $owner;
    private int $superAdminRoleId;
    private int $cashierRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        $this->otherBranch = $this->createBranch();

        // Como en producción (UserSeeder): rol de sistema con todos los
        // permisos, directo y en cada sucursal.
        $this->superAdminRoleId = $this->role('Super Admin', null, null, true);
        $this->owner = User::factory()->create(['password' => Hash::make('Clave-del-dueno-1')]);
        DB::table('users_roles')->insert(['user_id' => $this->owner->id, 'role_id' => $this->superAdminRoleId]);
        DB::table('user_company')->insert(['user_id' => $this->owner->id, 'company_id' => $this->company->id]);
        foreach ([$this->location, $this->otherBranch] as $branch) {
            DB::table('user_location_roles')->insert([
                'user_id' => $this->owner->id, 'location_id' => $branch->id, 'role_id' => $this->superAdminRoleId,
            ]);
        }

        $this->cashierRoleId = $this->role('Cajero', ['sales_pos']);
    }

    /** @param string[]|null $permissions null = todos */
    private function role(string $name, ?array $permissions, ?int $companyId = null, bool $system = false): int
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => $name, 'company_id' => $system ? null : ($companyId ?? $this->company->id), 'is_system' => $system,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rol_permission')->insert(
            DB::table('permissions')->when($permissions !== null, fn($q) => $q->whereIn('name', $permissions))->pluck('id')
                ->map(fn($id) => ['role_id' => $roleId, 'permission_id' => $id])->all()
        );

        return $roleId;
    }

    private function branchManager(): User
    {
        return $this->createBranchUser($this->location, self::BRANCH_MANAGER);
    }

    private function allBranchesManager(): User
    {
        return $this->createUserWithRole($this->location, [...self::BRANCH_MANAGER, 'locations_list', 'locations_all']);
    }

    private function ownerIsIntact(): void
    {
        $owner = $this->owner->fresh();
        $this->assertTrue(Hash::check('Clave-del-dueno-1', $owner->password), 'la contraseña del dueño cambió');
        $this->assertTrue((bool) ($owner->is_active ?? true), 'el dueño quedó desactivado');
        $this->assertSame($this->owner->email, $owner->email);
        $this->assertTrue(DB::table('users_roles')->where('user_id', $owner->id)->where('role_id', $this->superAdminRoleId)->exists());
        $this->assertTrue(CurrentWorker::isSystemAdmin($owner));
    }

    // ─── El administrador del sistema ────────────────────────────────────────

    public function test_a_manager_cannot_reset_the_owner_password(): void
    {
        $this->actingInBranch($this->branchManager())
            ->putJson("/api/auth/admin/users/{$this->owner->id}", ['password' => 'Tomada-123456'])
            ->assertForbidden();

        $this->ownerIsIntact();
    }

    public function test_a_manager_cannot_deactivate_the_owner(): void
    {
        $this->owner->createToken('telefono-del-dueno');

        $this->actingInBranch($this->branchManager())
            ->putJson("/api/auth/admin/users/{$this->owner->id}", ['is_active' => 0])
            ->assertForbidden();

        $this->ownerIsIntact();
        $this->assertSame(1, $this->owner->tokens()->count(), 'al dueño le cerraron la sesión');
    }

    public function test_a_manager_cannot_strip_the_owner_roles_or_change_anything(): void
    {
        $manager = $this->allBranchesManager();

        foreach ([
            ['role_ids' => []],
            ['role_ids' => [], 'location_roles' => [['location_id' => $this->location->id, 'role_id' => $this->cashierRoleId]]],
            ['location_role_id' => $this->cashierRoleId],
            ['email' => 'otro-correo@example.test'],
            ['name' => 'Ya no es el dueño'],
        ] as $payload) {
            $this->actingInBranch($manager)
                ->putJson("/api/auth/admin/users/{$this->owner->id}", $payload)
                ->assertForbidden();
        }

        $this->ownerIsIntact();
        $this->assertSame(2, DB::table('user_location_roles')->where('user_id', $this->owner->id)
            ->where('role_id', $this->superAdminRoleId)->count());
    }

    public function test_a_manager_cannot_delete_the_owner_or_remove_them_from_the_branch(): void
    {
        $manager = $this->allBranchesManager();

        $this->actingInBranch($manager)->deleteJson("/api/auth/admin/users/{$this->owner->id}")->assertForbidden();
        $this->actingInBranch($manager)->deleteJson("/api/auth/admin/users/{$this->owner->id}/current-location")->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $this->owner->id]);
        $this->assertTrue(DB::table('user_location_roles')->where('user_id', $this->owner->id)
            ->where('location_id', $this->location->id)->exists());
    }

    public function test_only_another_system_admin_sees_the_owner_in_user_lists(): void
    {
        $manager = $this->branchManager();

        foreach (['/api/auth/admin/users?scope=location', '/api/auth/admin/users?by_company=1'] as $url) {
            $ids = collect($this->actingInBranch($manager)->getJson($url)->assertOk()->json('data'))->pluck('id');
            $this->assertNotContains($this->owner->id, $ids, $url);
            $this->assertContains($manager->id, $ids, $url);
        }

        // $this->user tiene todos los permisos, system_admin incluido.
        $ids = collect($this->actingInBranch($this->user)->getJson('/api/auth/admin/users?scope=location')
            ->assertOk()->json('data'))->pluck('id');
        $this->assertContains($this->owner->id, $ids);
    }

    public function test_the_api_tells_the_app_who_is_a_system_admin(): void
    {
        $manager = $this->branchManager();

        $rows = collect($this->actingInBranch($this->user)->getJson('/api/auth/admin/users?scope=location')
            ->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[$this->owner->id]['is_system_admin']);
        $this->assertFalse($rows[$manager->id]['is_system_admin']);

        $this->actingInBranch($this->user)->getJson("/api/auth/admin/users/{$this->owner->id}")
            ->assertOk()->assertJsonPath('data.is_system_admin', true);
        $this->actingInBranch($manager)->getJson("/api/auth/admin/users/{$manager->id}")
            ->assertOk()->assertJsonPath('data.is_system_admin', false);

        $rows = collect($this->actingInBranch($manager)->getJson('/api/auth/admin/users?scope=location')
            ->assertOk()->json('data'));
        $this->assertFalse($rows->contains('is_system_admin', true));
    }

    public function test_the_system_admin_can_still_manage_the_owner_account(): void
    {
        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/users/{$this->owner->id}", ['name' => 'Dueño'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Dueño');
    }

    // ─── Usuarios con más permisos que el actor ──────────────────────────────

    public function test_a_manager_cannot_take_over_someone_with_more_permissions(): void
    {
        $manager = $this->branchManager();
        // Tiene reports_view, que el encargado no tiene.
        $accountant = $this->createUserWithRole($this->location, ['inventory_manage', 'reports_view']);

        foreach ([
            ['password' => 'Tomada-123456'],
            ['email' => 'contadora-nueva@example.test'],
            ['is_active' => false],
            ['location_role_id' => $this->cashierRoleId],
        ] as $payload) {
            $this->actingInBranch($manager)
                ->putJson("/api/auth/admin/users/{$accountant->id}", $payload)
                ->assertForbidden()
                ->assertJsonPath('message', 'No tienes permiso para modificar a un usuario con permisos que tú no tienes.');
        }

        $this->actingInBranch($manager)->deleteJson("/api/auth/admin/users/{$accountant->id}")->assertForbidden();
        $this->actingInBranch($manager)->deleteJson("/api/auth/admin/users/{$accountant->id}/current-location")->assertForbidden();

        $fresh = $accountant->fresh();
        $this->assertTrue(Hash::check('password', $fresh->password));
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNotSame($this->cashierRoleId, (int) DB::table('user_location_roles')
            ->where('user_id', $accountant->id)->where('location_id', $this->location->id)->value('role_id'));

        // El nombre y la foto sí; reenviar el formulario sin cambios, también.
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$accountant->id}", [
                'name' => 'Contadora', 'email' => $accountant->email, 'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Contadora');
    }

    public function test_a_manager_can_still_manage_equal_or_lower_users(): void
    {
        $manager = $this->branchManager();
        $cashier = $this->createBranchUser($this->location, ['sales_pos']);
        $peer = $this->createBranchUser($this->location, self::BRANCH_MANAGER);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$cashier->id}", ['password' => 'NuevaClave-123', 'location_role_id' => $this->cashierRoleId])
            ->assertOk();
        $this->assertTrue(Hash::check('NuevaClave-123', $cashier->fresh()->password));

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$peer->id}", ['is_active' => false])
            ->assertOk();
        $this->assertFalse((bool) $peer->fresh()->is_active);

        // A sí mismo también.
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$manager->id}", ['password' => 'MiClave-12345'])
            ->assertOk();

        $this->actingInBranch($manager)->deleteJson("/api/auth/admin/users/{$cashier->id}/current-location")->assertOk();
    }

    public function test_adding_an_existing_user_to_the_branch_follows_the_same_rule(): void
    {
        $manager = $this->allBranchesManager();
        $stronger = $this->createUserWithRole($this->otherBranch, ['reports_view']);
        $weaker = $this->createUserWithRole($this->otherBranch, ['sales_pos']);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$stronger->id}", ['location_role_id' => $this->cashierRoleId])
            ->assertForbidden();
        $this->assertFalse(DB::table('user_location_roles')->where('user_id', $stronger->id)
            ->where('location_id', $this->location->id)->exists());

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$weaker->id}", ['location_role_id' => $this->cashierRoleId])
            ->assertOk();
    }

    public function test_nobody_deactivates_their_own_account(): void
    {
        foreach ([$this->branchManager(), $this->owner] as $user) {
            $user->createToken('telefono');

            $this->actingInBranch($user)
                ->putJson("/api/auth/admin/users/{$user->id}", ['is_active' => false])
                ->assertStatus(422)
                ->assertJsonPath('message', 'No puedes desactivar tu propia cuenta.');

            $this->assertTrue((bool) $user->fresh()->is_active);
            $this->assertSame(1, $user->tokens()->count());

            // Reenviar el formulario con la cuenta activa sigue funcionando.
            $this->actingInBranch($user)
                ->putJson("/api/auth/admin/users/{$user->id}", ['name' => 'Yo mismo', 'is_active' => true])
                ->assertOk();
        }
    }

    // ─── Quitar roles se revisa como asignarlos ──────────────────────────────

    public function test_removing_a_system_role_is_checked_like_assigning_it(): void
    {
        $manager = $this->allBranchesManager();
        // Rol de sistema sin system_admin: el usuario no es administrador y el
        // encargado tiene todos sus permisos, pero no podría habérselo dado.
        $supportRoleId = $this->role('Soporte', ['sales_pos'], null, true);
        $user = $this->createBranchUser($this->location, ['sales_pos']);
        DB::table('users_roles')->insert(['user_id' => $user->id, 'role_id' => $supportRoleId]);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$user->id}", ['role_ids' => []])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para quitar un rol del sistema.');

        $this->assertTrue(DB::table('users_roles')->where('user_id', $user->id)->where('role_id', $supportRoleId)->exists());
    }

    public function test_removing_a_branch_of_another_company_is_checked_like_assigning_it(): void
    {
        $manager = $this->allBranchesManager();
        [$otherCompany, $foreignBranch] = $this->createCompanyWithBranch();
        $foreignCashierRole = $this->role('Cajero ajeno', ['sales_pos'], $otherCompany->id);

        // Cajero aquí y en la otra empresa.
        $user = $this->createBranchUser($this->location, ['sales_pos']);
        DB::table('user_company')->insert(['user_id' => $user->id, 'company_id' => $otherCompany->id]);
        DB::table('user_location_roles')->insert([
            'user_id' => $user->id, 'location_id' => $foreignBranch->id, 'role_id' => $foreignCashierRole,
        ]);

        // location_roles reemplaza todas: sin la sucursal ajena, se la quitaría.
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$user->id}", [
                'location_roles' => [['location_id' => $this->location->id, 'role_id' => $this->cashierRoleId]],
            ])
            ->assertForbidden();

        $this->assertTrue(DB::table('user_location_roles')->where('user_id', $user->id)
            ->where('location_id', $foreignBranch->id)->exists());

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$user->id}", ['company_ids' => [$this->company->id]])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para quitar una empresa a la que no perteneces.');
    }
}
