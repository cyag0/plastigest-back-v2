<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('closing_date');
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->decimal('total_income', 12, 2)->default(0);
            $table->decimal('total_expense', 12, 2)->default(0);
            $table->decimal('expected_balance', 12, 2)->default(0);
            $table->decimal('physical_count', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->decimal('total_cash', 12, 2)->default(0);
            $table->decimal('total_card', 12, 2)->default(0);
            $table->decimal('total_transfer', 12, 2)->default(0);
            $table->decimal('total_other', 12, 2)->default(0);
            $table->integer('movements_count')->default(0);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('closed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closings');
    }
};
