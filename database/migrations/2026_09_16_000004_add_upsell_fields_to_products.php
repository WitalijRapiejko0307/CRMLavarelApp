<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('upsell_name')->nullable()->after('weight');
            $table->decimal('upsell_price', 12, 2)->nullable()->after('upsell_name');
            $table->text('upsell_text')->nullable()->after('upsell_price');
            $table->string('cross_name')->nullable()->after('upsell_text');
            $table->decimal('cross_price', 12, 2)->nullable()->after('cross_name');
            $table->text('cross_text')->nullable()->after('cross_price');
            $table->text('manager_note')->nullable()->after('cross_text');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'upsell_name',
                'upsell_price',
                'upsell_text',
                'cross_name',
                'cross_price',
                'cross_text',
                'manager_note',
            ]);
        });
    }
};
