<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeder.
     *
     * Datos extraídos del archivo "Datos cocos fco .xlsx" (sección PROVEEDORES)
     * combinado con "PROVEEDORES.xlsx" (hojas con historial de pagos).
     */
    public function run(): void
    {
        $now = Carbon::now();
        $companies = DB::table('companies')->get();

        // Lista real de proveedores de Cocos Francisco.
        // Estructura: nombre, contacto (responsable), teléfono, categoría de producto,
        // email (cuando se conoce) y dirección (cuando aplica).
        $suppliersData = [
            // MATERIAS PRIMAS (cocos)
            [
                'name' => 'PROVEEDOR DE COCOS',
                'contact_name' => 'Encargado de compras',
                'phone' => null,
                'email' => null,
                'address' => 'Productores locales de coco',
                'category' => 'Materias Primas (Cocos)',
            ],

            // PET, DESECHABLES
            [
                'name' => 'LIDERPLAST',
                'contact_name' => 'Esteban',
                'phone' => '322 834 9228',
                'email' => null,
                'address' => null,
                'category' => 'PET, Desechables',
            ],
            [
                'name' => 'TESTUS PET SOLUTIONS',
                'contact_name' => 'Mayra Flores',
                'phone' => '322 858 1056',
                'email' => null,
                'address' => null,
                'category' => 'PET, Desechables',
            ],
            [
                'name' => 'DULCERIA MI CASITA',
                'contact_name' => 'Encargada',
                'phone' => '322 152 8782',
                'email' => null,
                'address' => null,
                'category' => 'PET, Desechables',
            ],
            [
                'name' => 'MAZAPLASTICOS',
                'contact_name' => 'José',
                'phone' => '669 932 3554',
                'email' => null,
                'address' => null,
                'category' => 'PET, Desechables',
            ],
            [
                'name' => 'PLASTICOS DENYS',
                'contact_name' => null,
                'phone' => null,
                'email' => null,
                'address' => null,
                'category' => 'PET, Desechables',
            ],

            // SEMILLAS Y CEREALES
            [
                'name' => 'LA BUENA SEMILLA',
                'contact_name' => 'Encargada',
                'phone' => '322 104 2557',
                'email' => null,
                'address' => null,
                'category' => 'Semillas y Cereales',
            ],
            [
                'name' => 'FRAGAQUIM',
                'contact_name' => null,
                'phone' => null,
                'email' => null,
                'address' => null,
                'category' => 'Semillas y Cereales',
            ],
            [
                'name' => 'AMEZCUA',
                'contact_name' => null,
                'phone' => null,
                'email' => null,
                'address' => null,
                'category' => 'Semillas y Cereales',
            ],

            // GALLETAS
            [
                'name' => 'PROV. GALLETAS',
                'contact_name' => null,
                'phone' => '322 227 4515',
                'email' => null,
                'address' => null,
                'category' => 'Galletas',
            ],
            [
                'name' => 'OSMAR ROMPOPE TALPA',
                'contact_name' => 'Osmar',
                'phone' => '331 280 8222',
                'email' => null,
                'address' => 'Talpa de Allende',
                'category' => 'Galletas',
            ],
            [
                'name' => 'SUSY ACEITE TECOMAN',
                'contact_name' => null,
                'phone' => null,
                'email' => null,
                'address' => 'Tecoman, Colima',
                'category' => 'Galletas',
            ],

            // IMPRESIONES
            [
                'name' => 'RUBEN GARIBAY OCHOA',
                'contact_name' => 'Rubén Garibay Ochoa',
                'phone' => '322 293 5077',
                'email' => null,
                'address' => 'Impresión digital - offset - gran formato - rotulación vehicular - corte vinil - letras 3D',
                'category' => 'Impresiones',
            ],
            [
                'name' => 'GRUPO ESPEJO',
                'contact_name' => 'Orlando',
                'phone' => '332 931 3300',
                'email' => null,
                'address' => null,
                'category' => 'Impresiones',
            ],

            // HIELO / AGUA
            [
                'name' => 'HIELO',
                'contact_name' => null,
                'phone' => '322 222 0750',
                'email' => null,
                'address' => null,
                'category' => 'Hielo / Agua',
            ],
            [
                'name' => 'AGUA LA BUENA',
                'contact_name' => 'Omar',
                'phone' => '322 157 0853',
                'email' => null,
                'address' => null,
                'category' => 'Hielo / Agua',
            ],

            // MAQUINARIA
            [
                'name' => 'MAQUINAS CDMX',
                'contact_name' => 'Javier',
                'phone' => '558 205 7691',
                'email' => null,
                'address' => 'Ciudad de México',
                'category' => 'Maquinaria',
            ],
            [
                'name' => 'MAQUINA CORTE DIAMANTE',
                'contact_name' => null,
                'phone' => null,
                'email' => null,
                'address' => null,
                'category' => 'Maquinaria',
            ],

            // TOSTADAS / DULCES DE COCO
            [
                'name' => 'TOSTADAS COCO',
                'contact_name' => 'Carla',
                'phone' => '322 199 0709',
                'email' => null,
                'address' => null,
                'category' => 'Tostadas / Dulces de coco',
            ],
            [
                'name' => 'ANA DULCE COCO',
                'contact_name' => 'Ana',
                'phone' => null,
                'email' => null,
                'address' => null,
                'category' => 'Tostadas / Dulces de coco',
            ],

            // PUBLICIDAD
            [
                'name' => 'JOVANA RAMIREZ',
                'contact_name' => 'Jovana Ramírez',
                'phone' => null,
                'email' => null,
                'address' => null,
                'category' => 'Publicidad',
            ],
        ];

        foreach ($companies as $company) {
            foreach ($suppliersData as $supplierData) {
                DB::table('suppliers')->insert([
                    'company_id' => $company->id,
                    'name' => $supplierData['name'],
                    'business_name' => null,
                    'rfc' => null,
                    'email' => $supplierData['email'],
                    'phone' => $supplierData['phone'],
                    'address' => $supplierData['address'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->command->info('✅ ' . count($suppliersData) . ' proveedores reales de Cocos Francisco creados para ' . $companies->count() . ' compañía(s)');
    }
}
