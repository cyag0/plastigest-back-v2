<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los roles de sistema (Super Admin) tienen todos los permisos: el seeder se
 * los da todos y el editor de roles no deja cambiarlos. Pero no todas las
 * migraciones que agregaron permisos se los asignaron (cash_movements_*, por
 * ejemplo): en una base sembrada antes de esas migraciones el Super Admin se
 * quedaba sin "Caja".
 *
 * Cada rol de sistema recibe aquí todo permiso existente que le falte. Se
 * puede correr las veces que sea: no duplica asignaciones ni toca los roles
 * de empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = DB::table('permissions')->pluck('id')->map(fn($id) => (int) $id);
        if ($permissionIds->isEmpty()) {
            return;
        }

        foreach (DB::table('roles')->where('is_system', true)->pluck('id') as $roleId) {
            $assigned = array_flip(
                DB::table('rol_permission')->where('role_id', $roleId)->pluck('permission_id')
                    ->map(fn($id) => (int) $id)->all()
            );

            $rows = $permissionIds
                ->reject(fn($permissionId) => isset($assigned[$permissionId]))
                ->map(fn($permissionId) => [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('rol_permission')->insert($chunk);
            }
        }
    }

    /**
     * No se quita nada: no se sabe qué permisos le faltaban a cada rol antes, y
     * quitárselos al Super Admin lo dejaría sin acceso.
     */
    public function down(): void
    {
        //
    }
};
