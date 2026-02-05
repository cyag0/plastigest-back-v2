<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;
<<<<<<< HEAD
use App\Models\Admin\Location;
=======
use App\Models\Admin\Company;
>>>>>>> refs/remotes/origin/main

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
<<<<<<< HEAD
        $locations = Location::all();

        $userTypes = [
            ['name' => 'Gerente', 'email' => 'gerente'],
            ['name' => 'Vendedor', 'email' => 'vendedor'],
            ['name' => 'Almacenista', 'email' => 'almacenista'],
            ['name' => 'Auxiliar', 'email' => 'auxiliar'],
        ];

        foreach ($locations as $location) {
            foreach ($userTypes as $index => $type) {
                // Nombre de la locación en formato slug
                $locationSlug = strtolower(str_replace(' ', '', $location->name));
                $companySlug = strtolower(str_replace(' ', '', $location->company->name));
                
                $user = User::create([
                    'name' => $type['name'] . ' ' . $location->name,
                    'email' => $type['email'] . '.' . $locationSlug . '.' . $companySlug . '@test.com',
                    'password' => Hash::make('password123'),
                    'email_verified_at' => now(),
                ]);

                // Asociar usuario con la compañía
                DB::table('user_company')->insert([
                    'user_id' => $user->id,
                    'company_id' => $location->company_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Crear worker para el usuario
                $workerId = DB::table('workers')->insertGetId([
                    'company_id' => $location->company_id,
                    'user_id' => $user->id,
                    'employee_number' => 'EMP-' . str_pad($location->id . $index, 5, '0', STR_PAD_LEFT),
                    'position' => $type['name'],
                    'department' => 'Operaciones',
                    'hire_date' => now()->subMonths(rand(1, 24)),
                    'salary' => rand(8000, 25000),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Asociar worker con la locación
                DB::table('location_worker')->insert([
                    'location_id' => $location->id,
                    'worker_id' => $workerId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Crear un super admin
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@plastigest.com',
            'password' => Hash::make('admin123'),
            'email_verified_at' => now(),
        ]);

        // Asociar con todas las compañías
        $companies = DB::table('companies')->get();
        foreach ($companies as $company) {
            DB::table('user_company')->insert([
                'user_id' => $superAdmin->id,
                'company_id' => $company->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('✅ Usuarios creados exitosamente: ' . User::count() . ' usuarios');
=======
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
>>>>>>> refs/remotes/origin/main
    }
}

