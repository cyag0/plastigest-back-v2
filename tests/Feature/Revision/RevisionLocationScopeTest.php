<?php

namespace Tests\Feature\Revision;

use App\Models\Admin\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * locations_update (en la plantilla "Encargado") dejaba renombrar o
 * desactivar CUALQUIER sucursal de la empresa: el listado ya ocultaba las
 * demás, pero show/update/destroy por id no. Ahora quien no tiene acceso a
 * todas las sucursales solo ve y edita la actual (como la configuración de
 * la sucursal) y no la desactiva.
 */
class RevisionLocationScopeTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private const ENCARGADO = ['locations_read', 'locations_update', 'locations_delete', 'sales_pos'];

    private Location $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
        $this->other = $this->createBranch(['name' => 'Pitillal', 'is_active' => true]);
    }

    private function payload(Location $location, array $changes = []): array
    {
        return array_merge([
            'name' => $location->name, 'address' => $location->address ?? 'Calle 1', 'company_id' => $location->company_id,
            'is_active' => (bool) $location->is_active,
        ], $changes);
    }

    public function test_a_branch_manager_cannot_see_edit_or_delete_another_branch(): void
    {
        $manager = $this->createBranchUser($this->location, self::ENCARGADO);

        $this->actingInBranch($manager)->getJson("/api/auth/admin/locations/{$this->other->id}")->assertNotFound();
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/locations/{$this->other->id}", $this->payload($this->other, ['name' => 'CERRADA', 'is_active' => false]))
            ->assertNotFound();
        $this->actingInBranch($manager)->deleteJson("/api/auth/admin/locations/{$this->other->id}")->assertNotFound();

        $row = DB::table('locations')->where('id', $this->other->id)->first();
        $this->assertSame('Pitillal', $row->name);
        $this->assertTrue((bool) $row->is_active);
    }

    public function test_only_the_current_branch_even_when_assigned_to_several(): void
    {
        $manager = $this->createBranchUser($this->location, self::ENCARGADO);
        $roleId = DB::table('user_location_roles')->where('user_id', $manager->id)->value('role_id');
        DB::table('user_location_roles')->insert(['user_id' => $manager->id, 'location_id' => $this->other->id, 'role_id' => $roleId]);

        // El selector de sucursales sigue mostrando las dos.
        $ids = collect($this->actingInBranch($manager)->getJson('/api/auth/admin/locations')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$this->location->id, $this->other->id], $ids->all());

        $this->actingInBranch($manager)->getJson("/api/auth/admin/locations/{$this->other->id}")->assertNotFound();
        $this->actingInBranch($manager, $this->other)->getJson("/api/auth/admin/locations/{$this->other->id}")->assertOk();
    }

    public function test_a_branch_manager_edits_the_data_of_their_branch_but_cannot_deactivate_it(): void
    {
        $manager = $this->createBranchUser($this->location, self::ENCARGADO);

        $this->actingInBranch($manager)->getJson("/api/auth/admin/locations/{$this->location->id}")->assertOk();
        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/locations/{$this->location->id}", $this->payload($this->location, ['name' => 'Centro']))
            ->assertOk();
        $this->assertSame('Centro', DB::table('locations')->where('id', $this->location->id)->value('name'));

        $this->actingInBranch($manager)
            ->putJson("/api/auth/admin/locations/{$this->location->id}", $this->payload($this->location->fresh(), ['is_active' => false]))
            ->assertForbidden();
        $this->assertTrue((bool) DB::table('locations')->where('id', $this->location->id)->value('is_active'));
    }

    public function test_an_all_branches_admin_still_manages_every_branch(): void
    {
        $this->actingInBranch($this->user)->getJson("/api/auth/admin/locations/{$this->other->id}")->assertOk();
        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/locations/{$this->other->id}", $this->payload($this->other, ['name' => 'Pitillal 2', 'is_active' => false]))
            ->assertOk();

        $row = DB::table('locations')->where('id', $this->other->id)->first();
        $this->assertSame('Pitillal 2', $row->name);
        $this->assertFalse((bool) $row->is_active);
    }
}
