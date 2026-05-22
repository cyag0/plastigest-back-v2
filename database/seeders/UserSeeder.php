<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Admin\Location;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $locations = Location::all();

        $userTypes = [
            ['name' => 'Gerente', 'email' => 'gerente'],
            ['name' => 'Vendedor', 'email' => 'vendedor'],
            ['name' => 'Almacenista', 'email' => 'almacenista'],
            ['name' => 'Auxiliar', 'email' => 'auxiliar'],
        ];

        foreach ($locations as $location) {
            foreach ($userTypes as $index => $type) {
                $locationSlug = strtolower(str_replace(' ', '', $location->name));
                $companySlug = strtolower(str_replace(' ', '', $location->company->name));
                $email = $type['email'] . '.' . $locationSlug . '.' . $companySlug . '@test.com';

                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $type['name'] . ' ' . $location->name,
                        'password' => Hash::make('password123'),
                        'email_verified_at' => now(),
                    ]
                );

                if ($user->wasRecentlyCreated) {
                    // Asociar usuario con la compañía
                    DB::table('user_company')->insertOrIgnore([
                        'user_id' => $user->id,
                        'company_id' => $location->company_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Crear worker para el usuario
                    $workerId = DB::table('workers')->insertGetId([
                        'company_id' => $location->company_id,
                        'user_id' => $user->id,
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
        }

        // Crear un super admin
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@plastigest.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin123'),
                'email_verified_at' => now(),
            ]
        );

        // Asociar con todas las compañías
        $companies = DB::table('companies')->get();
        foreach ($companies as $company) {
            DB::table('user_company')->insertOrIgnore([
                'user_id' => $superAdmin->id,
                'company_id' => $company->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Crear (o recuperar) rol Super Admin con todos los permisos
        $superAdminRoleId = DB::table('roles')
            ->where('name', 'Super Admin')
            ->where('is_system', true)
            ->value('id');

        if (!$superAdminRoleId) {
            $superAdminRoleId = DB::table('roles')->insertGetId([
                'name' => 'Super Admin',
                'description' => 'Acceso total al sistema',
                'is_system' => true,
                'company_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Asignar todos los permisos al rol (sin duplicados)
        $allPermissions = DB::table('permissions')->pluck('id');
        $existingRolePermissions = DB::table('rol_permission')
            ->where('role_id', $superAdminRoleId)
            ->pluck('permission_id')
            ->toArray();

        foreach ($allPermissions as $permissionId) {
            if (!in_array($permissionId, $existingRolePermissions)) {
                DB::table('rol_permission')->insert([
                    'role_id' => $superAdminRoleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        // Asignar el rol al super admin globalmente (fallback)
        DB::table('users_roles')->insertOrIgnore([
            'user_id' => $superAdmin->id,
            'role_id' => $superAdminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Asignar el rol al super admin en TODAS las ubicaciones (tabla principal)
        $allLocations = DB::table('locations')->get();
        foreach ($allLocations as $location) {
            DB::table('user_location_roles')->insertOrIgnore([
                'user_id' => $superAdmin->id,
                'location_id' => $location->id,
                'role_id' => $superAdminRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('✅ Usuarios creados exitosamente: ' . User::count() . ' usuarios');
        $this->command->info('🔑 Super Admin con ' . $allPermissions->count() . ' permisos asignados en ' . $allLocations->count() . ' ubicaciones');
    }
}
