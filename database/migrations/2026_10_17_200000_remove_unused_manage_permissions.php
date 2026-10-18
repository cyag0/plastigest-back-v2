<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuera los permisos que no hacían nada: users_manage («Gestionar usuarios»),
 * companies_manage («Gestionar empresas») y settings_manage («Gestionar
 * configuración»). Ningún endpoint ni pantalla los revisaba, así que en el
 * editor de roles eran casillas que no daban ni quitaban nada. Usuarios,
 * empresas y configuración siguen con sus permisos de siempre (listar, ver,
 * crear, editar, eliminar) y con system_admin.
 *
 * Se borran con sus asignaciones a roles (rol_permission las borraría en
 * cascada, pero así no depende de la llave foránea). Ninguna otra tabla
 * apunta a permissions.id; notification_preferences guarda el nombre de un
 * permiso, pero ninguno de estos (la API no deja cambiarlo).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'users_manage',     'description' => 'Gestionar Usuarios',      'resource' => 'users'],
        ['name' => 'companies_manage', 'description' => 'Gestionar Empresas',      'resource' => 'companies'],
        ['name' => 'settings_manage',  'description' => 'Gestionar Configuración', 'resource' => 'settings'],
    ];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('rol_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    /**
     * Vuelven los permisos con los roles de sistema, como los dejaba el
     * seeder. Las asignaciones a roles de empresa no se recuperan.
     */
    public function down(): void
    {
        $existing = DB::table('permissions')->pluck('name')->all();

        foreach (self::PERMISSIONS as $permission) {
            if (!in_array($permission['name'], $existing, true)) {
                DB::table('permissions')->insert($permission + ['created_at' => now(), 'updated_at' => now()]);
            }
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('id')
            ->map(fn($id) => (int) $id);

        foreach (DB::table('roles')->where('is_system', true)->pluck('id') as $roleId) {
            $assigned = DB::table('rol_permission')->where('role_id', $roleId)->pluck('permission_id')
                ->map(fn($id) => (int) $id)->all();

            $rows = $permissionIds
                ->reject(fn($id) => in_array($id, $assigned, true))
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
