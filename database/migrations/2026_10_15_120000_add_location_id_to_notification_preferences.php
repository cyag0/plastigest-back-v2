<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de notificación por sucursal.
 *
 * Una fila sin sucursal es la configuración de la empresa; una con sucursal la
 * reemplaza para los avisos de esa sucursal (Administración › Esta sucursal).
 * Si la sucursal no tiene fila para un aviso, usa la de la empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('company_id')
                ->constrained('locations')->cascadeOnDelete();

            // MySQL no tiene índices parciales y NULL no choca en un índice
            // único: la llave usa 0 para la fila de la empresa, así no puede
            // haber dos filas de empresa (ni dos de una sucursal) por aviso.
            // Virtual y no stored: MySQL no acepta una columna generada stored
            // sobre una llave foránea con borrado en cascada.
            $table->unsignedBigInteger('location_key')->virtualAs('IFNULL(location_id, 0)')->after('location_id');

            $table->dropUnique(['company_id', 'event_type']);
            $table->unique(['company_id', 'location_key', 'event_type'], 'notification_preferences_scope_unique');
        });
    }

    public function down(): void
    {
        // Las filas de sucursal no caben en el esquema anterior (un aviso por
        // empresa).
        \Illuminate\Support\Facades\DB::table('notification_preferences')->whereNotNull('location_id')->delete();

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropUnique('notification_preferences_scope_unique');
            $table->unique(['company_id', 'event_type']);
            $table->dropColumn('location_key');
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
