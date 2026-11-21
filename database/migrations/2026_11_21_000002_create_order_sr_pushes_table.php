<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attempt log for pushes to SalesRender (retained 30 days, see PurgeSrPushLogCommand).
        Schema::create('order_sr_pushes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('fields')->nullable();
            $table->string('status', 16); // success | failed | skipped | conflict
            $table->string('error_code', 40)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('message', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sr_pushes');
    }
};
