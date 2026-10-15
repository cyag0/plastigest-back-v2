<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Super Admin. La contraseña NUNCA va en el código (el repositorio es
        // público): viene de ADMIN_PASSWORD y solo se usa al crear el usuario,
        // así que volver a correr el seeder no la pide ni la cambia.
        $email = env('ADMIN_EMAIL') ?: 'admin@cocos-francisco.com';
        $superAdmin = User::where('email', $email)->first();

        if (!$superAdmin) {
            $superAdmin = User::create([
                'name' => 'Administrador',
                'email' => $email,
                'password' => Hash::make($this->adminPassword()),
                'email_verified_at' => now(),
            ]);
        }

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

        // Asignar el rol al super admin globalmente (fallback). El índice
        // único (user_id, role_id) hace que volver a correrlo no lo repita.
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

    /**
     * Contraseña del Super Admin desde ADMIN_PASSWORD. En local/testing, si no
     * está definida, se genera una al azar y se muestra una sola vez en la
     * consola (antes era "password", escrita aquí: ninguna contraseña va en el
     * código). En cualquier otro entorno (producción, staging) se aborta con
     * un mensaje.
     */
    private function adminPassword(): string
    {
        $password = (string) env('ADMIN_PASSWORD', '');

        if (app()->environment('local', 'testing')) {
            if ($password === '') {
                $password = Str::password(20, symbols: false);
                $this->command->warn('⚠️  ADMIN_PASSWORD no está definido. Contraseña generada para el Super Admin '
                    . "(solo se muestra ahora; define ADMIN_PASSWORD para fijarla): {$password}");
            }

            return $password;
        }

        if (strlen($password) < 12) {
            throw new \RuntimeException(
                'UserSeeder: define ADMIN_PASSWORD (mínimo 12 caracteres) en las variables de entorno para crear '
                . 'el Super Admin en "' . app()->environment() . '". Nunca la pongas en el código.'
            );
        }

        return $password;
    }
}
