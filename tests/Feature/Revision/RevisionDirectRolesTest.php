<?php

namespace Tests\Feature\Revision;

use App\Models\Admin\Location;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Los roles directos (role_ids, users_roles) aplican en todas las sucursales
 * del usuario. Un encargado que solo opera su sucursal podía darse su rol
 * (o dárselo a un cajero) y quedar como encargado también en las demás.
 * Ahora, como location_roles, solo los cambia quien tiene acceso a todas.
 */
class RevisionDirectRolesTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const ENCARGADO = ['sales_pos', 'users_create', 'users_read', 'users_update', 'users_list', 'sales_list', 'sales_read', 'sales_update', 'roles_list', 'roles_read'];

    private Location $branchB;
    private int $encargadoRole;
    private int $cajeroRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        $this->branchB = $this->createBranch(['name' => 'B']);
        $this->encargadoRole = $this->role(self::ENCARGADO);
        $this->cajeroRole = $this->role(['sales_pos']);
    }

    private function role(array $permissions): int
    {
        $id = DB::table('roles')->insertGetId([
            'name' => 'Rol ' . uniqid(), 'company_id' => $this->company->id, 'is_system' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('permissions')->whereIn('name', $permissions)->pluck('id') as $permissionId) {
            DB::table('rol_permission')->insert(['role_id' => $id, 'permission_id' => $permissionId]);
        }

        return $id;
    }

    /** @param array<int, int> $roleByLocation [location_id => role_id] */
    private function staff(array $roleByLocation): User
    {
        $user = User::factory()->create();
        foreach ($roleByLocation as $locationId => $roleId) {
            DB::table('user_location_roles')->insert(['user_id' => $user->id, 'location_id' => $locationId, 'role_id' => $roleId]);
        }
        DB::table('user_company')->insert(['user_id' => $user->id, 'company_id' => $this->company->id]);

        return $user;
    }

    private function directRoles(User $user): array
    {
        return DB::table('users_roles')->where('user_id', $user->id)->pluck('role_id')->map(fn($id) => (int) $id)->all();
    }

    public function test_a_branch_manager_cannot_make_a_cashier_manager_in_another_branch(): void
    {
        $manager = $this->staff([$this->location->id => $this->encargadoRole]);
        $worker = $this->staff([$this->location->id => $this->cajeroRole, $this->branchB->id => $this->cajeroRole]);
        $saleB = Sale::create([
            'company_id' => $this->company->id, 'location_id' => $this->branchB->id, 'user_id' => $worker->id,
            'sale_date' => now()->toDateString(), 'status' => 'closed', 'subtotal' => 100, 'tax' => 0, 'discount' => 0,
            'total' => 100, 'paid_amount' => 100, 'payment_method' => 'card', 'payment_status' => 'paid',
        ]);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$worker->id}", ['role_ids' => [$this->encargadoRole]])
            ->assertForbidden();

        $this->assertSame([], $this->directRoles($worker));
        $this->actingInBranch($worker, $this->branchB)->getJson("/api/auth/admin/sales/{$saleB->id}")->assertForbidden();
    }

    public function test_a_branch_manager_cannot_grant_themselves_a_direct_role(): void
    {
        $manager = $this->staff([$this->location->id => $this->encargadoRole, $this->branchB->id => $this->cajeroRole]);

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$manager->id}", ['role_ids' => [$this->encargadoRole]])
            ->assertForbidden();

        $this->assertSame([], $this->directRoles($manager));
        $this->actingInBranch($manager, $this->branchB)->getJson('/api/auth/admin/users?scope=location')->assertForbidden();
    }

    public function test_a_branch_manager_cannot_create_a_user_with_direct_roles(): void
    {
        $manager = $this->staff([$this->location->id => $this->encargadoRole]);

        $this->actingInBranch($manager)
            ->postJson('/api/auth/admin/users', [
                'name' => 'Nuevo', 'email' => 'nuevo-directo@example.test', 'password' => 'Secret123!',
                'role_ids' => [$this->cajeroRole],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nuevo-directo@example.test']);
    }

    public function test_the_branch_role_flow_of_the_app_keeps_working(): void
    {
        $manager = $this->staff([$this->location->id => $this->encargadoRole]);
        $worker = $this->staff([$this->location->id => $this->cajeroRole]);

        // La app cambia el rol de la sucursal actual con location_role_id.
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/users/{$worker->id}", ['location_role_id' => $this->encargadoRole])
            ->assertOk();

        $this->assertSame($this->encargadoRole, (int) DB::table('user_location_roles')
            ->where('user_id', $worker->id)->where('location_id', $this->location->id)->value('role_id'));
    }

    public function test_an_all_branches_admin_can_still_assign_direct_roles(): void
    {
        $admin = $this->createUserWithRole($this->location, [...self::ENCARGADO, 'locations_list', 'locations_all']);
        $worker = $this->staff([$this->location->id => $this->cajeroRole]);

        $this->actingInBranch($admin)
            ->putJson("/api/auth/admin/users/{$worker->id}", ['role_ids' => [$this->encargadoRole]])
            ->assertOk();

        $this->assertSame([$this->encargadoRole], $this->directRoles($worker));
    }
}
