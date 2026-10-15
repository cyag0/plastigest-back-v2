<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * El Super Admin está en todas las sucursales con su rol de sistema (así le
 * llegan los avisos de cada una), pero una sucursal creada desde la app no
 * lo incluía: de ella no le llegaba ningún aviso.
 */
class AccesosNewBranchTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    public function test_system_admins_of_the_company_join_a_new_branch(): void
    {
        $this->createTenant();

        $superAdminRoleId = DB::table('roles')->insertGetId([
            'name' => 'Super Admin', 'company_id' => null, 'is_system' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rol_permission')->insert(DB::table('permissions')->pluck('id')
            ->map(fn($id) => ['role_id' => $superAdminRoleId, 'permission_id' => $id])->all());
        $owner = User::factory()->create();
        DB::table('users_roles')->insert(['user_id' => $owner->id, 'role_id' => $superAdminRoleId]);
        DB::table('user_company')->insert(['user_id' => $owner->id, 'company_id' => $this->company->id]);

        [$otherCompany] = $this->createCompanyWithBranch();
        $foreignAdmin = User::factory()->create();
        DB::table('users_roles')->insert(['user_id' => $foreignAdmin->id, 'role_id' => $superAdminRoleId]);
        DB::table('user_company')->insert(['user_id' => $foreignAdmin->id, 'company_id' => $otherCompany->id]);

        $manager = $this->createUserWithRole($this->location, ['locations_create', 'locations_list', 'locations_all']);

        $branchId = $this->actingInBranch($manager)->postJson('/api/auth/admin/locations', [
            'name' => 'Sucursal Nueva', 'address' => 'Av. Siempre Viva 1', 'company_id' => $this->company->id,
        ])->assertCreated()->json('data.id');

        $this->assertSame($superAdminRoleId, (int) DB::table('user_location_roles')
            ->where('user_id', $owner->id)->where('location_id', $branchId)->value('role_id'));
        $this->assertFalse(DB::table('user_location_roles')->where('location_id', $branchId)
            ->whereIn('user_id', [$foreignAdmin->id, $manager->id])->exists());
    }
}
