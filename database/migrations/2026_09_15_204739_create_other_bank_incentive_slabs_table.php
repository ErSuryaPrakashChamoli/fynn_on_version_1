<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-defined incentive slabs for the Other Bank Support team. A set of
 * slabs applies from its effective_month until a later month defines a new
 * set, so the Admin only re-enters slabs when they actually change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_bank_incentive_slabs', function (Blueprint $table) {
            $table->id();
            /** Always stored as the first day of the month. */
            $table->date('effective_month');
            $table->decimal('min_achievement', 15, 2);
            $table->string('payout_type', 20);
            $table->decimal('payout_value', 15, 4);
            $table->timestamps();

            // Named explicitly: the generated name exceeds MySQL's 64-char limit.
            $table->unique(['effective_month', 'min_achievement'], 'ob_incentive_slabs_month_min_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_bank_incentive_slabs');
    }
};
