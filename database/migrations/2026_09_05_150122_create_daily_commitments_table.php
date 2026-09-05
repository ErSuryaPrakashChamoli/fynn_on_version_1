<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One morning commitment per employee per day for the Daily Commitment
 * module. Deliberately separate from employee_targets (the existing LMS
 * monthly target/incentive engine) — nothing here feeds that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->string('commitment_stage', 30);
            $table->decimal('commitment_amount', 15, 2)->default(0);
            $table->unsignedInteger('commitment_count')->default(0);

            /** Highest stage the employee's own cases reached on this date. */
            $table->string('current_stage', 30)->nullable();
            $table->decimal('achievement_amount', 15, 2)->default(0);
            $table->unsignedInteger('achievement_count')->default(0);

            $table->string('result', 20)->default('in_progress');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'commitment_stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_commitments');
    }
};
