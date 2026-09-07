<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket raised by a Manager (or the Admin line) saying that somebody
 * on their team has gone inactive, so the Daily Commitment module should
 * stop demanding a monthly target for them.
 *
 * The ticket is what unblocks the month: while one is open or approved,
 * MonthlyTargetGate skips that employee. Approving it is the Admin's
 * call and is what actually marks the employee off the rolls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_inactivity_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            /** Always stored as the first day of the month the target is skipped for. */
            $table->date('month');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'month']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_inactivity_requests');
    }
};
