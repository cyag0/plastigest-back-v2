<?php

namespace Tests\Feature\Revision;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * La lista y el detalle de usuarios traen can_manage: si quien consulta puede
 * cambiarle a ese usuario contraseña, correo, estado y roles. Es la misma
 * regla que aplica UserController al guardar (administrador del sistema +
 * tener todos sus permisos), para que la app oculte esos controles en vez de
 * esperar el 403.
 */
class RevisionUserManageFlagTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const MANAGER = ['users_list', 'users_read', 'users_update', 'sales_pos'];

    private User $owner;
    private User $manager;
    private User $cashier;
    private User $superior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();

        $superAdminRole = DB::table('roles')->insertGetId([
            'name' => 'Super Admin', 'company_id' => null, 'is_system' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rol_permission')->insert(DB::table('permissions')->pluck('id')
            ->map(fn($id) => ['role_id' => $superAdminRole, 'permission_id' => $id])->all());
        $this->owner = User::factory()->create();
        DB::table('users_roles')->insert(['user_id' => $this->owner->id, 'role_id' => $superAdminRole]);
        DB::table('user_company')->insert(['user_id' => $this->owner->id, 'company_id' => $this->company->id]);
        DB::table('user_location_roles')->insert(['user_id' => $this->owner->id, 'location_id' => $this->location->id, 'role_id' => $superAdminRole]);

        $this->manager = $this->createBranchUser($this->location, self::MANAGER);
        $this->cashier = $this->createBranchUser($this->location, ['sales_pos']);
        $this->superior = $this->createUserWithRole($this->location, array_values(array_diff($this->presetPermissions('all'), ['system_admin'])));
    }

    /** @return array<int, bool> can_manage por id de usuario en la lista */
    private function listFlags(User $actor): array
    {
        return collect($this->actingInBranch($actor)->getJson('/api/auth/admin/users?scope=location')->assertOk()->json('data'))
            ->mapWithKeys(fn($row) => [$row['id'] => $row['can_manage']])
            ->all();
    }

    public function test_the_list_marks_who_the_manager_can_manage(): void
    {
        $flags = $this->listFlags($this->manager);

        $this->assertTrue($flags[$this->cashier->id]);
        $this->assertTrue($flags[$this->manager->id], 'a sí mismo sí');
        $this->assertFalse($flags[$this->superior->id]);
        $this->assertArrayNotHasKey($this->owner->id, $flags, 'el administrador del sistema no aparece en su lista');
    }

    public function test_the_detail_matches_what_saving_allows(): void
    {
        $this->actingInBranch($this->manager)->getJson("/api/auth/admin/users/{$this->cashier->id}")
            ->assertOk()->assertJsonPath('data.can_manage', true);
        $this->actingInBranch($this->manager)
            ->putJson("/api/auth/admin/users/{$this->cashier->id}", ['password' => 'Nueva-clave-123'])
            ->assertOk()
            ->assertJsonPath('data.can_manage', true);

        $this->actingInBranch($this->manager)->getJson("/api/auth/admin/users/{$this->superior->id}")
            ->assertOk()->assertJsonPath('data.can_manage', false);
        $this->actingInBranch($this->manager)
            ->putJson("/api/auth/admin/users/{$this->superior->id}", ['password' => 'Nueva-clave-123'])
            ->assertForbidden();
    }

    public function test_only_the_system_admin_can_manage_everyone(): void
    {
        $flags = $this->listFlags($this->owner);
        foreach ([$this->manager, $this->cashier, $this->superior, $this->owner] as $user) {
            $this->assertTrue($flags[$user->id], "el dueño no puede administrar a {$user->id}");
        }

        // Quien tiene todos los permisos salvo system_admin no puede con el dueño.
        $this->actingInBranch($this->superior)->getJson("/api/auth/admin/users/{$this->owner->id}")
            ->assertOk()->assertJsonPath('data.can_manage', false);
        $flags = $this->listFlags($this->superior);
        $this->assertTrue($flags[$this->manager->id]);
        $this->assertTrue($flags[$this->cashier->id]);
    }
}
