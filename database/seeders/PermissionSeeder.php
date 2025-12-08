<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Enums\Resources;
use App\Enums\Actions;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener permisos existentes para evitar duplicados
        $existingPermissions = DB::table('permissions')->pluck('name')->toArray();

        $permissionsToInsert = [];

        // Definir permisos por recurso
        $resourcesWithActions = [
            // Sistema (solo para super admins)
            [Resources::USERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST, Actions::MANAGE]],
            [Resources::COMPANIES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST, Actions::MANAGE]],

            // Empresa (para admins de empresa)
            [Resources::WORKERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::LOCATIONS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::ROLES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::PERMISSIONS, [Actions::READ, Actions::LIST]],

            // Catálogos
            [Resources::CUSTOMERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::SUPPLIERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::UNITS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::CATEGORIES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::PRODUCTS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],

            // Operaciones
            [Resources::INVENTORY, [Actions::READ, Actions::UPDATE, Actions::LIST, Actions::MANAGE]],
            [Resources::MOVEMENTS, [Actions::CREATE, Actions::READ, Actions::LIST]],
            [Resources::SALES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::PURCHASES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST, Actions::MANAGE]],

            // Reportes y Dashboard
            [Resources::REPORTS, [Actions::VIEW, Actions::EXPORT]],
            [Resources::DASHBOARD, [Actions::VIEW]],
            [Resources::SETTINGS, [Actions::READ, Actions::UPDATE, Actions::MANAGE]],
        ];

        // Generar permisos basados en recursos y acciones
        foreach ($resourcesWithActions as [$resourceEnum, $actions]) {
            foreach ($actions as $action) {
                $permissionName = $resourceEnum->value . '_' . $action->value;

                // Solo agregar si no existe
                if (!in_array($permissionName, $existingPermissions)) {
                    $permissionsToInsert[] = [
                        'name' => $permissionName,
                        'description' => $action->label() . ' ' . $resourceEnum->label(),
                        'resource' => $resourceEnum->value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        // Agregar permisos especiales
        $specialPermissions = [
            [
                'name' => 'system_admin',
                'description' => 'Administrador del sistema',
                'resource' => Resources::SETTINGS->value,
            ],
        ];

        foreach ($specialPermissions as $specialPermission) {
            if (!in_array($specialPermission['name'], $existingPermissions)) {
                $permissionsToInsert[] = array_merge($specialPermission, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Insertar solo los permisos nuevos
        if (!empty($permissionsToInsert)) {
            DB::table('permissions')->insert($permissionsToInsert);
            $this->command->info('Se insertaron ' . count($permissionsToInsert) . ' permisos nuevos.');
        } else {
            $this->command->info('Todos los permisos ya existen. No se insertó ninguno nuevo.');
        }
    }
}
