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
        Schema::table('sales_reports', function (Blueprint $table) {
            $table->decimal('total_expenses', 10, 2)->default(0)->after('total_transfer');
            $table->decimal('net_income', 10, 2)->default(0)->after('total_expenses');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_reports', function (Blueprint $table) {
            $table->dropColumn(['total_expenses', 'net_income']);
        });
    }
};
