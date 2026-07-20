<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('mail_batch_id')->nullable()->after('belpost_address_id')->index();
            $table->foreign('mail_batch_id')->references('id')->on('mail_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['mail_batch_id']);
            $table->dropColumn('mail_batch_id');
        });
    }
};
