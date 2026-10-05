<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_batches', function (Blueprint $table) {
            $table->boolean('is_partial_receipt')->default(false)->after('who_pays');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('belpost_mailing_item_id', 50)->nullable()->after('belpost_address_id');
            $table->boolean('belpost_partial_receipt_complete')->default(false)->after('belpost_mailing_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['belpost_mailing_item_id', 'belpost_partial_receipt_complete']);
        });

        Schema::table('mail_batches', function (Blueprint $table) {
            $table->dropColumn('is_partial_receipt');
        });
    }
};
