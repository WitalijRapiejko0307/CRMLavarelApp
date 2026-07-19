<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_settings')
            ->where('key', 'id_project_in_call_centr')
            ->update(['key' => 'company_id_in_call_centre']);
    }

    public function down(): void
    {
        DB::table('tenant_settings')
            ->where('key', 'company_id_in_call_centre')
            ->update(['key' => 'id_project_in_call_centr']);
    }
};
