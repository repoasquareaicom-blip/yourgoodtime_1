<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chatbot_openai_cache')) {
            Schema::create('chatbot_openai_cache', function (Blueprint $table): void {
                $table->id();
                $table->text('normalized_question');
                $table->string('question_hash', 64);
                $table->text('original_question');
                $table->longText('answer');
                $table->string('model', 100)->nullable();
                $table->integer('knowledge_version')->default(1);
                $table->unsignedBigInteger('hit_count')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->unique(['question_hash', 'knowledge_version', 'model'], 'chatbot_openai_cache_lookup_unique');
                $table->index(['question_hash', 'knowledge_version'], 'chatbot_openai_cache_hash_version_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_openai_cache');
    }
};
