<?php

namespace Tests\Feature\Foundation;

use App\Models\Admin\Location;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Acceso por sucursal (CurrentWorker): a qué sucursal puede entrar cada
 * usuario, el 404 para registros de sucursales ajenas, la sucursal efectiva
 * de las consultas con location_id opcional, y los helpers de prueba para
 * varias sucursales y empresas.
 */
class FoundationLocationAccessTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Encargado de sucursal: opera su sucursal, no administra sucursales. */
    private const BRANCH = ['sales_list', 'sales_read', 'inventory_list', 'inventory_read'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    /** Contexto de la petición sin pasar por HTTP: usuario, empresa y sucursal. */
    private function context(User $user, Location $location): void
    {
        Sanctum::actingAs($user, ['*']);
        CurrentCompany::set($location->company);
        CurrentLocation::set($location);
    }

    // ─── canAccessLocation ───────────────────────────────────────────────────

    public function test_branch_user_can_access_only_their_branch(): void
    {
        $other = $this->createBranch();
        [, $foreign] = $this->createCompanyWithBranch();
        $this->context($this->createBranchUser($this->location, self::BRANCH), $this->location);

        $this->assertTrue(CurrentWorker::canAccessLocation($this->location->id));
        $this->assertFalse(CurrentWorker::canAccessLocation($other->id));
        $this->assertFalse(CurrentWorker::canAccessLocation($foreign->id));
        $this->assertFalse(CurrentWorker::canAccessLocation(null));
    }

    public function test_all_branches_access_stays_inside_the_current_company(): void
    {
        $other = $this->createBranch();
        [, $foreign] = $this->createCompanyWithBranch();
        $this->context($this->user, $this->location); // todos los permisos

        $this->assertTrue(CurrentWorker::canAccessLocation($other->id));
        $this->assertFalse(CurrentWorker::canAccessLocation($foreign->id));
    }

    public function test_a_branch_of_another_company_is_rejected_at_the_door(): void
    {
        [, $foreign] = $this->createCompanyWithBranch();

        // El administrador de la empresa A mandaba la sucursal de B con el
        // contexto de A y quedaba operando en ella.
        $this->actingInBranch($this->user)
            ->withHeaders(['X-Location-ID' => (string) $foreign->id])
            ->getJson('/api/auth/admin/inventory-adjustments')
            ->assertForbidden()
            ->assertJsonPath('code', 'location_forbidden');

        $this->actingInBranch($this->user)->getJson('/api/auth/admin/inventory-adjustments')->assertOk();
    }

    // ─── abortUnlessCanAccessLocation ────────────────────────────────────────

    public function test_records_of_an_inaccessible_branch_are_not_found(): void
    {
        $other = $this->createBranch();
        $this->context($this->createBranchUser($this->location, self::BRANCH), $this->location);

        CurrentWorker::abortUnlessCanAccessLocation($this->location->id); // no corta

        foreach ([$other->id, null] as $locationId) {
            try {
                CurrentWorker::abortUnlessCanAccessLocation($locationId);
                $this->fail('Debió cortar con 404.');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    // ─── locationScope ───────────────────────────────────────────────────────

    public function test_location_scope_pins_branch_users_to_their_branch(): void
    {
        $other = $this->createBranch();
        $this->context($this->createBranchUser($this->location, self::BRANCH), $this->location);

        foreach ([null, '', 'all', $other->id, (string) $other->id, 'basura'] as $requested) {
            $this->assertSame($this->location->id, CurrentWorker::locationScope($requested));
        }
    }

    public function test_location_scope_without_a_current_branch_is_forbidden_for_branch_users(): void
    {
        $this->context($this->createBranchUser($this->location, self::BRANCH), $this->location);
        CurrentLocation::clear();

        // Devolver null aquí habría sido "todas las sucursales".
        try {
            CurrentWorker::locationScope('all');
            $this->fail('Debió cortar con 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_location_scope_lets_all_branch_users_choose_within_their_company(): void
    {
        $other = $this->createBranch();
        [, $foreign] = $this->createCompanyWithBranch();
        $this->context($this->user, $this->location);

        $this->assertNull(CurrentWorker::locationScope(null));
        $this->assertNull(CurrentWorker::locationScope(''));
        $this->assertNull(CurrentWorker::locationScope('all'));
        $this->assertSame($other->id, CurrentWorker::locationScope((string) $other->id));
        $this->assertSame($other->id, CurrentWorker::locationScope($other->id));

        foreach ([$foreign->id, 'basura', 0, -1, [1]] as $requested) {
            try {
                CurrentWorker::locationScope($requested);
                $this->fail('Debió cortar con 404 para ' . json_encode($requested));
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    // ─── Helpers de prueba ───────────────────────────────────────────────────

    public function test_branch_user_helper_rejects_permissions_that_do_not_exist(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Permisos que no existen: sales_sell');

        $this->createBranchUser($this->location, ['sales_list', 'sales_sell']);
    }

    public function test_branch_user_helper_rejects_all_branches_access(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('acceso a todas las sucursales');

        // locations_list da todas las sucursales hoy; system_admin, también
        // con el permiso dedicado (locations_all).
        $this->createBranchUser($this->location, ['sales_list', 'locations_list', 'system_admin']);
    }

    public function test_preset_permissions_follow_the_role_editor_templates(): void
    {
        $operativo = $this->presetPermissions('operativo');
        $lectura = $this->presetPermissions('lectura');

        $this->assertContains('transfers_create', $operativo);
        $this->assertContains('production_orders_update', $operativo);
        $this->assertContains('expenses_export', $operativo);
        // Fuera: administración, borrar, gestionar y los permisos especiales.
        foreach (['locations_list', 'users_update', 'roles_create', 'sales_delete', 'inventory_manage', 'sales_pos', 'system_admin'] as $excluded) {
            $this->assertNotContains($excluded, $operativo);
        }

        $this->assertContains('sales_read', $lectura);
        $this->assertNotContains('sales_create', $lectura);
        $this->assertSame([], array_diff($lectura, $operativo), 'solo lectura es un subconjunto de operativo');

        $this->assertCount(DB::table('permissions')->count(), $this->presetPermissions('all'));

        // Un rol "Operativo" es de sucursal.
        $this->createBranchUser($this->location, $operativo);
    }

    public function test_acting_in_branch_sends_the_branch_company_and_does_not_leak_context(): void
    {
        [$foreignCompany, $foreign] = $this->createCompanyWithBranch();
        $outsider = $this->createUserWithRole($foreign);

        $this->assertSame(
            ['X-Company-ID' => (string) $foreignCompany->id, 'X-Location-ID' => (string) $foreign->id, 'Accept' => 'application/json'],
            $this->tenantHeaders($foreign)
        );
        $this->assertSame(['Accept' => 'application/json', 'X-Location-ID' => (string) $foreign->id], $this->contextHeaders(null, $foreign));

        $this->actingInBranch($outsider, $foreign)->getJson('/api/auth/admin/inventory-adjustments')->assertOk();

        // Lo que fijó la petición no queda para la siguiente.
        $this->assertNull(CurrentCompany::id());
        $this->assertNull(CurrentLocation::id());
    }
}
