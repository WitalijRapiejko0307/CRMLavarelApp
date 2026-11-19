<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCustomerFieldsToOrdersTable extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            // Set once the customer base has been searched for this order's phone.
            $table->timestamp('customer_lookup_at')->nullable();
            // Last 9 digits of the phone that was looked up (re-lookup only when it changes).
            $table->string('customer_lookup_key', 9)->nullable();
            // {"source_order_id": int, "fields": [..]} — what was auto-filled from the base.
            $table->json('customer_prefill')->nullable();
            $table->timestamp('customer_prefill_ack_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropColumn([
                'customer_id',
                'customer_lookup_at',
                'customer_lookup_key',
                'customer_prefill',
                'customer_prefill_ack_at',
            ]);
        });
    }
}
