<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_quiz_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->string('type')->default('single_choice');
            $table->json('options')->nullable();
            $table->json('correct_answer');
            $table->text('explanation')->nullable();
            $table->unsignedTinyInteger('marks')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['training_quiz_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_quiz_questions');
    }
};
