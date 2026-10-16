<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Se retira el módulo "Reportes de ventas": era una foto del día con montos
 * que se podían cambiar a mano y se confundía con el corte de caja. El corte
 * es el del turno de caja y el análisis de ventas está en Reportes del
 * negocio.
 *
 * reports_list/read/create/update/delete solo los revisaba
 * SalesReportController: se borran (con sus asignaciones a roles) para que el
 * editor de roles no muestre casillas que ya no controlan nada. reports_view y
 * reports_export siguen: tablero, estadísticas y Reportes del negocio.
 *
 * La tabla sales_reports se conserva con lo que ya se haya guardado.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'reports_list',   'description' => 'Listar reportes',     'resource' => 'reports'],
        ['name' => 'reports_read',   'description' => 'Ver reporte',         'resource' => 'reports'],
        ['name' => 'reports_create', 'description' => 'Crear reportes',      'resource' => 'reports'],
        ['name' => 'reports_update', 'description' => 'Actualizar reportes', 'resource' => 'reports'],
        ['name' => 'reports_delete', 'description' => 'Eliminar reportes',   'resource' => 'reports'],
    ];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('id');

        DB::table('rol_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    /**
     * Vuelven los permisos con los roles de sistema, como al crearlos
     * (2026_09_24_000001). Las asignaciones a roles de empresa no se recuperan.
     */
    public function down(): void
    {
        $existing = DB::table('permissions')->pluck('name')->all();

        foreach (self::PERMISSIONS as $permission) {
            if (!in_array($permission['name'], $existing)) {
                DB::table('permissions')->insert($permission + ['created_at' => now(), 'updated_at' => now()]);
            }
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('id');

        foreach (DB::table('roles')->where('is_system', true)->pluck('id') as $roleId) {
            $assigned = DB::table('rol_permission')->where('role_id', $roleId)->pluck('permission_id')->all();

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

            if ($rows) {
                DB::table('rol_permission')->insert($rows);
            }
        }
    }
};
