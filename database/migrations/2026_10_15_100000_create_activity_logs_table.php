<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora: quién hizo qué en la app (ventas, cortes, cambios de precio,
 * permisos, eliminaciones…). Es la base de "Mi actividad".
 *
 * Sólo se inserta, nunca se edita. El nombre del usuario se guarda aparte
 * para que el registro siga diciendo quién fue aunque el usuario se borre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('module', 30);
            $table->string('event', 30);
            $table->nullableMorphs('subject');
            $table->string('subject_label')->nullable();
            $table->string('description', 500);
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['company_id', 'module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
