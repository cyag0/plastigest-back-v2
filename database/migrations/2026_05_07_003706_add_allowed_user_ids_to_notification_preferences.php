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
        Schema::table('notification_preferences', function (Blueprint $table) {
            // JSON array of user IDs to restrict recipients.
            // NULL = send to all eligible users (permission-based).
            // [] = send to no one (empty list).
            // [1,2,3] = send only to these specific user IDs.
            $table->json('allowed_user_ids')->nullable()->default(null)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn('allowed_user_ids');
        });
    }
};
