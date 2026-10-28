<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ZIP download from Belpost was removed: batches are only draft or committed.
 * Legacy columns id_to_download / pdf_path / error_message are kept for one release.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mail_batches')
            ->whereIn('status', ['downloading', 'ready', 'failed'])
            ->where('belpost_committed', true)
            ->update(['status' => 'committed']);

        DB::table('mail_batches')
            ->whereIn('status', ['downloading', 'ready', 'failed', 'created'])
            ->where('belpost_committed', false)
            ->update(['status' => 'draft']);
    }

    public function down(): void
    {
        // irreversible: previous statuses are not restorable
    }
};
