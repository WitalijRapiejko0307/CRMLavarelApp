<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMailBatchesTable extends Migration
{
    public function up(): void
    {
        Schema::create('mail_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('batch_id')->nullable();
            $table->string('type', 50)->nullable();
            $table->string('status', 50)->default('created');
            $table->string('id_to_download')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_batches');
    }
}
