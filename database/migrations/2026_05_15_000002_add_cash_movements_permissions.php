<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('permissions')->pluck('name')->toArray();

        $permissions = [
            ['name' => 'cash_movements_list',   'description' => 'Listar movimientos de caja',   'resource' => 'cash_movements'],
            ['name' => 'cash_movements_create',  'description' => 'Crear movimientos de caja',    'resource' => 'cash_movements'],
            ['name' => 'cash_movements_update',  'description' => 'Actualizar movimientos de caja', 'resource' => 'cash_movements'],
            ['name' => 'cash_movements_delete',  'description' => 'Eliminar movimientos de caja', 'resource' => 'cash_movements'],
            ['name' => 'cash_movements_read',    'description' => 'Ver detalle de movimiento',    'resource' => 'cash_movements'],
        ];

        $toInsert = array_filter($permissions, fn($p) => !in_array($p['name'], $existing));

        foreach ($toInsert as &$permission) {
            $permission['created_at'] = now();
            $permission['updated_at'] = now();
        }

        if (!empty($toInsert)) {
            DB::table('permissions')->insert(array_values($toInsert));
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', [
            'cash_movements_list',
            'cash_movements_create',
            'cash_movements_update',
            'cash_movements_delete',
            'cash_movements_read',
        ])->delete();
    }
};
