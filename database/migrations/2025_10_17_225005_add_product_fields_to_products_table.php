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
        Schema::table('products', function (Blueprint $table) {
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('code')->unique();
            $table->decimal('price', 10, 2)->default(0);
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn([
                'name',
                'description',
                'code',
                'price',
                'company_id',
                'category_id',
                'is_active'
            ]);
        });
    }
};
