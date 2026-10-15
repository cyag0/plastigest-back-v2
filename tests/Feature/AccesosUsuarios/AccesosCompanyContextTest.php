<?php

namespace Tests\Feature\AccesosUsuarios;

use App\Http\Controllers\CategoryController;
use App\Models\Admin\Customer;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Sin X-Company-ID (o con una empresa que no existe) la API trabajaba sin
 * filtro de empresa: bastaba quitar el header para listar los clientes,
 * productos o ventas de otra empresa.
 */
class AccesosCompanyContextTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant();
    }

    private function category(int $companyId, string $name): void
    {
        DB::table('categories')->insert([
            'name' => $name, 'company_id' => $companyId, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Usuario que pertenece a dos empresas, asignado a una sucursal de cada una. */
    private function twoCompanyUser(): array
    {
        [$otherCompany, $otherBranch] = $this->createCompanyWithBranch();
        $user = $this->createUserWithRole($this->location, ['categories_list']);
        DB::table('user_company')->insert(['user_id' => $user->id, 'company_id' => $otherCompany->id]);
        DB::table('user_location_roles')->insert([
            'user_id' => $user->id,
            'location_id' => $otherBranch->id,
            'role_id' => DB::table('user_location_roles')->where('user_id', $user->id)->value('role_id'),
        ]);

        return [$user, $otherCompany, $otherBranch];
    }

    public function test_without_the_header_a_single_company_user_works_in_their_company(): void
    {
        [$otherCompany] = $this->createCompanyWithBranch();
        $this->category($this->company->id, 'Propia');
        $this->category($otherCompany->id, 'Ajena');
        $cashier = $this->createBranchUser($this->location, ['categories_list']);

        $names = collect($this->actingInBranch($cashier)
            ->flushHeaders()->withHeaders($this->contextHeaders(null, $this->location))
            ->getJson('/api/auth/admin/categories?per_page=50')
            ->assertOk()->json('data'))->pluck('name');

        $this->assertContains('Propia', $names);
        $this->assertNotContains('Ajena', $names);
    }

    public function test_a_client_company_id_does_not_widen_the_scope(): void
    {
        [$otherCompany] = $this->createCompanyWithBranch();
        Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente propio']);
        Customer::create(['company_id' => $otherCompany->id, 'name' => 'Cliente ajeno']);
        $cashier = $this->createBranchUser($this->location, ['customers_list']);

        // La prueba del auditor: sin X-Company-ID y con ?company_id de otra empresa.
        $names = collect($this->actingInBranch($cashier)
            ->flushHeaders()->withHeaders($this->contextHeaders(null, $this->location))
            ->getJson("/api/auth/admin/customers?per_page=50&company_id={$otherCompany->id}")
            ->assertOk()->json('data'))->pluck('name');

        $this->assertNotContains('Cliente ajeno', $names);
    }

    public function test_without_the_header_a_user_of_several_companies_must_choose(): void
    {
        [$user] = $this->twoCompanyUser();
        $noCompany = $this->contextHeaders(null, null);

        $this->actingInBranch($user)->flushHeaders()->withHeaders($noCompany)
            ->getJson('/api/auth/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'company_required');

        // Lo que la app pide antes de elegir empresa sigue funcionando.
        $this->actingInBranch($user)->flushHeaders()->withHeaders($noCompany)->getJson('/api/auth/me')->assertOk();
        $this->actingInBranch($user)->flushHeaders()->withHeaders($noCompany)->getJson('/api/auth/admin/companies')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingInBranch($user)->flushHeaders()->withHeaders($noCompany)
            ->getJson('/api/auth/admin/notifications/unread-count')
            ->assertOk()->assertJsonPath('data.count', 0);
        $this->actingInBranch($user)->flushHeaders()->withHeaders($noCompany)->getJson('/api/auth/admin/device-tokens')->assertOk();
    }

    public function test_an_unknown_company_is_rejected(): void
    {
        $headers = ['X-Company-ID' => '999999', 'X-Location-ID' => (string) $this->location->id, 'Accept' => 'application/json'];

        $this->actingInBranch($this->user)->flushHeaders()->withHeaders($headers)
            ->getJson('/api/auth/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'company_forbidden');

        // La sesión no depende de la empresa.
        $this->actingInBranch($this->user)->flushHeaders()->withHeaders($headers)->getJson('/api/auth/me')->assertOk();
    }

    public function test_the_branch_must_belong_to_the_company(): void
    {
        [, $foreignBranch] = $this->createCompanyWithBranch();
        // Administrador con todos los permisos (system_admin incluido) y una
        // sola empresa: sin header se usa la suya, y la sucursal de otra
        // empresa no vale ni para él.
        $admin = $this->user;

        $this->actingInBranch($admin)->flushHeaders()->withHeaders($this->contextHeaders(null, $foreignBranch))
            ->getJson('/api/auth/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'location_forbidden');

        $this->actingInBranch($admin)->flushHeaders()->withHeaders($this->contextHeaders($this->company, $foreignBranch))
            ->getJson('/api/auth/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'location_forbidden');
    }

    public function test_an_unknown_branch_is_rejected(): void
    {
        $headers = $this->contextHeaders($this->company, 999999);

        $this->actingInBranch($this->user)->flushHeaders()->withHeaders($headers)
            ->getJson('/api/auth/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'location_forbidden');

        $this->actingInBranch($this->user)->flushHeaders()->withHeaders($headers)->getJson('/api/auth/me')->assertOk();
        $this->actingInBranch($this->user)->flushHeaders()->withHeaders($headers)->getJson('/api/auth/admin/companies')->assertOk();
    }

    public function test_public_routes_do_not_need_a_company(): void
    {
        [$user] = $this->twoCompanyUser();

        // Un archivo público con sesión y sin empresa: 404 (no existe), no 403.
        $this->actingInBranch($user)->flushHeaders()->withHeaders($this->contextHeaders(null, null))
            ->getJson('/api/auth/files/no-existe.png')
            ->assertNotFound();
    }

    public function test_crud_queries_return_nothing_without_a_company(): void
    {
        $this->category($this->company->id, 'Propia');
        $controller = new class extends CategoryController {
            public function query()
            {
                return $this->scopedQuery();
            }
        };

        CurrentCompany::clear();
        $this->assertSame(0, $controller->query()->count());

        CurrentCompany::set($this->company);
        $this->assertSame(1, $controller->query()->count());
    }

    public function test_crud_store_is_rejected_without_a_company(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        CurrentCompany::clear();
        CurrentLocation::set($this->location);

        $response = app(CategoryController::class)->store(Request::create('/', 'POST', [
            'name' => 'Sin empresa', 'company_id' => $this->company->id,
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('company_required', $response->getData(true)['code']);
        $this->assertDatabaseMissing('categories', ['name' => 'Sin empresa']);
    }
}
