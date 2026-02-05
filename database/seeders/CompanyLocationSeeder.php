<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CompanyLocationSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        $now = Carbon::now();

        // Crear 2 compañías
        $companies = [
            [
                'name' => 'Cocos Francisco',
                'business_name' => 'Cocos Francisco S.A. de C.V.',
                'rfc' => 'CFR240101ABC',
                'email' => 'contacto@cocosfrancisco.com',
                'phone' => '5551234567',
                'address' => 'Av. Principal #123, Col. Centro',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Jara',
                'business_name' => 'Jara Comercializadora S.A. de C.V.',
                'rfc' => 'JAR240101XYZ',
                'email' => 'info@jara.com',
                'phone' => '5559876543',
                'address' => 'Blvd. Reforma #456, Col. Juárez',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($companies as $companyData) {
            $companyId = DB::table('companies')->insertGetId($companyData);

            // Crear 4 locaciones para cada compañía
            $locations = [
                [
                    'company_id' => $companyId,
                    'name' => 'Sucursal Centro',
                    'description' => 'Sucursal principal en el centro de la ciudad',
                    'address' => 'Calle Principal #100',
                    'city' => 'Ciudad de México',
                    'state' => 'CDMX',
                    'country' => 'México',
                    'postal_code' => '02000',
                    'phone' => '5551111111',
                    'email' => 'centro@' . strtolower(str_replace(' ', '', $companyData['name'])) . '.com',
                    'is_active' => true,
                    'is_warehouse' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'company_id' => $companyId,
                    'name' => 'Sucursal Norte',
                    'description' => 'Sucursal ubicada en la zona norte',
                    'address' => 'Av. Norte #200',
                    'city' => 'Monterrey',
                    'state' => 'Nuevo León',
                    'country' => 'México',
                    'postal_code' => '64000',
                    'phone' => '5552222222',
                    'email' => 'norte@' . strtolower(str_replace(' ', '', $companyData['name'])) . '.com',
                    'is_active' => true,
                    'is_warehouse' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'company_id' => $companyId,
                    'name' => 'Sucursal Sur',
                    'description' => 'Sucursal ubicada en la zona sur',
                    'address' => 'Calle Sur #300',
                    'city' => 'Guadalajara',
                    'state' => 'Jalisco',
                    'country' => 'México',
                    'postal_code' => '44100',
                    'phone' => '5553333333',
                    'email' => 'sur@' . strtolower(str_replace(' ', '', $companyData['name'])) . '.com',
                    'is_active' => true,
                    'is_warehouse' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'company_id' => $companyId,
                    'name' => 'Almacén Central',
                    'description' => 'Almacén principal de distribución',
                    'address' => 'Zona Industrial #400',
                    'city' => 'Tijuana',
                    'state' => 'Baja California',
                    'country' => 'México',
                    'postal_code' => '22000',
                    'phone' => '5554444444',
                    'email' => 'almacen@' . strtolower(str_replace(' ', '', $companyData['name'])) . '.com',
                    'is_active' => true,
                    'is_warehouse' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];

            foreach ($locations as $location) {
                DB::table('locations')->insert($location);
            }
        }

        $this->command->info('✅ Compañías y locaciones creadas exitosamente');
    }
}
