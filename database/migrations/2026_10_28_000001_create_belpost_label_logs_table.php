<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('belpost_label_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_batch_id')->nullable()->constrained('mail_batches')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind', 10)->default('blanks');
            $table->string('label_size', 10)->nullable();
            $table->unsignedInteger('orders_count')->nullable();
            $table->string('result', 10);
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['mail_batch_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('belpost_label_logs');
    }
};
