<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_pan_requests', function (Blueprint $table) {
            $table->id();

            // Existing customer
            $table->foreignId('customer_id')
                ->constrained()
                ->cascadeOnDelete();

            // Caller who requested
            $table->foreignId('requested_by')
                ->constrained('employees')
                ->cascadeOnDelete();

            // Requested bank
            $table->foreignId('requested_bank_id')
                ->constrained('banks')
                ->cascadeOnDelete();

            // Loan details
            $table->string('requested_loan_type');

            // Reason for requesting duplicate PAN
            $table->text('reason')->nullable();

            // Approval Status
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
            ])->default('pending');

            // Admin who approved/rejected
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            // Admin remarks
            $table->text('remarks')->nullable();

            // Loan application created after approval
            $table->foreignId('application_id')
                ->nullable()
                ->constrained('loan_applications')
                ->nullOnDelete();

            $table->timestamps();

            // Helpful indexes
            $table->index('status');
            $table->index(['customer_id', 'status']);
            $table->index('requested_by');
            $table->index('requested_bank_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_pan_requests');
    }
};
