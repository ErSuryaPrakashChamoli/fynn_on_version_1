<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainee_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedSmallInteger('total_marks')->default(0);
            $table->unsignedTinyInteger('percentage')->default(0);
            $table->boolean('passed')->default(false);
            $table->string('status')->default('in_progress');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['trainee_id', 'training_quiz_id']);
            $table->index(['training_enrollment_id', 'passed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_quiz_attempts');
    }
};
