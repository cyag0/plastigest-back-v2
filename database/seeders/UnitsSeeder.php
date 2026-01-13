<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\UnitConversion;
use Illuminate\Database\Seeder;

class UnitsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Verificar si ya existen unidades
        if (Unit::count() > 0) {
            $this->command->warn('⚠️  Ya existen unidades en la base de datos');
            return;
        }

        // ========================================
        // UNIDADES DE CANTIDAD
        // ========================================
        $pieza = Unit::create([
            'name' => 'Pieza',
            'abbreviation' => 'pz',
            'type' => 'quantity',
            'is_base' => true,
            'factor_to_base' => 1, // Unidad base de cantidad
        ]);

        $caja = Unit::create([
            'name' => 'Decena',
            'abbreviation' => 'da',
            'type' => 'quantity',
            'factor_to_base' => 10, // 1 decena = 10 piezas
        ]);

        $docena = Unit::create([
            'name' => 'Docena',
            'abbreviation' => 'dz',
            'type' => 'quantity',
            'factor_to_base' => 12, // 1 docena = 12 piezas
        ]);


        // ========================================
        // UNIDADES DE MASA (PESO)
        // ========================================
        $kilogramo = Unit::create([
            'name' => 'Kilogramo',
            'abbreviation' => 'kg',
            'type' => 'mass',
            'is_base' => true,
            'factor_to_base' => 1, // Unidad base de masa
        ]);

        $gramo = Unit::create([
            'name' => 'Gramo',
            'abbreviation' => 'g',
            'type' => 'mass',
            'factor_to_base' => 0.001, // 1 g = 0.001 kg
        ]);

        $tonelada = Unit::create([
            'name' => 'Tonelada',
            'abbreviation' => 'ton',
            'type' => 'mass',
            'factor_to_base' => 1000, // 1 ton = 1000 kg
        ]);

        $miligramo = Unit::create([
            'name' => 'Miligramo',
            'abbreviation' => 'mg',
            'type' => 'mass',
            'factor_to_base' => 0.000001, // 1 mg = 0.000001 kg
        ]);

        $libra = Unit::create([
            'name' => 'Libra',
            'abbreviation' => 'lb',
            'type' => 'mass',
            'factor_to_base' => 0.453592, // 1 lb = 0.453592 kg
        ]);

        $onza = Unit::create([
            'name' => 'Onza',
            'abbreviation' => 'oz',
            'type' => 'mass',
            'factor_to_base' => 0.0283495, // 1 oz = 0.0283495 kg
        ]);

        // ========================================
        // UNIDADES DE VOLUMEN
        // ========================================
        $litro = Unit::create([
            'name' => 'Litro',
            'abbreviation' => 'L',
            'type' => 'volume',
            'is_base' => true,
            'factor_to_base' => 1, // Unidad base de volumen
        ]);

        $mililitro = Unit::create([
            'name' => 'Mililitro',
            'abbreviation' => 'ml',
            'type' => 'volume',
            'factor_to_base' => 0.001, // 1 ml = 0.001 L
        ]);

        $galon = Unit::create([
            'name' => 'Galón',
            'abbreviation' => 'gal',
            'type' => 'volume',
            'factor_to_base' => 3.78541, // 1 gal = 3.78541 L
        ]);


        $this->command->info('✅ Unidades y conversiones creadas exitosamente');
    }
}
