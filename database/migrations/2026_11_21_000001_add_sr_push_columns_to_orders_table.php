<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // null = not applicable; synced | pending | retrying | failed | blocked | conflict
            $table->string('sr_sync_state', 16)->nullable()->index();
            // {"phone": {"from": ..., "to": ..., "at": ISO, "user_id": 5, "conflict": false}, ...}
            $table->json('sr_pending_changes')->nullable();
            $table->string('sr_last_error_code', 40)->nullable();
            $table->string('sr_last_error_message', 500)->nullable();
            $table->timestamp('sr_last_attempt_at')->nullable();
            $table->timestamp('sr_last_synced_at')->nullable();
            $table->unsignedTinyInteger('sr_push_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['sr_sync_state']);
            $table->dropColumn([
                'sr_sync_state', 'sr_pending_changes', 'sr_last_error_code', 'sr_last_error_message',
                'sr_last_attempt_at', 'sr_last_synced_at', 'sr_push_attempts',
            ]);
        });
    }
};
