<?php

namespace Tests\Feature\Flows;

use App\Models\ActivityLog;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Administración › Esta sucursal: usuarios, bitácora y avisos de la sucursal
 * actual, sin tocar los de las demás.
 */
class BranchAdministrationTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Permisos de un encargado que solo opera su sucursal (sin locations_list). */
    private const BRANCH_MANAGER = [
        'users_list', 'users_read', 'users_create', 'users_update', 'users_delete',
        'sales_pos', 'inventory_manage',
    ];

    private Location $otherBranch;
    private int $cashierRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant(); // $this->user: todos los permisos en $this->location

        $this->otherBranch = Location::factory()->create(['company_id' => $this->company->id]);
        $this->cashierRoleId = $this->createRole('Cajero', ['sales_pos']);
    }

    private function createRole(string $name, array $permissions, ?int $companyId = null): int
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => $name, 'company_id' => $companyId ?? $this->company->id, 'is_system' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rol_permission')->insert(
            DB::table('permissions')->whereIn('name', $permissions)->pluck('id')
                ->map(fn($id) => ['role_id' => $roleId, 'permission_id' => $id])->all()
        );

        return $roleId;
    }

    private function roleIn(User $user, Location $location): ?int
    {
        $roleId = DB::table('user_location_roles')
            ->where('user_id', $user->id)->where('location_id', $location->id)->value('role_id');

        return $roleId === null ? null : (int) $roleId;
    }

    private function inBranch(User $user, Location $location): bool
    {
        return DB::table('user_location_roles')
            ->where('user_id', $user->id)->where('location_id', $location->id)->exists();
    }

    // ─── Usuarios de la sucursal ─────────────────────────────────────────────

    public function test_branch_users_lists_only_people_with_access_to_the_current_branch(): void
    {
        $here = $this->createUserWithRole($this->location, ['sales_pos']);
        $elsewhere = $this->createUserWithRole($this->otherBranch, ['sales_pos']);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/users?scope=location')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($here->id));
        $this->assertTrue($ids->contains($this->user->id));
        $this->assertFalse($ids->contains($elsewhere->id));

        // Cada uno trae su rol en esta sucursal.
        $row = collect($response->json('data'))->firstWhere('id', $here->id);
        $this->assertSame($this->location->id, $row['location_role']['location_id']);
        $this->assertNotNull($row['location_role']['role_name']);
    }

    public function test_a_user_created_from_the_branch_lands_in_the_branch_with_its_role(): void
    {
        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/users', [
                'name' => 'Cajera Nueva', 'email' => 'cajera-nueva@example.test', 'password' => 'Secret123!',
                'location_role_id' => $this->cashierRoleId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.location_role.role_id', $this->cashierRoleId);

        $user = User::where('email', 'cajera-nueva@example.test')->firstOrFail();
        $this->assertSame($this->cashierRoleId, $this->roleIn($user, $this->location));
        $this->assertFalse($this->inBranch($user, $this->otherBranch));
        $this->assertTrue($user->companies()->where('companies.id', $this->company->id)->exists());
    }

    public function test_changing_the_role_in_the_branch_keeps_the_other_branches(): void
    {
        $person = $this->createUserWithRole($this->location, ['sales_pos']);
        $otherRoleId = $this->createRole('Encargado', ['inventory_manage']);
        DB::table('user_location_roles')->insert([
            'user_id' => $person->id, 'location_id' => $this->otherBranch->id, 'role_id' => $otherRoleId,
        ]);

        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/users/{$person->id}", ['location_role_id' => $this->cashierRoleId])
            ->assertOk();

        $this->assertSame($this->cashierRoleId, $this->roleIn($person, $this->location));
        $this->assertSame($otherRoleId, $this->roleIn($person, $this->otherBranch));

        $this->assertTrue(
            ActivityLog::where('subject_type', User::class)->where('subject_id', $person->id)
                ->where('description', 'like', 'Cambió el rol de%')->exists()
        );
    }

    public function test_an_existing_user_of_the_company_can_be_added_to_the_branch(): void
    {
        $fromOtherBranch = $this->createUserWithRole($this->otherBranch, ['sales_pos']);

        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/users/{$fromOtherBranch->id}", ['location_role_id' => $this->cashierRoleId])
            ->assertOk();

        $this->assertSame($this->cashierRoleId, $this->roleIn($fromOtherBranch, $this->location));
        $this->assertTrue($this->inBranch($fromOtherBranch, $this->otherBranch));
    }

    public function test_the_branch_role_must_belong_to_the_company(): void
    {
        $otherCompany = Company::factory()->create();
        $foreignRoleId = $this->createRole('Ajeno', ['sales_pos'], $otherCompany->id);

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/users', [
                'name' => 'X', 'email' => 'x-branch@example.test', 'password' => 'Secret123!',
                'location_role_id' => $foreignRoleId,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'x-branch@example.test']);
    }

    public function test_removing_from_the_branch_only_drops_that_branch(): void
    {
        $person = $this->createUserWithRole($this->location, ['sales_pos']);
        DB::table('user_location_roles')->insert([
            'user_id' => $person->id, 'location_id' => $this->otherBranch->id, 'role_id' => $this->cashierRoleId,
        ]);

        $this->withHeaders($this->tenantHeaders())
            ->deleteJson("/api/auth/admin/users/{$person->id}/current-location")
            ->assertOk();

        $this->assertFalse($this->inBranch($person, $this->location));
        $this->assertTrue($this->inBranch($person, $this->otherBranch));
        $this->assertDatabaseHas('users', ['id' => $person->id]);
        $this->assertTrue(
            ActivityLog::where('subject_id', $person->id)->where('description', 'like', 'Quitó a%')->exists()
        );

        // Ya no aparece entre los usuarios de la sucursal.
        $ids = collect($this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/users?scope=location')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($person->id));
    }

    public function test_nobody_can_remove_themselves_from_the_branch_they_are_in(): void
    {
        $this->withHeaders($this->tenantHeaders())
            ->deleteJson("/api/auth/admin/users/{$this->user->id}/current-location")
            ->assertStatus(422);

        $this->assertTrue($this->inBranch($this->user, $this->location));
    }

    // ─── Encargado que solo opera su sucursal ───────────────────────────────

    public function test_a_branch_only_manager_only_sees_and_edits_users_of_their_branch(): void
    {
        $manager = $this->createUserWithRole($this->location, self::BRANCH_MANAGER);
        $here = $this->createUserWithRole($this->location, ['sales_pos']);
        $elsewhere = $this->createUserWithRole($this->otherBranch, ['sales_pos']);
        Sanctum::actingAs($manager, ['*']);

        // También desde "Usuarios" de la empresa (sin scope).
        $ids = collect($this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/users?by_company=1')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($here->id));
        $this->assertFalse($ids->contains($elsewhere->id));

        $this->withHeaders($this->tenantHeaders())
            ->getJson("/api/auth/admin/users/{$elsewhere->id}")->assertNotFound();
        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/users/{$elsewhere->id}", ['location_role_id' => $this->cashierRoleId])
            ->assertNotFound();
        $this->assertFalse($this->inBranch($elsewhere, $this->location));
    }

    public function test_a_branch_only_manager_cannot_rewrite_all_branches_of_a_user(): void
    {
        $manager = $this->createUserWithRole($this->location, self::BRANCH_MANAGER);
        $here = $this->createUserWithRole($this->location, ['sales_pos']);
        Sanctum::actingAs($manager, ['*']);

        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/users/{$here->id}", [
                'location_roles' => [['location_id' => $this->otherBranch->id, 'role_id' => $this->cashierRoleId]],
            ])
            ->assertForbidden();

        // Su rol en la sucursal sí lo puede cambiar.
        $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/auth/admin/users/{$here->id}", ['location_role_id' => $this->cashierRoleId])
            ->assertOk();
        $this->assertSame($this->cashierRoleId, $this->roleIn($here, $this->location));
    }

    public function test_a_branch_only_manager_cannot_delete_someone_who_works_in_other_branches(): void
    {
        $manager = $this->createUserWithRole($this->location, self::BRANCH_MANAGER);
        $shared = $this->createUserWithRole($this->location, ['sales_pos']);
        DB::table('user_location_roles')->insert([
            'user_id' => $shared->id, 'location_id' => $this->otherBranch->id, 'role_id' => $this->cashierRoleId,
        ]);
        Sanctum::actingAs($manager, ['*']);

        $this->withHeaders($this->tenantHeaders())
            ->deleteJson("/api/auth/admin/users/{$shared->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $shared->id]);
    }

    public function test_company_users_only_lists_the_selected_company_even_for_the_system_admin(): void
    {
        $otherCompany = Company::factory()->create();
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $outsider = $this->createUserWithRole($otherLocation, ['sales_pos']);

        $ids = collect($this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/users?by_company=1')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($this->user->id));
        $this->assertFalse($ids->contains($outsider->id));
    }

    // ─── Bitácora de la sucursal ─────────────────────────────────────────────

    public function test_branch_activity_only_shows_what_happened_in_the_branch(): void
    {
        $base = ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'user_name' => 'Admin', 'module' => 'ventas', 'event' => 'created'];
        ActivityLog::create($base + ['location_id' => $this->location->id, 'description' => 'Venta aquí']);
        ActivityLog::create($base + ['location_id' => $this->otherBranch->id, 'description' => 'Venta allá']);

        $descriptions = collect($this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/activity?user_id=all&scope=location')->assertOk()->json('data'))
            ->pluck('description');

        $this->assertTrue($descriptions->contains('Venta aquí'));
        $this->assertFalse($descriptions->contains('Venta allá'));
    }

    // ─── Avisos de la sucursal ───────────────────────────────────────────────

    public function test_branch_notifications_inherit_the_company_until_customized(): void
    {
        NotificationPreference::create(array_merge(
            NotificationPreference::getDefaults()['low_stock'],
            ['company_id' => $this->company->id, 'event_type' => 'low_stock', 'channel_email' => true]
        ));

        $lowStock = fn() => collect($this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/notification-preferences?scope=location')->assertOk()->json('data'))
            ->firstWhere('event_type', 'low_stock');

        $this->assertFalse($lowStock()['is_customized']);
        $this->assertSame('company', $lowStock()['source']);
        $this->assertTrue($lowStock()['channel_email']);

        // Apagar el push en la sucursal: el resto sigue como en la empresa.
        $this->withHeaders($this->tenantHeaders())
            ->patchJson('/api/auth/admin/notification-preferences/low_stock?scope=location', ['channel_push' => false])
            ->assertOk();

        $this->assertTrue($lowStock()['is_customized']);
        $this->assertFalse($lowStock()['channel_push']);
        $this->assertTrue($lowStock()['channel_email']);

        // La empresa no cambió.
        $company = collect($this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/notification-preferences')->json('data'))->firstWhere('event_type', 'low_stock');
        $this->assertTrue($company['channel_push']);

        // Volver a la configuración de la empresa.
        $this->withHeaders($this->tenantHeaders())
            ->deleteJson('/api/auth/admin/notification-preferences/low_stock?scope=location')
            ->assertOk();
        $this->assertFalse($lowStock()['is_customized']);
        $this->assertTrue($lowStock()['channel_push']);
    }

    public function test_a_branch_can_turn_off_an_alert_only_for_itself(): void
    {
        $managerHere = $this->createUserWithRole($this->location, ['inventory_manage']);
        $managerThere = $this->createUserWithRole($this->otherBranch, ['inventory_manage']);

        $this->withHeaders($this->tenantHeaders())
            ->patchJson('/api/auth/admin/notification-preferences/low_stock?scope=location', ['is_active' => false])
            ->assertOk();

        $movements = new MovementService();
        $productHere = $this->createProduct([], 1);
        $movements->decrement($this->location->id, $productHere->id, $productHere->unit_id, 1);

        $productThere = $this->createProduct([], 0);
        $this->setStock($productThere, $this->otherBranch, 1);
        $movements->decrement($this->otherBranch->id, $productThere->id, $productThere->unit_id, 1);

        $inbox = fn(User $u) => Notification::where('user_id', $u->id)->where('event_type', 'low_stock')->where('channel', 'db')->count();
        $this->assertSame(0, $inbox($managerHere), 'la sucursal apagó el aviso');
        $this->assertSame(1, $inbox($managerThere), 'las demás siguen con la configuración de la empresa');
    }

    public function test_branch_recipients_are_people_of_the_branch(): void
    {
        $managerHere = $this->createUserWithRole($this->location, ['inventory_manage']);
        $managerThere = $this->createUserWithRole($this->otherBranch, ['inventory_manage']);

        $ids = collect($this->withHeaders($this->tenantHeaders())
            ->getJson('/api/auth/admin/notification-preferences/low_stock/eligible-users?scope=location')
            ->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($managerHere->id));
        $this->assertFalse($ids->contains($managerThere->id));
    }

    public function test_resetting_the_company_keeps_the_branch_configuration(): void
    {
        $this->withHeaders($this->tenantHeaders())
            ->patchJson('/api/auth/admin/notification-preferences/low_stock', ['channel_email' => true])->assertOk();
        $this->withHeaders($this->tenantHeaders())
            ->patchJson('/api/auth/admin/notification-preferences/low_stock?scope=location', ['is_active' => false])->assertOk();

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/notification-preferences/reset')->assertOk();

        $this->assertDatabaseMissing('notification_preferences', ['company_id' => $this->company->id, 'location_id' => null]);
        $this->assertDatabaseHas('notification_preferences', [
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'is_active' => false,
        ]);
    }
}
