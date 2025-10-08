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
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name', 150); // "Conteo Semanal Almacén A"
            $table->date('count_date');
            $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('set null'); // NULL = todos los almacenes
            $table->enum('status', ['planning', 'counting', 'completed', 'cancelled'])->default('planning');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // responsable
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_counts');
    }
};
