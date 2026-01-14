<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Admin\Company;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Desactivar restricciones de foreign keys temporalmente
        \DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        
        // Limpiar usuarios anteriores (excepto el super admin si existe)
        User::where('email', '!=', 'admin@plastigest.com')->delete();
        
        // Reactivar restricciones de foreign keys
        \DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Crear usuarios de prueba
        $users = [
            [
                'name' => 'Ana García',
                'email' => 'ana@plastigest.com',
                'password' => Hash::make('password123'),
            ],
            [
                'name' => 'Abigail Rodríguez',
                'email' => 'abigail@plastigest.com',
                'password' => Hash::make('password123'),
            ],
            [
                'name' => 'Blanca Martínez',
                'email' => 'blanca@plastigest.com',
                'password' => Hash::make('password123'),
            ],
            [
                'name' => 'Marilu López',
                'email' => 'marilu@plastigest.com',
                'password' => Hash::make('password123'),
            ],
            [
                'name' => 'Carlos Sánchez',
                'email' => 'carlos@plastigest.com',
                'password' => Hash::make('password123'),
            ],
        ];

        foreach ($users as $userData) {
            User::create($userData);
        }

        $this->command->info('5 usuarios de prueba creados exitosamente');
        $this->command->info('Contraseña para todos: password123');
    }
}
