<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBelpostTrackingDraftsTable extends Migration
{
    public function up(): void
    {
        Schema::create('belpost_tracking_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('track_number');
            $table->string('event')->nullable();
            $table->string('event_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'track_number']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('belpost_tracking_drafts');
    }
}
