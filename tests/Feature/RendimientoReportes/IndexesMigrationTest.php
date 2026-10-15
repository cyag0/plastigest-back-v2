<?php

namespace Tests\Feature\RendimientoReportes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Los índices de las pantallas frecuentes se crean en una base nueva y la
 * migración se puede revertir sin dejar llaves foráneas sin índice. La prueba
 * cambia el esquema y lo deja como estaba; no crea datos (el DDL de MySQL
 * confirma la transacción de la prueba).
 */
class IndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_INDEXES = [
        'sales' => ['sales_company_date_index', 'sales_location_company_date_cover_index'],
        'sales_details' => ['sales_details_sale_product_cover_index'],
        'cash_movements' => [
            'cash_movements_location_company_date_cover_index',
            'cash_movements_company_created_index',
            'cash_movements_source_id_source_type_index',
        ],
        'stock_ledger_entries' => ['idx_ledger_company_location_product_date', 'idx_ledger_company_reason_date'],
    ];

    private const REPLACED_INDEXES = [
        'sales' => ['sales_company_id_location_id_status_index', 'sales_sale_date_index'],
        'sales_details' => ['sales_details_sale_id_product_id_index'],
        'cash_movements' => ['cash_movements_company_id_location_id_movement_date_index', 'cash_movements_source_type_source_id_index'],
    ];

    private function indexes(string $table): array
    {
        return collect(Schema::getIndexes($table))->pluck('name')->all();
    }

    private function assertIndexes(array $expected, array $absent): void
    {
        foreach ($expected as $table => $names) {
            foreach ($names as $name) {
                $this->assertContains($name, $this->indexes($table), "{$table}.{$name}");
            }
        }
        foreach ($absent as $table => $names) {
            foreach ($names as $name) {
                $this->assertNotContains($name, $this->indexes($table), "{$table}.{$name}");
            }
        }
    }

    public function test_los_indices_existen_y_la_migracion_se_puede_revertir(): void
    {
        $this->assertIndexes(self::NEW_INDEXES, self::REPLACED_INDEXES);

        $migration = require database_path('migrations/2026_10_15_220000_add_indexes_for_frequent_screens.php');

        $migration->down();
        $this->assertIndexes(self::REPLACED_INDEXES, self::NEW_INDEXES);

        $migration->up();
        $this->assertIndexes(self::NEW_INDEXES, self::REPLACED_INDEXES);
    }
}
