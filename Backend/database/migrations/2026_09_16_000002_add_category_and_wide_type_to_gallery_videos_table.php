<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery_videos', function (Blueprint $table): void {
            if (! Schema::hasColumn('gallery_videos', 'category')) {
                $table->string('category', 120)->default('General')->after('description');
                $table->index('category');
            }
        });

        DB::table('gallery_videos')
            ->where('video_type', 'VIDEO')
            ->update(['video_type' => 'WIDE']);
    }

    public function down(): void
    {
        DB::table('gallery_videos')
            ->where('video_type', 'WIDE')
            ->update(['video_type' => 'VIDEO']);

        Schema::table('gallery_videos', function (Blueprint $table): void {
            if (Schema::hasColumn('gallery_videos', 'category')) {
                $table->dropIndex(['category']);
                $table->dropColumn('category');
            }
        });
    }
};
