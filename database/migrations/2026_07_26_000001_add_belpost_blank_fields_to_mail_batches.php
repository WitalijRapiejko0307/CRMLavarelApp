<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_batches', function (Blueprint $table) {
            $table->string('label_size', 10)->default('150x100')->after('who_pays');
            $table->boolean('belpost_committed')->default(false)->after('label_size');
        });

        // Existing batches that went through commit retain committed flag.
        DB::table('mail_batches')
            ->whereIn('status', ['committed', 'downloading', 'ready', 'failed'])
            ->update(['belpost_committed' => true]);
    }

    public function down(): void
    {
        Schema::table('mail_batches', function (Blueprint $table) {
            $table->dropColumn(['label_size', 'belpost_committed']);
        });
    }
};
