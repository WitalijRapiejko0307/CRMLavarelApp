<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_connections', function (Blueprint $table) {
            $table->boolean('include_existing_active')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_connections', function (Blueprint $table) {
            $table->dropColumn('include_existing_active');
        });
    }
};
