<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer-wise fulfilment an employee declares at the end of the day
 * against their morning commitment. One row per case worked.
 *
 * `customer_id` points at the existing LMS customer — this table never
 * duplicates customer data, it only records which cases make up a day's
 * claimed business, and at which stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_commitment_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_commitment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            /** Snapshot of the name, so a claim stays readable if the case is later reassigned or removed. */
            $table->string('customer_name');
            /** Lead / application number, free text — auto-filled from the customer when one is picked. */
            $table->string('reference')->nullable();

            /** Ladder stage this case reached, as declared by the employee. */
            $table->string('stage', 30);
            /** Highest ladder stage the LMS itself says the case reached, resolved when the row is saved. */
            $table->string('lms_highest_stage', 30)->nullable();
            /** Terminal outcome, if any: dropped / rejected. Never a ladder stage. */
            $table->string('outcome', 20)->nullable();

            $table->decimal('amount', 15, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['daily_commitment_id', 'stage']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_commitment_entries');
    }
};
