<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_status_history', function (Blueprint $table) {
            $table->index(['order_id', 'to_status', 'user_id'], 'order_status_history_handlers_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_status_history', function (Blueprint $table) {
            $table->dropIndex('order_status_history_handlers_idx');
        });
    }
};
