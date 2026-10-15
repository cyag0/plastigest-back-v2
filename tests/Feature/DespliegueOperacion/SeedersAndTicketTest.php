<?php

namespace Tests\Feature\DespliegueOperacion;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use Database\Seeders\CategoriesSeeder;
use Database\Seeders\CompaniesSeeder;
use Database\Seeders\FormulasSeeder;
use Database\Seeders\InitialCatalogSeeder;
use Database\Seeders\PackageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProductsSeeder;
use Database\Seeders\ResourceSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * Primera instalación en producción: la empresa se crea sin datos fiscales
 * inventados (el ticket omite lo vacío) y los seeders del catálogo sueltos no
 * pisan ni borran nada fuera de InitialCatalogSeeder.
 */
class SeedersAndTicketTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    private function seedReference(): void
    {
        $this->seed([ResourceSeeder::class, PermissionSeeder::class, UnitsSeeder::class, CompaniesSeeder::class]);
    }

    public function test_la_empresa_y_las_sucursales_se_crean_sin_rfc_ni_telefonos_inventados(): void
    {
        $this->seedReference();

        $company = Company::where('name', 'Cocos Francisco')->firstOrFail();
        $this->assertNull($company->rfc);
        $this->assertNull($company->business_name);
        $this->assertNull($company->phone);
        $this->assertNull($company->email);

        $branches = Location::where('company_id', $company->id)->get();
        $this->assertCount(8, $branches);
        $this->assertTrue($branches->every(fn($branch) => $branch->phone === null));
        // Las direcciones sí son reales (hoja SUCURSALES).
        $this->assertTrue($branches->every(fn($branch) => filled($branch->address)));
    }

    public function test_la_empresa_sin_rfc_se_puede_abrir_para_editar(): void
    {
        $this->createTenant();
        $this->company->update(['rfc' => null, 'business_name' => null, 'email' => null, 'phone' => null]);

        $this->getJson("/api/auth/admin/companies/{$this->company->id}", $this->tenantHeaders())
            ->assertOk()
            ->assertJsonPath('data.rfc', null);
    }

    public function test_el_ticket_omite_los_datos_vacios_de_la_empresa_y_la_sucursal(): void
    {
        $render = fn(Company $company, Location $location) => view('pdf.ticket-layout', [
            'company' => $company,
            'location' => $location,
            'paperWidthMm' => 80,
        ])->render();

        $empty = $render(
            new Company(['name' => 'Cocos Francisco', 'business_name' => null, 'rfc' => null]),
            new Location(['name' => 'Sucursal Matriz', 'address' => null, 'phone' => null]),
        );
        $this->assertStringContainsString('Cocos Francisco', $empty);
        $this->assertStringContainsString('Sucursal Matriz', $empty);
        $this->assertStringNotContainsString('RFC:', $empty);
        $this->assertStringNotContainsString('Tel.', $empty);

        $filled = $render(
            new Company(['name' => 'Cocos Francisco', 'business_name' => 'Cocos Francisco SA de CV', 'rfc' => 'CFR010101AB1']),
            new Location(['name' => 'Sucursal Matriz', 'address' => 'Av. Las Torres 220A', 'phone' => '322 111 2233']),
        );
        $this->assertStringContainsString('RFC: CFR010101AB1', $filled);
        $this->assertStringContainsString('Cocos Francisco SA de CV', $filled);
        $this->assertStringContainsString('Tel. 322 111 2233', $filled);
    }

    public function test_en_produccion_los_seeders_del_catalogo_solo_corren_por_initial_catalog_seeder(): void
    {
        $this->seedReference();
        $this->app['env'] = 'production';

        foreach ([CategoriesSeeder::class, SupplierSeeder::class, ProductsSeeder::class, PackageSeeder::class, FormulasSeeder::class] as $seeder) {
            $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])
                ->expectsOutputToContain('solo se corre a través de InitialCatalogSeeder')
                ->assertSuccessful();
        }
        $this->assertSame(0, DB::table('categories')->count());
        $this->assertSame(0, DB::table('suppliers')->count());
        $this->assertSame(0, DB::table('products')->count());

        // El camino documentado en DEPLOY-COOLIFY.md sí carga el catálogo.
        $this->artisan('db:seed', ['--class' => InitialCatalogSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertGreaterThan(50, DB::table('products')->count());
        $this->assertGreaterThan(0, DB::table('suppliers')->count());

        // Después de él, uno suelto vuelve a negarse.
        $this->artisan('db:seed', ['--class' => ProductsSeeder::class, '--force' => true])
            ->expectsOutputToContain('solo se corre a través de InitialCatalogSeeder')
            ->assertSuccessful();
    }
}
