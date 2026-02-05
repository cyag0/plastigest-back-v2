<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $companies = DB::table('companies')->get();

        $suppliersData = [
            [
                'name' => 'Distribuidora Nacional',
                'phone' => '5551112233',
                'email' => 'ventas@distribuidoranacional.com',
                'address' => 'Av. Industria #100, CDMX',
            ],
            [
                'name' => 'Proveedora del Centro',
                'phone' => '5552223344',
                'email' => 'contacto@proveedoradelcentro.com',
                'address' => 'Calle Comercio #200, Monterrey',
            ],
            [
                'name' => 'Importadora Global',
                'phone' => '5553334455',
                'email' => 'info@importadoraglobal.com',
                'address' => 'Blvd. Internacional #300, Guadalajara',
            ],
            [
                'name' => 'Mayorista del Pacífico',
                'phone' => '5554445566',
                'email' => 'ventas@mayoristadelpacifico.com',
                'address' => 'Zona Industrial #400, Tijuana',
            ],
            [
                'name' => 'Comercializadora del Norte',
                'phone' => '5555556677',
                'email' => 'contacto@comercializadoranorte.com',
                'address' => 'Parque Industrial #500, Hermosillo',
            ],
        ];

        foreach ($companies as $company) {
            foreach ($suppliersData as $supplierData) {
                DB::table('suppliers')->insert([
                    'company_id' => $company->id,
                    'name' => $supplierData['name'],
                    'email' => $supplierData['email'],
                    'phone' => $supplierData['phone'],
                    'address' => $supplierData['address'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->command->info('✅ Proveedores creados exitosamente');
    }
}
