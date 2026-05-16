<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('notifications');

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('company_id')->constrained()->onDelete('cascade');

            // Tipo de evento que originó esta notificación
            $table->string('event_type', 100);

            $table->string('title');
            $table->text('message');

            // Severidad visual (reemplaza el campo 'type' del sistema anterior)
            $table->enum('severity', ['info', 'success', 'warning', 'error', 'alert'])->default('info');

            // Datos de contexto crudos (para deep-link, etc.)
            $table->json('data')->nullable();

            // Canal de entrega: un registro por usuario+canal
            $table->enum('channel', ['db', 'email', 'push'])->default('db');
            $table->enum('delivery_status', ['pending', 'sent', 'failed'])->default('sent');
            $table->text('delivery_error')->nullable();

            // Referencia polimórfica al objeto que originó la notificación
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();

            // Estado de lectura (solo relevante para canal 'db')
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index('company_id');
            $table->index('event_type');
            $table->index(['channel', 'delivery_status']);
            $table->index(['notifiable_type', 'notifiable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
