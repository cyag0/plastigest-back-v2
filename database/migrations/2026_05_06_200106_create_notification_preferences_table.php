<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');

            // Tipo de evento (coincide con NotificationEventType enum)
            $table->string('event_type', 100);

            // Permiso requerido para recibir este tipo de notificación
            $table->string('permission_name');

            // Canales activos para este evento en esta empresa
            $table->boolean('channel_db')->default(true);
            $table->boolean('channel_email')->default(true);
            $table->boolean('channel_push')->default(true);

            // Permite desactivar un tipo de evento completamente
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'event_type']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
