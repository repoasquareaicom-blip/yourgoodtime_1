<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chatbot_openai_usage')) {
            Schema::create('chatbot_openai_usage', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('conversation_id')->nullable()->constrained('chatbot_conversations')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('model', 100)->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('cached_tokens')->default(0);
                $table->decimal('estimated_cost', 12, 6)->nullable();
                $table->string('response_status', 60);
                $table->boolean('cache_hit')->default(false);
                $table->timestamps();

                $table->index(['model', 'cache_hit']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_openai_usage');
    }
};
