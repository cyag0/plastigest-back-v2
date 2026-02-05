<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Company;
use Illuminate\Database\Seeder;

class AssociateUsersToCompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener la primera empresa
        $company = Company::first();
        
        if (!$company) {
            $this->command->error('No se encontró ninguna empresa.');
            return;
        }

        // Obtener todos los usuarios
        $users = User::all();

        if ($users->isEmpty()) {
            $this->command->error('No se encontraron usuarios.');
            return;
        }

        $associated = 0;
        $skipped = 0;

        foreach ($users as $user) {
            // Verificar si ya está asociado
            if (!$user->companies()->where('company_id', $company->id)->exists()) {
                $user->companies()->attach($company->id);
                $this->command->info("✓ Usuario asociado: {$user->name} ({$user->email})");
                $associated++;
            } else {
                $this->command->warn("- Usuario ya asociado: {$user->name} ({$user->email})");
                $skipped++;
            }
        }

        $this->command->info("\n=== Resumen ===");
        $this->command->info("Empresa: {$company->name}");
        $this->command->info("Usuarios asociados: {$associated}");
        $this->command->info("Usuarios ya existentes: {$skipped}");
        $this->command->info("Total de usuarios: " . $users->count());
    }
}
