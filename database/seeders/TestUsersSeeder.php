<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener la primera empresa
        $company = Company::first();
        
        if (!$company) {
            $this->command->error('No se encontró ninguna empresa. Por favor crea una empresa primero.');
            return;
        }

        // Usuario Admin Test
        $admin = User::firstOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Admin Test',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ]
        );

        // Asociar con la empresa si no está asociado
        if (!$admin->companies()->where('company_id', $company->id)->exists()) {
            $admin->companies()->attach($company->id);
        }

        $this->command->info('✓ Usuario creado exitosamente:');
        $this->command->info('  Email: admin@test.com');
        $this->command->info('  Password: password123');
        $this->command->info('  Empresa: ' . $company->name);

        // Usuario normal de prueba
        $user = User::firstOrCreate(
            ['email' => 'user@test.com'],
            [
                'name' => 'Usuario Test',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ]
        );

        // Asociar con la empresa si no está asociado
        if (!$user->companies()->where('company_id', $company->id)->exists()) {
            $user->companies()->attach($company->id);
        }

        $this->command->info('✓ Usuario normal creado:');
        $this->command->info('  Email: user@test.com');
        $this->command->info('  Password: password123');
    }
}
