<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ExpandOrdersDeliveryType extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `orders` MODIFY `delivery_type` VARCHAR(30) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `orders` MODIFY `delivery_type` ENUM('belpost', 'europochta') NULL");
    }
}
