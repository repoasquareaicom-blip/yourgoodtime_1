<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_video_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gallery_video_id')->constrained('gallery_videos')->cascadeOnDelete();
            $table->string('event_type', 20);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('visitor_key', 80)->nullable();
            $table->string('ip_hash', 128)->nullable();
            $table->timestamps();

            $table->index(['gallery_video_id', 'event_type']);
            $table->index(['event_type', 'visitor_key']);
            $table->index(['event_type', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_video_events');
    }
};
