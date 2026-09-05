<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly target for the Daily Commitment module only. The existing LMS
 * target (employees.category / employee_targets, consumed by
 * AchievementCalculatorService) is untouched by this module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_commitment_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            /** Always stored as the first day of the month. */
            $table->date('month');
            $table->string('stage', 30);
            $table->decimal('target_amount', 15, 2)->default(0);
            $table->unsignedInteger('target_count')->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_commitment_targets');
    }
};
