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
        $hasData = Company::query()->exists() || Location::query()->exists();

        // Fuera de local/testing nunca se borran datos: si ya hay empresas o
        // sucursales, se aborta. En una BD vacía sí crea la empresa y sus
        // sucursales reales (útil en la primera instalación).
        if ($hasData && !app()->environment('local', 'testing')) {
            $this->command->error(
                'CompaniesSeeder: ya existen empresas o sucursales. En "' . app()->environment()
                . '" este seeder no borra datos; no se hizo nada.'
            );

            return;
        }

        // Desactivar restricciones de foreign keys temporalmente
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Limpiar datos anteriores (solo llega aquí en local/testing o con la BD vacía)
        Location::query()->delete();
        Company::query()->delete();

        // Crear compañía Cocos Francisco. Razón social, RFC, correo y
        // teléfonos van vacíos: salen en los tickets y no hay datos fiscales
        // verificados. El dueño los captura en Administración → Empresa y en
        // cada sucursal (el ticket omite lo que esté vacío).
        $company = Company::create([
            'name' => 'Cocos Francisco',
            'business_name' => null,
            'rfc' => null,
            'email' => null,
            'phone' => null,
            'address' => 'Puerto Vallarta, Jalisco',
            'is_active' => true,
        ]);

        // Sucursales reales (orden de aparición en la hoja SUCURSALES del excel)
        $locations = [
            [
                'name' => 'Cocos Francisco Sucursal Matriz',
                'address' => 'Av. Las Torres #220A, Puerto Vallarta, Jalisco',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco La Lija',
                'address' => 'Cuba #701, Col. La Lija, Puerto Vallarta, Jalisco',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Pitillal',
                'address' => 'Abasolo #242A, El Pitillal, Puerto Vallarta, Jalisco',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Volcanes',
                'address' => 'Carboneras y Habana #492, Av. Víctor Iturbe, Col. Volcanes, Puerto Vallarta, Jalisco',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Ixtapa',
                'address' => 'Carr. a las Palmas #2101-C, Niños Héroes, Ixtapa, Puerto Vallarta, Jalisco',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Mojoneras',
                'address' => 'Av. México #210, Las Mojoneras, Puerto Vallarta, Jalisco',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Mezcales',
                'address' => 'Carretera Mezcales - Colomo #213, Col. El Manguito, Mezcales, Nayarit',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco Guayabitos',
                'address' => 'Guayabitos, Nayarit',
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
