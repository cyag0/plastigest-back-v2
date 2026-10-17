<?php

namespace Tests\Feature\Flows;

use App\Models\Admin\Company;
use App\Models\Admin\Customer;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Clientes y proveedores se quedan en su empresa: al crearlos como los manda
 * la app (FormData, company_id como texto o lista) y al editarlos.
 */
class CatalogCompanyTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    /** Una empresa con id de dos dígitos: "12"[0] era "1". */
    private function companyWithTwoDigitId(): Company
    {
        while (Company::max('id') < 9) {
            Company::factory()->create();
        }

        return Company::factory()->create();
    }

    public function test_a_supplier_is_saved_in_its_company_when_the_id_has_two_digits(): void
    {
        $company = $this->companyWithTwoDigitId();
        $location = $this->createBranch([], $company);
        $user = $this->createUserWithRole($location);
        $this->assertGreaterThanOrEqual(10, $company->id);

        $this->actingInBranch($user, $location)
            ->post('/api/auth/admin/suppliers', [
                'name' => 'Cocos del Pacífico',
                'company_id' => (string) $company->id,
                'is_active' => '1',
            ])
            ->assertCreated();

        $this->assertSame($company->id, (int) Supplier::where('name', 'Cocos del Pacífico')->value('company_id'));
    }

    public function test_editing_a_supplier_or_customer_never_moves_it_to_another_company(): void
    {
        $other = Company::factory()->create();
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Proveedor']);
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente']);

        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/suppliers/{$supplier->id}", ['name' => 'Proveedor 2', 'company_id' => $other->id])
            ->assertOk();
        $this->actingInBranch($this->user)
            ->putJson("/api/auth/admin/customers/{$customer->id}", ['name' => 'Cliente 2', 'company_id' => $other->id])
            ->assertOk();

        $this->assertSame($this->company->id, (int) $supplier->fresh()->company_id);
        $this->assertSame('Proveedor 2', $supplier->fresh()->name);
        $this->assertSame($this->company->id, (int) $customer->fresh()->company_id);
    }

    public function test_a_company_sent_as_a_list_is_still_checked(): void
    {
        $other = Company::factory()->create();
        // Un usuario de sucursal: no pertenece a la otra empresa.
        $clerk = $this->createBranchUser($this->location, ['suppliers_create', 'suppliers_list']);

        foreach ([[$other->id], (string) $other->id] as $companyId) {
            $this->actingInBranch($clerk)
                ->post('/api/auth/admin/suppliers', [
                    'name' => 'Intruso',
                    'company_id' => $companyId,
                    'is_active' => '1',
                ])
                ->assertForbidden();
        }

        $this->assertNull(Supplier::where('name', 'Intruso')->first());
    }

    public function test_a_real_social_reason_fits(): void
    {
        $reason = 'Cocos del Pacífico Sociedad Anónima de Capital Variable';

        $this->actingInBranch($this->user)
            ->post('/api/auth/admin/suppliers', [
                'name' => 'Cocos del Pacífico',
                'social_reason' => $reason,
                'is_active' => '1',
            ])
            ->assertCreated();

        $this->assertSame($reason, Supplier::where('name', 'Cocos del Pacífico')->value('social_reason'));
    }
}
