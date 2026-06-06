<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompaniesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Sucursales obtenidas del archivo "Datos cocos fco .xlsx" (sección SUCURSALES).
     */
    public function run(): void
    {
        // Desactivar restricciones de foreign keys temporalmente
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

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

        // Sucursales reales (orden de aparición en la hoja SUCURSALES del excel)
        $locations = [
            [
                'name' => 'Cocos Francisco Sucursal Matriz',
                'address' => 'Av. Las Torres #220A, Puerto Vallarta, Jalisco',
                'phone' => '+52 322 123 4567',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco La Lija',
                'address' => 'Cuba #701, Col. La Lija, Puerto Vallarta, Jalisco',
                'phone' => '+52 322 123 4568',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Pitillal',
                'address' => 'Abasolo #242A, El Pitillal, Puerto Vallarta, Jalisco',
                'phone' => '+52 322 123 4569',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Volcanes',
                'address' => 'Carboneras y Habana #492, Av. Víctor Iturbe, Col. Volcanes, Puerto Vallarta, Jalisco',
                'phone' => '+52 322 123 4570',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Ixtapa',
                'address' => 'Carr. a las Palmas #2101-C, Niños Héroes, Ixtapa, Puerto Vallarta, Jalisco',
                'phone' => '+52 322 123 4571',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Mojoneras',
                'address' => 'Av. México #210, Las Mojoneras, Puerto Vallarta, Jalisco',
                'phone' => '+52 322 123 4572',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Mezcales',
                'address' => 'Carretera Mezcales - Colomo #213, Col. El Manguito, Mezcales, Nayarit',
                'phone' => '+52 322 123 4573',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Guayabitos',
                'address' => 'Guayabitos, Nayarit',
                'phone' => '+52 322 123 4574',
                'is_active' => true,
            ],
        ];

        foreach ($locations as $locationData) {
            Location::create(array_merge($locationData, ['company_id' => $company->id]));
        }

        // Reactivar restricciones de foreign keys
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->info('Compañía Cocos Francisco y ' . count($locations) . ' sucursales reales creadas exitosamente');
    }
}
