<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * orders.source stores either a label (site/manual) or the full landing-page URL
 * (sent to SalesRender as source.uri). varchar(50) rejected real URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY source VARCHAR(2000) NULL DEFAULT 'site'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders MODIFY source VARCHAR(50) NULL DEFAULT 'site'");
    }
};
