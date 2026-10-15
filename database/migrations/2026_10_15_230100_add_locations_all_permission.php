<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso propio para "acceso a todas las sucursales" (locations_all).
 *
 * Antes lo daba locations_list, que el editor de roles muestra como
 * "Ubicaciones › Listar": quien lo tuviera entraba y operaba en cualquier
 * sucursal sin que nadie lo supiera. Ahora locations_list solo deja listar
 * sucursales y el acceso a todas es locations_all (o system_admin).
 *
 * Para no quitarle acceso a nadie en una base que ya está en uso, los roles
 * que hoy tienen locations_list reciben locations_all; los roles de sistema
 * también, como con los demás permisos nuevos.
 */
return new class extends Migration
{
    private const PERMISSION = [
        'name' => 'locations_all',
        'description' => 'Acceso a todas las sucursales',
        'resource' => 'locations',
    ];

    public function up(): void
    {
        if (!DB::table('permissions')->where('name', self::PERMISSION['name'])->exists()) {
            DB::table('permissions')->insert(self::PERMISSION + ['created_at' => now(), 'updated_at' => now()]);
        }

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION['name'])->value('id');
        $listPermissionId = DB::table('permissions')->where('name', 'locations_list')->value('id');

        $roleIds = DB::table('roles')->where('is_system', true)->pluck('id')
            ->merge($listPermissionId
                ? DB::table('rol_permission')->where('permission_id', $listPermissionId)->pluck('role_id')
                : [])
            ->unique();

        $assigned = DB::table('rol_permission')->where('permission_id', $permissionId)->pluck('role_id')->all();

        $rows = $roleIds
            ->reject(fn($roleId) => in_array($roleId, $assigned))
            ->map(fn($roleId) => [
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

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION['name'])->value('id');
        if (!$permissionId) {
            return;
        }

        DB::table('rol_permission')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
