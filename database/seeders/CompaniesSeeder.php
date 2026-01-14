<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use Illuminate\Database\Seeder;

class CompaniesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Desactivar restricciones de foreign keys temporalmente
        \DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        
        // Limpiar datos anteriores
        Location::query()->delete();
        Company::query()->delete();

        // Crear compañía Cocos Francisco
        $company = Company::create([
            'name' => 'Cocos Francisco',
            'business_name' => 'Cocos Francisco Distribuidora S.A. de C.V.',
            'rfc' => 'CFD987654321',
            'email' => 'ventas@cocosfrancisco.com',
            'phone' => '+52 322 123 4567',
            'address' => 'Puerto Vallarta, Jalisco',
            'is_active' => true,
        ]);

        // Crear sucursales de Cocos Francisco
        $locations = [
            [
                'name' => 'Cocos Francisco Matriz',
                'address' => 'Av. Francisco Villa 100, Centro, Puerto Vallarta, Jalisco, 48300',
                'phone' => '+52 322 123 4567',
                'is_main' => true,
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Lija',
                'address' => 'Calle Lija 50, Col. Las Juntas, Puerto Vallarta, Jalisco, 48350',
                'phone' => '+52 322 123 4568',
                'is_main' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Volcanes',
                'address' => 'Av. Los Volcanes 200, Versalles, Puerto Vallarta, Jalisco, 48310',
                'phone' => '+52 322 123 4569',
                'is_main' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Mojoneras',
                'address' => 'Carretera a Las Mojoneras Km 5, Puerto Vallarta, Jalisco, 48315',
                'phone' => '+52 322 123 4570',
                'is_main' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Pitillal',
                'address' => 'Av. Francisco Villa 500, Pitillal, Puerto Vallarta, Jalisco, 48290',
                'phone' => '+52 322 123 4571',
                'is_main' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Ixtapa',
                'address' => 'Boulevard Ixtapa 75, Ixtapa, Puerto Vallarta, Jalisco, 48280',
                'phone' => '+52 322 123 4572',
                'is_main' => false,
                'is_active' => true,
            ],
        ];

        foreach ($locations as $locationData) {
            Location::create(array_merge($locationData, ['company_id' => $company->id]));
        }

        // Reactivar restricciones de foreign keys
        \DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->info('Compañía y 6 sucursales de Cocos Francisco creadas exitosamente');
    }
}
