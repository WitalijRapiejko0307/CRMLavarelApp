<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCallCenterFieldsToOrdersTable extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('call_center_tenant_id')->nullable()->after('tenant_id')->index();
            $table->unsignedBigInteger('last_updated_by_user_id')->nullable()->after('call_center_tenant_id');

            $table->foreign('call_center_tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('last_updated_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'updated_at']);
            $table->index(['call_center_tenant_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['call_center_tenant_id']);
            $table->dropForeign(['last_updated_by_user_id']);
            $table->dropIndex(['tenant_id', 'updated_at']);
            $table->dropIndex(['call_center_tenant_id', 'updated_at']);
            $table->dropColumn(['call_center_tenant_id', 'last_updated_by_user_id']);
        });
    }
}
