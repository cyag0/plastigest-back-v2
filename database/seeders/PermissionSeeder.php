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
            // Sistema (solo para super admins). Sin "manage": users_manage y
            // companies_manage no los revisaba nada (ver la migración
            // remove_unused_manage_permissions).
            [Resources::USERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::COMPANIES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],

            // Empresa (para admins de empresa)
            // [Resources::WORKERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::LOCATIONS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::ROLES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::PERMISSIONS, [Actions::READ, Actions::LIST]],

            // Catálogos
            [Resources::CUSTOMERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::SUPPLIERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::UNITS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::CATEGORIES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::INVENTORY_TYPES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::PRODUCTS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],

            // Operaciones
            [Resources::INVENTORY, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST, Actions::MANAGE]],
            [Resources::MOVEMENTS, [Actions::CREATE, Actions::READ, Actions::LIST]],
            [Resources::TRANSFERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::SALES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::SALES_ORDERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::PURCHASES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST, Actions::MANAGE]],
            [Resources::PRODUCTION, [Actions::READ, Actions::LIST]], // legacy - se conserva solo lectura para reportes históricos
            [Resources::PRODUCTION_ORDERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::FORMULAS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::EXPENSES, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST, Actions::EXPORT]],
            [Resources::TASKS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::REMINDERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::NOTIFICATIONS, [Actions::READ, Actions::LIST, Actions::MANAGE]],
            [Resources::CASH_MOVEMENTS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            [Resources::CASH_REGISTERS, [Actions::CREATE, Actions::READ, Actions::UPDATE, Actions::DELETE, Actions::LIST]],
            // Historial de turnos de todos los cajeros. Abrir/cerrar el propio es sales_pos.
            [Resources::CASH_REGISTER_SESSIONS, [Actions::READ, Actions::LIST]],

            // Reportes y Dashboard
            // Solo ver y exportar: el CRUD era del reporte de ventas manual, que
            // se retiró (el corte es el del turno de caja).
            [Resources::REPORTS, [Actions::VIEW, Actions::EXPORT]],
            [Resources::DASHBOARD, [Actions::VIEW]],
            // Sin settings_manage: tampoco lo revisaba nada.
            [Resources::SETTINGS, [Actions::READ, Actions::UPDATE]],
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
            [
                'name' => 'sales_pos',
                'description' => 'Vender en el punto de venta y abrir/cerrar su turno',
                'resource' => Resources::SALES->value,
            ],
            [
                'name' => 'sales_discount',
                'description' => 'Cambiar precio y aplicar descuentos en el punto de venta',
                'resource' => Resources::SALES->value,
            ],
            [
                // Kiosko de autoservicio: catálogo para clientes y pedidos
                // que se pagan en caja. La cuenta del kiosko solo tiene este.
                'name' => 'sales_kiosk',
                'description' => 'Usar el kiosko de autoservicio',
                'resource' => Resources::SALES->value,
            ],
            [
                // Entrar y operar en cualquier sucursal de la empresa. Antes lo
                // daba locations_list ("Listar ubicaciones") sin que se notara.
                'name' => 'locations_all',
                'description' => 'Acceso a todas las sucursales',
                'resource' => Resources::LOCATIONS->value,
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
