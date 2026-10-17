<?php

namespace Tests\Feature\Flows;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use Database\Seeders\CompaniesSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ResourceSeeder;
use Database\Seeders\UnitsSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Datos de la empresa y de la sucursal: los límites son los de las columnas y
 * el RFC se acepta como lo escribe la gente (minúsculas, Ñ, &) o vacío.
 */
class CompanyLocationValidationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Location $matriz;
    private string $token;

    private const ADMIN_PASSWORD = 'Clave-del-dueno-2026';

    protected function setUp(): void
    {
        parent::setUp();

        putenv('ADMIN_PASSWORD=' . self::ADMIN_PASSWORD);
        $_ENV['ADMIN_PASSWORD'] = $_SERVER['ADMIN_PASSWORD'] = self::ADMIN_PASSWORD;
        $this->beforeApplicationDestroyed(function () {
            putenv('ADMIN_PASSWORD');
            unset($_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_PASSWORD']);
        });

        $this->seed([ResourceSeeder::class, PermissionSeeder::class, UnitsSeeder::class, CompaniesSeeder::class, UserSeeder::class]);

        $this->company = Company::firstOrFail();
        $this->matriz = Location::where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $this->app['auth']->shouldUse('web');
        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'admin@cocos-francisco.com',
            'password' => self::ADMIN_PASSWORD,
        ])->assertOk()->json('access_token');
    }

    private function api(): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Company-ID' => (string) $this->company->id,
            'X-Location-ID' => (string) $this->matriz->id,
            'Accept' => 'application/json',
        ]);
    }

    private function updateCompany(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->api()->putJson("/api/auth/admin/companies/{$this->company->id}", array_merge([
            'name' => $this->company->name,
        ], $data));
    }

    public function test_a_business_without_rfc_can_save_its_address(): void
    {
        $this->updateCompany(['address' => 'Av. México 210, Puerto Vallarta'])
            ->assertOk()
            ->assertJsonPath('data.address', 'Av. México 210, Puerto Vallarta');

        $this->assertNull($this->company->fresh()->rfc);
    }

    public function test_rfc_is_normalized_and_accepts_enie_and_ampersand(): void
    {
        $this->updateCompany(['rfc' => 'cfr-010101 ab1'])->assertOk()->assertJsonPath('data.rfc', 'CFR010101AB1');
        $this->updateCompany(['rfc' => 'ÑA&010101AB1'])->assertOk()->assertJsonPath('data.rfc', 'ÑA&010101AB1');
        $this->updateCompany(['rfc' => 'peña800101ab1'])->assertOk()->assertJsonPath('data.rfc', 'PEÑA800101AB1');
    }

    public function test_an_invalid_rfc_explains_the_format(): void
    {
        $this->updateCompany(['rfc' => 'ABC12'])
            ->assertStatus(422)
            ->assertJsonPath('errors.rfc.0', 'Revisa el RFC: son 12 caracteres si es empresa o 13 si es persona física.');
    }

    public function test_company_limits_match_the_columns(): void
    {
        $this->updateCompany(['name' => str_repeat('a', 151)])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->updateCompany(['address' => str_repeat('a', 256)])->assertStatus(422)->assertJsonValidationErrors('address');
        $this->updateCompany(['email' => str_repeat('a', 96) . '@x.mx'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->updateCompany(['name' => str_repeat('a', 150)])->assertOk();
    }

    public function test_location_limits_match_the_columns(): void
    {
        $base = ['name' => $this->matriz->name, 'address' => 'Av. Las Torres 220', 'company_id' => $this->company->id];

        $this->api()->putJson("/api/auth/admin/locations/{$this->matriz->id}", array_merge($base, ['name' => str_repeat('a', 151)]))
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->api()->putJson("/api/auth/admin/locations/{$this->matriz->id}", array_merge($base, ['address' => str_repeat('a', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors('address');
        $this->api()->putJson("/api/auth/admin/locations/{$this->matriz->id}", array_merge($base, ['email' => str_repeat('a', 96) . '@x.mx']))
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->api()->putJson("/api/auth/admin/locations/{$this->matriz->id}", array_merge($base, ['address' => str_repeat('a', 255)]))
            ->assertOk();
    }
}
