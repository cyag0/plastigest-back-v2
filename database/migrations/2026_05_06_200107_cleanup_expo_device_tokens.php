<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Expo tokens are not FCM-compatible. Remove them so only valid FCM tokens remain.
        DB::table('device_tokens')
            ->where('token', 'like', 'ExponentPushToken%')
            ->orWhere('token', 'like', 'ExpoPushToken%')
            ->delete();
    }

    public function down(): void
    {
        // Expo tokens cannot be restored after deletion.
    }
};
