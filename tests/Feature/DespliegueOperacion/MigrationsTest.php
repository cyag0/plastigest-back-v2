<?php

namespace Tests\Feature\DespliegueOperacion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migraciones que deben poder revertirse en una base instalada desde cero
 * (la de producción). Las pruebas cambian el esquema y lo dejan como estaba;
 * no crean datos (el DDL de MySQL confirma la transacción de la prueba).
 */
class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function productIndexes(): array
    {
        return collect(Schema::getIndexes('products'))->pluck('name')->all();
    }

    public function test_el_codigo_unico_por_empresa_se_puede_revertir_en_una_base_nueva(): void
    {
        $migration = require database_path('migrations/2026_09_25_000001_make_product_code_unique_per_company.php');

        // En una base nueva la FK de company_id usa el índice compuesto:
        // MySQL no dejaba borrarlo y el rollback tronaba.
        $migration->down();
        $this->assertContains('products_code_unique', $this->productIndexes());
        $this->assertNotContains('products_company_code_unique', $this->productIndexes());

        $migration->up();
        $this->assertContains('products_company_code_unique', $this->productIndexes());
        $this->assertNotContains('products_code_unique', $this->productIndexes());

        // Y se puede repetir (un segundo rollback no duplica índices).
        $migration->down();
        $migration->up();
        $this->assertContains('products_company_code_unique', $this->productIndexes());
    }
}
