<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permisos de cajas, turnos y punto de venta. Se asignan a los roles de
 * sistema; los roles de empresa los reciben desde la pantalla de roles.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'cash_registers_list',   'description' => 'Listar cajas',     'resource' => 'cash_registers'],
        ['name' => 'cash_registers_read',   'description' => 'Ver caja',         'resource' => 'cash_registers'],
        ['name' => 'cash_registers_create', 'description' => 'Crear cajas',      'resource' => 'cash_registers'],
        ['name' => 'cash_registers_update', 'description' => 'Actualizar cajas', 'resource' => 'cash_registers'],
        ['name' => 'cash_registers_delete', 'description' => 'Eliminar cajas',   'resource' => 'cash_registers'],
        ['name' => 'cash_register_sessions_list', 'description' => 'Listar turnos de caja', 'resource' => 'cash_register_sessions'],
        ['name' => 'cash_register_sessions_read', 'description' => 'Ver turno de caja',     'resource' => 'cash_register_sessions'],
        ['name' => 'sales_pos',      'description' => 'Vender en el punto de venta y abrir/cerrar su turno', 'resource' => 'sales'],
        ['name' => 'sales_discount', 'description' => 'Cambiar precio y aplicar descuentos en el punto de venta', 'resource' => 'sales'],
    ];

    public function up(): void
    {
        $existing = DB::table('permissions')->pluck('name')->all();

        $rows = [];
        foreach (self::PERMISSIONS as $permission) {
            if (!in_array($permission['name'], $existing)) {
                $rows[] = $permission + ['created_at' => now(), 'updated_at' => now()];
            }
        }
        if (!empty($rows)) {
            DB::table('permissions')->insert($rows);
        }

        $ids = DB::table('permissions')->whereIn('name', array_column(self::PERMISSIONS, 'name'))->pluck('id');

        foreach (DB::table('roles')->where('is_system', true)->pluck('id') as $roleId) {
            $assigned = DB::table('rol_permission')->where('role_id', $roleId)->pluck('permission_id')->all();
            $new = $ids->reject(fn($id) => in_array($id, $assigned))
                ->map(fn($id) => ['role_id' => $roleId, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()])
                ->values()->all();
            if (!empty($new)) {
                DB::table('rol_permission')->insert($new);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_column(self::PERMISSIONS, 'name'))->pluck('id');
        DB::table('rol_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
