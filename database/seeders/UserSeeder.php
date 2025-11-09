<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Company;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear empresa de prueba
        $company = Company::firstOrCreate(
            ['name' => 'PlastiGest Demo'],
            [
                'business_name' => 'PlastiGest Demo S.A. de C.V.',
                'rfc' => '12345678901',
                'email' => 'demo@plastigest.com',
                'phone' => '1234567890',
                'address' => 'Calle Principal 123',
                'is_active' => true,
            ]
        );

        // Crear usuario de prueba
        User::firstOrCreate(
            ['email' => 'gabriel@plastigest.com'],
            [
                'name' => 'Alejandro',
                'password' => Hash::make('password123'),
            ]
        );
    }
}
