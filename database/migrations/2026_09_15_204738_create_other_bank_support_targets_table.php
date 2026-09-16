<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly target the Admin sets for each Other Bank Support user. Separate
 * from the LMS target engine (employees.category / AchievementCalculatorService)
 * and from the Daily Commitment module's monthly_commitment_targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_bank_support_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            /** Always stored as the first day of the month. */
            $table->date('month');
            $table->decimal('target_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_bank_support_targets');
    }
};
