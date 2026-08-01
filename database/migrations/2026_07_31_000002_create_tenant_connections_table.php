<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantConnectionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('call_center_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('status', ['pending', 'active', 'rejected', 'disconnected'])->default('pending');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->unique(['store_tenant_id', 'call_center_tenant_id']);
            $table->index(['call_center_tenant_id', 'status']);
            $table->index(['store_tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_connections');
    }
}
