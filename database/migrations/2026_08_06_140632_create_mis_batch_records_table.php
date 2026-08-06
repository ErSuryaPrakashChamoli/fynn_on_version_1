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
        Schema::create('mis_batch_records', function (Blueprint $table) {
            $table->id();
            // MIS Batch
            $table->foreignId('mis_batch_id')
                ->constrained()
                ->cascadeOnDelete();

            // Matched Customer
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Bank Details
            $table->foreignId('bank_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Identifiers from MIS
            $table->string('application_no')->nullable();
            $table->string('lan_no')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('mobile_no')->nullable();
            $table->string('pan_number')->nullable();

            // Financial Details from MIS
            $table->decimal('sanctioned_loan_amount', 15, 2)->nullable();
            $table->decimal('disbursed_amount', 15, 2)->nullable();
            $table->decimal('cashback', 15, 2)->default(0);
            $table->decimal('subvention', 15, 2)->default(0);
            $table->decimal('docking', 15, 2)->default(0);
            $table->decimal('processing_fee', 15, 2)->nullable();
            $table->decimal('roi', 5, 2)->nullable();

            $table->date('disbursal_date')->nullable();

            // Matching Status
            $table->enum('match_status', [
                'pending',
                'matched',
                'multiple_match',
                'customer_not_found',
                'already_processed',
                'error',
            ])->default('pending');

            // Processing Status
            $table->enum('process_status', [
                'pending',
                'processed',
                'verified',
                'rejected',
            ])->default('pending');

            // Difference Summary
            $table->boolean('has_difference')->default(false);

            $table->json('difference')->nullable();
            /*
            Example:
            {
                "sanctioned_loan_amount": {
                    "old": 850000,
                    "new": 830000
                },
                "cashback": {
                    "old": 15000,
                    "new": 14000
                }
            }
            */

            // Original MIS row (for audit)
            $table->json('raw_data')->nullable();

            // Error / Remarks
            $table->text('remarks')->nullable();

            // Processing Information
            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('match_status');
            $table->index('process_status');
            $table->index('application_no');
            $table->index('lan_no');
            $table->index('mobile_no');
            $table->index('pan_number');
            $table->index(['mis_batch_id', 'customer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mis_batch_records');
    }
};
