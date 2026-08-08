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

            // Existing Customer
            $table->foreignId('customer_id')
                ->constrained()
                ->cascadeOnDelete();

            // Request Number
            $table->string('request_no')->unique()->nullable();

            // Requested By
            $table->foreignId('requested_by')
                ->constrained('employees')
                ->cascadeOnDelete();

            $table->string('requested_by_emp_id');
            $table->string('requested_by_name');

            // Team Leader Snapshot
            $table->foreignId('team_leader_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->string('team_leader_name')->nullable();

            // Manager Snapshot
            $table->foreignId('manager_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->string('manager_name')->nullable();

            // Cluster Manager Snapshot
            $table->foreignId('cluster_manager_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->string('cluster_manager_name')->nullable();

            // Requested Bank
            $table->foreignId('requested_bank_id')
                ->constrained('banks')
                ->cascadeOnDelete();

            $table->string('requested_bank_name');

            // Loan Details
            $table->string('requested_loan_type');

            // Reason
            $table->text('reason')->nullable();

            // Status
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
            ])->default('pending');

            // Admin Approval
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            $table->text('remarks')->nullable();

            // Future Loan Application
            $table->unsignedBigInteger('application_id')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['customer_id', 'status']);
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
