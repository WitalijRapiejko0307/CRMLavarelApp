<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_batches', function (Blueprint $table) {
            $table->string('who_pays', 20)->default('Покупатель')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('mail_batches', function (Blueprint $table) {
            $table->dropColumn('who_pays');
        });
    }
};
