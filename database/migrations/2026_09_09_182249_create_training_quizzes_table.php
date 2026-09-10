<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table serves both lesson quizzes and course-level assessments.
 *
 * The brief listed training_quizzes and training_assessments separately,
 * but they differ only in what they hang off (a lesson vs a course) —
 * identical questions, identical attempts, identical pass rules. A
 * polymorphic owner plus a `kind` discriminator collapses four tables
 * (quizzes/questions/assessments/results) into three and means the
 * grading service, the attempt guard and the trainee UI exist once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_quizzes', function (Blueprint $table) {
            $table->id();
            $table->morphs('quizzable');
            $table->string('kind')->default('quiz');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('pass_percentage')->default(60);
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->string('status')->default('published');
            $table->timestamps();

            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_quizzes');
    }
};
