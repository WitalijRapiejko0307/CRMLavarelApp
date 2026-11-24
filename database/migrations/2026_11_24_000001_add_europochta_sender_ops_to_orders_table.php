<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Europochta sender OPS for this order only (null = use tenant setting warehouse_id_start)
            $table->unsignedBigInteger('europochta_store_start_id')->nullable();
            $table->string('europochta_store_start_label', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['europochta_store_start_id', 'europochta_store_start_label']);
        });
    }
};
