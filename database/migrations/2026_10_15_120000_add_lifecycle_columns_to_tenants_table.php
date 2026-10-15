<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLifecycleColumnsToTenantsTable extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('leads_enabled')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('deletion_requested_by')->nullable()->index();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('deletion_scheduled_for')->nullable();

            $table->foreign('deletion_requested_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['deletion_requested_by']);
            $table->dropColumn([
                'leads_enabled',
                'archived_at',
                'deletion_requested_by',
                'deletion_requested_at',
                'deletion_scheduled_for',
            ]);
        });
    }
}
