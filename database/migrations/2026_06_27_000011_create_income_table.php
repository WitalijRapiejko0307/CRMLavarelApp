<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateIncomeTable extends Migration
{
    public function up(): void
    {
        Schema::create('income', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->text('description')->nullable();
            $table->string('source', 100)->nullable();
            $table->date('date');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('set null');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income');
    }
}
