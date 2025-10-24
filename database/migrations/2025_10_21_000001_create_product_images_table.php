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
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');

            // Información básica de la imagen
            $table->string('image_path')->comment('Ruta relativa de la imagen');
            $table->string('original_name')->nullable()->comment('Nombre original del archivo');
            $table->string('alt_text')->nullable()->comment('Texto alternativo');

            // Tipo y categorización
            $table->enum('image_type', [
                'main',        // Imagen principal
                'gallery',     // Galería del producto
                'technical',   // Especificaciones técnicas
                'packaging',   // Empaque/embalaje
                'certificate'  // Certificaciones
            ])->default('gallery');

            // Metadatos específicos para productos industriales
            $table->integer('sort_order')->default(0)->comment('Orden de visualización');
            $table->string('size', 20)->nullable()->comment('Dimensiones: 1920x1080');
            $table->integer('file_size')->nullable()->comment('Tamaño en bytes');
            $table->string('mime_type', 100)->nullable()->comment('image/jpeg, image/png');

            // Campos específicos del negocio
            $table->boolean('is_public')->default(true)->comment('Visible públicamente');
            $table->boolean('show_in_catalog')->default(true)->comment('Mostrar en catálogo');
            $table->json('metadata')->nullable()->comment('Metadatos adicionales');

            $table->timestamps();

            // Índices para optimización
            $table->index(['product_id', 'image_type']);
            $table->index(['product_id', 'sort_order']);
            $table->index('is_public');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
