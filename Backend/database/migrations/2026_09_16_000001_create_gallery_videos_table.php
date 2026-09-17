<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_videos', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 120)->default('General');
            $table->string('video_type', 20);
            $table->string('video_path', 500);
            $table->string('thumbnail_path', 500)->nullable();
            $table->integer('display_order')->default(0);
            $table->string('publication_status', 20)->default('DRAFT');
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('is_active');
            $table->index('category');
            $table->index('video_type');
            $table->index('published_at');
            $table->index('display_order');
            $table->index('publication_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_videos');
    }
};
