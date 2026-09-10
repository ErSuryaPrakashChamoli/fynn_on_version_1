<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trainer feedback on one trainee's enrollment.
 *
 * is_visible_to_trainee exists because a trainer needs somewhere to
 * record an internal note ("not ready for the floor yet") that the
 * trainee must not read; the trainee-facing query filters on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained('users')->cascadeOnDelete();
            $table->text('remark');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('is_visible_to_trainee')->default(true);
            $table->timestamps();

            $table->index(['training_enrollment_id', 'is_visible_to_trainee'], 'training_remarks_visible_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_remarks');
    }
};
