<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserFeedbacksTable extends Migration
{
    public function up(): void
    {
        Schema::create('user_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 32);
            $table->json('answers');
            $table->timestamps();

            $table->unique(['user_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedbacks');
    }
}
