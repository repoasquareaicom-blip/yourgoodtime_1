<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('astrologer_consultation_questions')) {
            Schema::create('astrologer_consultation_questions', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 120);
                $table->string('question', 500);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }

        Schema::table('astrologer_appointment_questions', function (Blueprint $table): void {
            if (! Schema::hasColumn('astrologer_appointment_questions', 'consultation_question_id')) {
                $table->foreignId('consultation_question_id')
                    ->nullable()
                    ->after('appointment_id')
                    ->constrained('astrologer_consultation_questions')
                    ->nullOnDelete();
            }
        });

        $now = now();
        $questions = [
            ['Career', 'What guidance do I need for my career growth or job change?', 1],
            ['Business', 'What should I understand about my business growth, timing, or obstacles?', 2],
            ['Finance', 'What financial period am I entering and how should I plan money decisions?', 3],
            ['Health', 'What health and wellbeing areas need more attention now?', 4],
            ['Marriage', 'What guidance is available for marriage timing or relationship harmony?', 5],
            ['Family', 'How can I handle current family responsibilities or relationship concerns?', 6],
            ['Education', 'What is the right direction for education, exams, or skill development?', 7],
            ['Property', 'Is this a favourable time for property, house, or vehicle decisions?', 8],
            ['Foreign Travel', 'What are the chances and timing for foreign travel or relocation?', 9],
            ['Spiritual Remedy', 'Which spiritual remedy or temple guidance can help my present situation?', 10],
        ];

        foreach ($questions as [$title, $question, $sortOrder]) {
            DB::table('astrologer_consultation_questions')->updateOrInsert(
                ['title' => $title],
                [
                    'question' => $question,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('astrologer_appointment_questions', function (Blueprint $table): void {
            if (Schema::hasColumn('astrologer_appointment_questions', 'consultation_question_id')) {
                $table->dropConstrainedForeignId('consultation_question_id');
            }
        });

        Schema::dropIfExists('astrologer_consultation_questions');
    }
};
