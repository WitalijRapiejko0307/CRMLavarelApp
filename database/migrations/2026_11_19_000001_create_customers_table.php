<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomersTable extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Last 9 digits of the phone: the lookup key (see PhoneNormalizer::lastNineDigits).
            $table->string('phone_key', 9);
            $table->string('phone', 20)->nullable();
            $table->string('full_name')->nullable();

            // Address block (always copied as a unit).
            $table->string('city')->nullable();
            $table->string('street')->nullable();
            $table->string('building', 20)->nullable();
            $table->string('housing', 20)->nullable();
            $table->string('apartment', 20)->nullable();
            $table->boolean('poste_restante')->default(false);

            // Delivery preferences from the last successful order.
            $table->string('delivery_type', 20)->nullable();
            $table->string('belpost_address_id', 50)->nullable();
            $table->unsignedBigInteger('europochta_store_id')->nullable();

            // Profile source / statistics.
            $table->unsignedBigInteger('last_order_id')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->unsignedInteger('orders_count')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'phone_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
}
