<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single row that grants a trainee access to a course.
 *
 * Every trainee-facing read in the Academy — lessons, documents,
 * quizzes, certificates — resolves back to an enrollment owned by the
 * authenticated user. There is no other path to training content, which
 * is what makes the IDOR surface one table wide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainee_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('assigned');
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['training_course_id', 'trainee_id'], 'training_enrollment_unique');
            $table->index(['trainee_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_enrollments');
    }
};
