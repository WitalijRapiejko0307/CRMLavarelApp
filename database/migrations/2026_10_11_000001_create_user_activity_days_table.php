<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserActivityDaysTable extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('activity_date');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'activity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_days');
    }
}
