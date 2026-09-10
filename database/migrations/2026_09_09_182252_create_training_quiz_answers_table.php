<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_quiz_question_id')->constrained()->cascadeOnDelete();
            $table->json('answer')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->unsignedTinyInteger('marks_awarded')->default(0);
            $table->timestamps();

            $table->unique(
                ['training_quiz_attempt_id', 'training_quiz_question_id'],
                'training_quiz_answer_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_quiz_answers');
    }
};
