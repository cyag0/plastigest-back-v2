<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Se retiró el código del sistema anterior (compras v1, movimientos,
 * transferencias y ajustes sobre la tabla movements, trabajadores). Tareas,
 * notificaciones y el libro mayor guardan la clase relacionada como texto:
 * si una apunta a una clase que ya no existe, cargar la relación revienta
 * con "Class not found" (el listado de tareas carga `related`).
 *
 * Aquí se desliga el registro de esa clase. La tarea o la notificación se
 * conserva con su título y descripción; solo pierde el vínculo.
 */
return new class extends Migration
{
    private const REMOVED = [
        'App\\Models\\Purchase',
        'App\\Models\\PurchaseDetail',
        'App\\Models\\Movement',
        'App\\Models\\MovementDetail',
        'App\\Models\\Transfer',
        'App\\Models\\Adjustment',
        'App\\Models\\AdjustmentDetail',
        'App\\Models\\Admin\\Worker',
    ];

    public function up(): void
    {
        DB::table('tasks')
            ->whereIn('related_type', self::REMOVED)
            ->update(['related_type' => null, 'related_id' => null]);

        DB::table('notifications')
            ->whereIn('notifiable_type', self::REMOVED)
            ->update(['notifiable_type' => null, 'notifiable_id' => null]);

        DB::table('stock_ledger_entries')
            ->whereIn('reference_type', self::REMOVED)
            ->update(['reference_type' => null, 'reference_id' => null]);
    }

    public function down(): void
    {
        // No se puede reconstruir el vínculo.
    }
};
