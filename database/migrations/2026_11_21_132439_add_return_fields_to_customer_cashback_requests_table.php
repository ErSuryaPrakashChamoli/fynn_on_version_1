<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A paid cashback the customer gives back (in full or in part). The case's
 * Team Leader or Manager requests it with how much came back, when, how
 * (mode + UTR) and why; it counts — the case's cashback drops by the
 * returned amount, so the achievement and incentive figures recover it —
 * only once the Admin approves it (return_status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_cashback_requests', function (Blueprint $table): void {
            $table->string('return_status')->nullable()->after('retrieval_reason')->index();
            $table->decimal('returned_amount', 12, 2)->nullable()->after('return_status');
            $table->date('return_date')->nullable()->after('returned_amount');
            $table->string('return_mode')->nullable()->after('return_date');
            $table->string('return_reference')->nullable()->after('return_mode');
            $table->text('return_reason')->nullable()->after('return_reference');
            $table->foreignId('return_requested_by')->nullable()->after('return_reason')->constrained('users')->nullOnDelete();
            $table->dateTime('return_requested_at')->nullable()->after('return_requested_by');
            $table->foreignId('return_reviewed_by')->nullable()->after('return_requested_at')->constrained('users')->nullOnDelete();
            $table->dateTime('return_reviewed_at')->nullable()->after('return_reviewed_by');
            $table->text('return_review_note')->nullable()->after('return_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_cashback_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('return_requested_by');
            $table->dropConstrainedForeignId('return_reviewed_by');
            $table->dropIndex(['return_status']);
            $table->dropColumn(['return_status', 'returned_amount', 'return_date', 'return_mode', 'return_reference', 'return_reason', 'return_requested_at', 'return_reviewed_at', 'return_review_note']);
        });
    }
};
