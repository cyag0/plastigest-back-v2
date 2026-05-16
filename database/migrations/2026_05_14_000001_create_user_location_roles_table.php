<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_location_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->foreignId('role_id')->nullable()->constrained('roles')->onDelete('set null');
            $table->timestamps();

            $table->unique(['user_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_location_roles');
    }
};
