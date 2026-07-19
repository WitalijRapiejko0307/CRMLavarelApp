<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPdfPathToMailBatchesTable extends Migration
{
    public function up(): void
    {
        Schema::table('mail_batches', function (Blueprint $table) {
            $table->string('pdf_path')->nullable()->after('id_to_download');
            $table->string('error_message')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('mail_batches', function (Blueprint $table) {
            $table->dropColumn(['pdf_path', 'error_message']);
        });
    }
}
