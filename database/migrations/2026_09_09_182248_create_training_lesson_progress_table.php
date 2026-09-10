<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_lesson_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('not_started');
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->unsignedInteger('seconds_spent')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['training_enrollment_id', 'training_lesson_id'], 'training_lesson_progress_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_lesson_progress');
    }
};
