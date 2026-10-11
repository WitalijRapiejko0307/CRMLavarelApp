<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserFeedbackPromptsTable extends Migration
{
    public function up(): void
    {
        Schema::create('user_feedback_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 32);
            $table->unsignedTinyInteger('snooze_count')->default(0);
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedback_prompts');
    }
}
