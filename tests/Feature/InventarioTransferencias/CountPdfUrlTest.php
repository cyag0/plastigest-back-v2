<?php

namespace Tests\Feature\InventarioTransferencias;

use App\Models\InventoryCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * El PDF de un conteo se emite con el mismo permiso y la misma sucursal que
 * la pantalla del conteo (antes bastaba con ser de la empresa).
 */
class CountPdfUrlTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function countAt($location): InventoryCount
    {
        return InventoryCount::create([
            'company_id' => $this->company->id, 'location_id' => $location->id, 'user_id' => $this->user->id,
            'name' => 'Conteo semanal', 'count_date' => now()->toDateString(), 'status' => 'completed',
        ]);
    }

    public function test_pdf_url_needs_inventory_permission_and_the_count_branch(): void
    {
        $propio = $this->countAt($this->location);
        $deOtra = $this->countAt($this->createBranch());

        $lector = $this->createBranchUser($this->location, ['inventory_list']);
        $this->actingInBranch($lector)->getJson("/api/auth/admin/inventory-counts/{$propio->id}/pdf-url")
            ->assertOk()
            ->assertJsonStructure(['url', 'expires_at']);
        $this->actingInBranch($lector)->getJson("/api/auth/admin/inventory-counts/{$deOtra->id}/pdf-url")
            ->assertNotFound();

        $cajero = $this->createBranchUser($this->location, ['sales_pos']);
        $this->actingInBranch($cajero)->getJson("/api/auth/admin/inventory-counts/{$propio->id}/pdf-url")
            ->assertForbidden();

        // Quien ve todas las sucursales sí emite el de otra.
        $this->actingInBranch($this->user)->getJson("/api/auth/admin/inventory-counts/{$deOtra->id}/pdf-url")
            ->assertOk();
    }
}
