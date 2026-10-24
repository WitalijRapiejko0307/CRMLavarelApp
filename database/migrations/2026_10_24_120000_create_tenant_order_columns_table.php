<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantOrderColumnsTable extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_order_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('field', 64);
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['tenant_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_order_columns');
    }
}
