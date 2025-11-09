<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\Admin\Company;
use Illuminate\Database\Seeder;

class UnitsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener la primera compañía
        $company = Company::first();

        if (!$company) {
            $this->command->error('No se encontró ninguna compañía. Ejecuta primero el seeder de compañías.');
            return;
        }

        // Unidades de Cantidad (quantity)
        Unit::create([
            'name' => 'Pieza',
            'symbol' => 'pz',
            'description' => 'Unidad individual',
            'type' => 'quantity',
            'is_base' => true,
            'conversion_rate' => 1.000000,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        Unit::create([
            'name' => 'Caja',
            'symbol' => 'cj',
            'description' => '12 piezas por caja',
            'type' => 'quantity',
            'is_base' => false,
            'conversion_rate' => 12.000000,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        // Unidades de Longitud (length)
        Unit::create([
            'name' => 'Metro',
            'symbol' => 'm',
            'description' => 'Unidad base de longitud',
            'type' => 'length',
            'is_base' => true,
            'conversion_rate' => 1.000000,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        Unit::create([
            'name' => 'Centímetro',
            'symbol' => 'cm',
            'description' => '0.01 metros',
            'type' => 'length',
            'is_base' => false,
            'conversion_rate' => 0.010000,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        // Unidades de Peso (weight)
        Unit::create([
            'name' => 'Kilogramo',
            'symbol' => 'kg',
            'description' => 'Unidad base de peso',
            'type' => 'weight',
            'is_base' => true,
            'conversion_rate' => 1.000000,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        Unit::create([
            'name' => 'Gramo',
            'symbol' => 'g',
            'description' => '0.001 kilogramos',
            'type' => 'weight',
            'is_base' => false,
            'conversion_rate' => 0.001000,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        // Unidades de Volumen (volume)
        Unit::create([
            'name' => 'Litro',
            'symbol' => 'L',
            'description' => 'Unidad base de volumen',
            'type' => 'volume',
            'is_base' => true,
            'conversion_rate' => 1.000000,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        Unit::create([
            'name' => 'Mililitro',
            'symbol' => 'ml',
            'description' => '0.001 litros',
            'type' => 'volume',
            'is_base' => false,
            'conversion_rate' => 0.001000,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $this->command->info('Unidades creadas exitosamente!');
    }
}
