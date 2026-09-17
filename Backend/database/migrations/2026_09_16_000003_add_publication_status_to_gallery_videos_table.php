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
            if (! Schema::hasColumn('gallery_videos', 'publication_status')) {
                $table->string('publication_status', 20)->default('DRAFT')->after('display_order');
                $table->index('publication_status');
            }
        });

        DB::table('gallery_videos')
            ->whereNotNull('published_at')
            ->update(['publication_status' => 'PUBLISHED']);
    }

    public function down(): void
    {
        Schema::table('gallery_videos', function (Blueprint $table): void {
            if (Schema::hasColumn('gallery_videos', 'publication_status')) {
                $table->dropIndex(['publication_status']);
                $table->dropColumn('publication_status');
            }
        });
    }
};
