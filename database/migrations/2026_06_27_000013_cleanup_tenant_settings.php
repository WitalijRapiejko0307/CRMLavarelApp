<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OBSOLETE_KEYS = [
        'active_list',
        'id_to_download',
        'belpost_recipient_email',
        'ep_weight_categories',
        'sr_default_item_id',
        'who_pays',
    ];

    public function up(): void
    {
        DB::table('tenant_settings')
            ->whereIn('key', self::OBSOLETE_KEYS)
            ->delete();
    }

    public function down(): void
    {
        // Deleted data cannot be restored; this migration is intentionally irreversible.
    }
};
