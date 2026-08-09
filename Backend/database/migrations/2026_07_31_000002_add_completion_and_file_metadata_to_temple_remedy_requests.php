<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_remedy_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('temple_remedy_requests', 'remedy_file_name')) {
                $table->string('remedy_file_name')->nullable()->after('remedy_file_path');
            }
            if (! Schema::hasColumn('temple_remedy_requests', 'remedy_mime_type')) {
                $table->string('remedy_mime_type', 120)->nullable()->after('remedy_file_name');
            }
            if (! Schema::hasColumn('temple_remedy_requests', 'remedy_file_size')) {
                $table->unsignedInteger('remedy_file_size')->nullable()->after('remedy_mime_type');
            }
            if (! Schema::hasColumn('temple_remedy_requests', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('temple_remedy_requests', 'completed_by_admin_id')) {
                $table->foreignId('completed_by_admin_id')->nullable()->after('completed_at')->constrained('admin_users')->nullOnDelete();
            }
            if (! Schema::hasColumn('temple_remedy_requests', 'completion_method')) {
                $table->string('completion_method', 32)->nullable()->after('completed_by_admin_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('temple_remedy_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('temple_remedy_requests', 'completion_method')) {
                $table->dropColumn('completion_method');
            }
            if (Schema::hasColumn('temple_remedy_requests', 'completed_by_admin_id')) {
                $table->dropConstrainedForeignId('completed_by_admin_id');
            }
            foreach (['completed_at', 'remedy_file_size', 'remedy_mime_type', 'remedy_file_name'] as $column) {
                if (Schema::hasColumn('temple_remedy_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
