<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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

        // Insertar unidades predefinidas
        DB::table('units')->insert([
            // Cantidad
            ['name' => 'Pieza', 'abbreviation' => 'pz', 'unit_type' => 'quantity', 'is_base_unit' => true, 'company_id' => null, 'factor_to_base' => 1.000000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Decena', 'abbreviation' => 'da', 'unit_type' => 'quantity', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 10.000000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Docena', 'abbreviation' => 'dz', 'unit_type' => 'quantity', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 12.000000, 'created_at' => now(), 'updated_at' => now()],
            
            // Masa
            ['name' => 'Kilogramo', 'abbreviation' => 'kg', 'unit_type' => 'mass', 'is_base_unit' => true, 'company_id' => null, 'factor_to_base' => 1.000000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Gramo', 'abbreviation' => 'g', 'unit_type' => 'mass', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 0.001000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Tonelada', 'abbreviation' => 'ton', 'unit_type' => 'mass', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 1000.000000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Miligramo', 'abbreviation' => 'mg', 'unit_type' => 'mass', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 0.000001, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Libra', 'abbreviation' => 'lb', 'unit_type' => 'mass', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 0.453592, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Onza', 'abbreviation' => 'oz', 'unit_type' => 'mass', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 0.028350, 'created_at' => now(), 'updated_at' => now()],
            
            // Volumen
            ['name' => 'Litro', 'abbreviation' => 'L', 'unit_type' => 'volume', 'is_base_unit' => true, 'company_id' => null, 'factor_to_base' => 1.000000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Mililitro', 'abbreviation' => 'ml', 'unit_type' => 'volume', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 0.001000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Galón', 'abbreviation' => 'gal', 'unit_type' => 'volume', 'is_base_unit' => false, 'company_id' => null, 'factor_to_base' => 3.785410, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
