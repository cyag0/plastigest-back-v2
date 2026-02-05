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
        // Eliminar tabla worker_roles
        Schema::dropIfExists('worker_roles');

        // Agregar role_id directamente a workers
        Schema::table('workers', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('user_id')->constrained('roles')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover role_id de workers
        Schema::table('workers', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        // Recrear worker_roles
        Schema::create('worker_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            $table->unique(['worker_id', 'role_id']);
        });
    }
};
