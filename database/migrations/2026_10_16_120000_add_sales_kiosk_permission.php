<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso del kiosko de autoservicio (sales_kiosk): ver el catálogo del
 * kiosko y mandar pedidos que el cliente paga en caja. La cuenta del
 * dispositivo lleva un rol con solo este permiso; ese rol lo arma el dueño
 * en Roles (no se siembra).
 *
 * Se asigna a los roles de sistema, como los demás permisos nuevos; los
 * roles de empresa lo reciben desde la pantalla de roles.
 */
return new class extends Migration
{
    private const PERMISSION = [
        'name' => 'sales_kiosk',
        'description' => 'Usar el kiosko de autoservicio',
        'resource' => 'sales',
    ];

    public function up(): void
    {
        if (!DB::table('permissions')->where('name', self::PERMISSION['name'])->exists()) {
            DB::table('permissions')->insert(self::PERMISSION + ['created_at' => now(), 'updated_at' => now()]);
        }

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION['name'])->value('id');
        $assigned = DB::table('rol_permission')->where('permission_id', $permissionId)->pluck('role_id')->all();

        $rows = DB::table('roles')->where('is_system', true)->pluck('id')
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
