<?php

namespace Tests\Feature\Security;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Endurecimiento de seguridad: aislamiento entre empresas (tenant), escalación
 * de privilegios al asignar roles, cuentas desactivadas y registro público.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        // Empresa A con un usuario administrador (todos los permisos).
        $this->createTenant();
    }

    /** Crea una segunda empresa con su sucursal. */
    private function makeCompanyB(): array
    {
        $companyB = Company::factory()->create();
        $locationB = Location::factory()->create(['company_id' => $companyB->id]);

        return [$companyB, $locationB];
    }

    /** Usuario básico (no administrador del sistema) de la empresa A. */
    private function basicUserOfCompanyA(array $permissions = ['sales_list', 'sales_read']): User
    {
        return $this->createUserWithRole($this->location, $permissions);
    }

    // ─── Aislamiento por empresa ──────────────────────────────────────────────

    public function test_cross_tenant_company_header_is_forbidden(): void
    {
        [$companyB, $locationB] = $this->makeCompanyB();

        // Usuario de A que NO es administrador del sistema.
        Sanctum::actingAs($this->basicUserOfCompanyA(), ['*']);

        $this->withHeaders([
            'X-Company-ID' => (string) $companyB->id,
            'X-Location-ID' => (string) $locationB->id,
            'Accept' => 'application/json',
        ])->getJson('/api/auth/admin/sales')
            ->assertStatus(403)
            ->assertJsonPath('code', 'company_forbidden');
    }

    public function test_cross_tenant_record_id_returns_404(): void
    {
        [$companyB, $locationB] = $this->makeCompanyB();

        // Venta que vive en la empresa B.
        $saleB = Sale::factory()->create([
            'company_id' => $companyB->id,
            'location_id' => $locationB->id,
            'user_id' => $this->user->id,
        ]);

        // Usuario de A, con contexto válido de A, pide el id de una venta de B.
        Sanctum::actingAs($this->basicUserOfCompanyA(['sales_list', 'sales_read']), ['*']);

        $this->withHeaders($this->tenantHeaders())
            ->getJson("/api/auth/admin/sales/{$saleB->id}")
            ->assertStatus(404);
    }

    // ─── Escalación de privilegios ────────────────────────────────────────────

    public function test_manager_cannot_assign_a_system_role(): void
    {
        // Rol de sistema (como "Super Admin"): company_id null, is_system true.
        $systemRoleId = DB::table('roles')->insertGetId([
            'name' => 'Super Admin', 'company_id' => null, 'is_system' => true,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Actor: puede crear usuarios, pero NO es administrador del sistema.
        $manager = $this->basicUserOfCompanyA(['users_create', 'users_update', 'users_list', 'users_read']);
        Sanctum::actingAs($manager, ['*']);

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/users', [
                'name' => 'Nuevo', 'email' => 'nuevo-sec@example.test', 'password' => 'Secret123!',
                'role_ids' => [$systemRoleId],
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'nuevo-sec@example.test']);
    }

    public function test_manager_cannot_grant_permissions_they_lack(): void
    {
        // Rol de la empresa A con un permiso que el actor NO tiene.
        $strongRoleId = DB::table('roles')->insertGetId([
            'name' => 'Fuerte', 'company_id' => $this->company->id, 'is_system' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permId = DB::table('permissions')->where('name', 'companies_delete')->value('id');
        DB::table('rol_permission')->insert(['role_id' => $strongRoleId, 'permission_id' => $permId]);

        // Actor solo con permisos de usuarios (sin companies_delete).
        $manager = $this->basicUserOfCompanyA(['users_create', 'users_update']);
        Sanctum::actingAs($manager, ['*']);

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/users', [
                'name' => 'Otro', 'email' => 'otro-sec@example.test', 'password' => 'Secret123!',
                'role_ids' => [$strongRoleId],
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'otro-sec@example.test']);
    }

    public function test_admin_can_still_create_a_user_with_an_allowed_role(): void
    {
        // El administrador de la empresa (todos los permisos) sí puede crear un
        // cajero con un rol de su empresa: el flujo legítimo sigue funcionando.
        $cashierRoleId = DB::table('roles')->insertGetId([
            'name' => 'Cajero', 'company_id' => $this->company->id, 'is_system' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permId = DB::table('permissions')->where('name', 'sales_pos')->value('id');
        DB::table('rol_permission')->insert(['role_id' => $cashierRoleId, 'permission_id' => $permId]);

        // $this->user (de createTenant) tiene todos los permisos.
        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/auth/admin/users', [
                'name' => 'Cajero Nuevo', 'email' => 'cajero-nuevo-sec@example.test', 'password' => 'Secret123!',
                'location_roles' => [['location_id' => $this->location->id, 'role_id' => $cashierRoleId]],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'cajero-nuevo-sec@example.test']);
    }

    // ─── Registro público ─────────────────────────────────────────────────────

    public function test_public_registration_is_disabled(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Hacker', 'email' => 'hacker-sec@example.test',
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('users', ['email' => 'hacker-sec@example.test']);
    }
}
