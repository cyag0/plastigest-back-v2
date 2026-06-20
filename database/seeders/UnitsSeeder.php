<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Fuente de verdad de las unidades del sistema. El motor de inventario
     * (MovementService, etc.) convierte usando units.factor_to_base, por eso
     * cada unidad define su unit_type, is_base_unit y factor_to_base.
     */
    public function run(): void
    {
        // Verificar si ya existen unidades
        if (Unit::count() > 0) {
            $this->command->warn('⚠️  Ya existen unidades en la base de datos');
            return;
        }

        DB::table('units')->insert([
            // Cantidad
            ['name' => 'Pieza', 'abbreviation' => 'pz', 'unit_type' => 'quantity', 'is_base_unit' => true, 'company_id' => null, 'factor_to_base' => 1.000000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Decena', 'abbreviation' => 'da', 'unit_type' => 'quantity', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 10.000000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Docena', 'abbreviation' => 'dz', 'unit_type' => 'quantity', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 12.000000, 'created_at' => now(), 'updated_at' => now()],

            // Masa
            ['name' => 'Kilogramo', 'abbreviation' => 'kg', 'unit_type' => 'mass', 'is_base_unit' => true, 'company_id' => null, 'factor_to_base' => 1.000000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Gramo', 'abbreviation' => 'g', 'unit_type' => 'mass', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 0.001000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Tonelada', 'abbreviation' => 'ton', 'unit_type' => 'mass', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 1000.000000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Miligramo', 'abbreviation' => 'mg', 'unit_type' => 'mass', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 0.000001, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Libra', 'abbreviation' => 'lb', 'unit_type' => 'mass', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 0.453592, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Onza', 'abbreviation' => 'oz', 'unit_type' => 'mass', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 0.028350, 'created_at' => now(), 'updated_at' => now()],

            // Volumen
            ['name' => 'Litro', 'abbreviation' => 'L', 'unit_type' => 'volume', 'is_base_unit' => true, 'company_id' => null, 'factor_to_base' => 1.000000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Mililitro', 'abbreviation' => 'ml', 'unit_type' => 'volume', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 0.001000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Galón', 'abbreviation' => 'gal', 'unit_type' => 'volume', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 3.785410, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->command->info('✅ Unidades creadas exitosamente');
    }
}
