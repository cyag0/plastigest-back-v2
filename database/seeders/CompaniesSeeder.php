<?php

namespace Database\Seeders;

use App\Models\Admin\Company;
use Illuminate\Database\Seeder;

class CompaniesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear las dos compañías principales
        $companies = [
            [
                'name' => 'Jara',
                'business_name' => 'Jara Productos Plásticos S.A. de C.V.',
                'rfc' => 'JPP123456789',
                'email' => 'contacto@jara.com',
                'phone' => '+52 55 1234 5678',
                'address' => 'Av. Industrial 123, Col. Zona Industrial, Ciudad de México',
                'is_active' => true,
            ],
            [
                'name' => 'Cocos Francisco',
                'business_name' => 'Cocos Francisco Distribuidora S.A. de C.V.',
                'rfc' => 'CFD987654321',
                'email' => 'ventas@cocosfrancisco.com',
                'phone' => '+52 55 9876 5432',
                'address' => 'Carretera Nacional Km 45, Zona Agrícola, Estado de México',
                'is_active' => true,
            ],
        ];

        foreach ($companies as $companyData) {
            Company::firstOrCreate(
                ['rfc' => $companyData['rfc']], // Buscar por RFC único
                $companyData
            );
        }

        $this->command->info('Compañías creadas exitosamente: Jara y Cocos Francisco');
    }
}
