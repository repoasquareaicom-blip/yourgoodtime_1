<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_remedy_requests', function (Blueprint $table): void {
            $table->unsignedTinyInteger('feedback_rating')->nullable()->after('admin_note');
            $table->text('feedback_comments')->nullable()->after('feedback_rating');
            $table->timestamp('feedback_submitted_at')->nullable()->after('feedback_comments');
        });
    }

    public function down(): void
    {
        Schema::table('temple_remedy_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'feedback_rating',
                'feedback_comments',
                'feedback_submitted_at',
            ]);
        });
    }
};
