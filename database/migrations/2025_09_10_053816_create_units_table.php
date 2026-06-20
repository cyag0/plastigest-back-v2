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
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('abbreviation', 20);
            $table->string('unit_type', 50); // quantity, mass, volume
            $table->boolean('is_base_unit')->default(false);
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');
            $table->decimal('factor_to_base', 15, 6)->default(1.000000);
            $table->timestamps();
        });

        // Las unidades predefinidas se siembran en UnitsSeeder (fuente de verdad).
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
