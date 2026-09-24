<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permisos que el código ya revisa pero que nunca se crearon:
 *
 * - inventory_types_*: InventoryTypeController. Sin ellos nadie podía crear,
 *   editar ni eliminar tipos de inventario propios de la empresa.
 * - reports_list/read/create/update/delete: SalesReportController. Sin ellos
 *   la pantalla de reportes de venta respondía 403 a todos los usuarios.
 *
 * Se asignan a los roles de sistema (Super Admin), que por definición tienen
 * todos los permisos. Los roles de empresa los reciben desde la pantalla de
 * roles.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'inventory_types_list',   'description' => 'Listar tipos de inventario',     'resource' => 'inventory_types'],
        ['name' => 'inventory_types_read',   'description' => 'Ver tipo de inventario',         'resource' => 'inventory_types'],
        ['name' => 'inventory_types_create', 'description' => 'Crear tipos de inventario',      'resource' => 'inventory_types'],
        ['name' => 'inventory_types_update', 'description' => 'Actualizar tipos de inventario', 'resource' => 'inventory_types'],
        ['name' => 'inventory_types_delete', 'description' => 'Eliminar tipos de inventario',   'resource' => 'inventory_types'],
        ['name' => 'reports_list',   'description' => 'Listar reportes',     'resource' => 'reports'],
        ['name' => 'reports_read',   'description' => 'Ver reporte',         'resource' => 'reports'],
        ['name' => 'reports_create', 'description' => 'Crear reportes',      'resource' => 'reports'],
        ['name' => 'reports_update', 'description' => 'Actualizar reportes', 'resource' => 'reports'],
        ['name' => 'reports_delete', 'description' => 'Eliminar reportes',   'resource' => 'reports'],
    ];

    public function up(): void
    {
        $existing = DB::table('permissions')->pluck('name')->toArray();

        $toInsert = [];
        foreach (self::PERMISSIONS as $permission) {
            if (!in_array($permission['name'], $existing)) {
                $toInsert[] = $permission + ['created_at' => now(), 'updated_at' => now()];
            }
        }

        if (!empty($toInsert)) {
            DB::table('permissions')->insert($toInsert);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('id');

        foreach (DB::table('roles')->where('is_system', true)->pluck('id') as $roleId) {
            $assigned = DB::table('rol_permission')
                ->where('role_id', $roleId)
                ->pluck('permission_id')
                ->toArray();

            $rows = $permissionIds
                ->reject(fn($id) => in_array($id, $assigned))
                ->map(fn($id) => [
                    'role_id' => $roleId,
                    'permission_id' => $id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all();

            if (!empty($rows)) {
                DB::table('rol_permission')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('id');

        DB::table('rol_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
