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
        Schema::create('customer_settlements', function (Blueprint $table) {
            $table->id();
              /*
             * Human-readable settlement reference.
             *
             * Example:
             * SET-20260812-000001
             */
            $table->string('settlement_no')->unique();

            /*
             * Customer relationship.
             */
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            /*
             * MIS batch from which this settlement was imported.
             */
            $table->foreignId('mis_batch_id')
                ->nullable()
                ->constrained('mis_batches')
                ->nullOnDelete();

            /*
             * Settlement version.
             *
             * Same customer can have:
             *
             * v1
             * v2
             * v3
             *
             * We never overwrite historical settlement data.
             */
            $table->unsignedInteger('version')->default(1);

            // =========================================================
            // MIS / BANK DATA
            // =========================================================

            $table->decimal('mis_disbursal_amount', 15, 2)->nullable();
            $table->decimal('mis_cashback', 15, 2)->nullable();
            $table->decimal('mis_subvention', 15, 2)->nullable();
            $table->decimal('mis_docking', 15, 2)->nullable();
            $table->decimal('mis_processing_fee', 15, 2)->nullable();
            $table->decimal('mis_roi', 5, 2)->nullable();

            $table->string('mis_lan_no')->nullable();
            $table->date('mis_disbursal_date')->nullable();

            // =========================================================
            // SALES DATA
            // =========================================================

            $table->decimal('sales_disbursal_amount', 15, 2)->nullable();
            $table->decimal('sales_cashback', 15, 2)->nullable();
            $table->decimal('sales_subvention', 15, 2)->nullable();
            $table->decimal('sales_docking', 15, 2)->nullable();

            $table->decimal('sales_incentive', 15, 2)->default(0);

            // =========================================================
            // COMPANY COMMISSION
            // =========================================================

            $table->decimal('expected_commission_percentage', 5, 2)->nullable();
            $table->decimal('bank_commission_percentage', 5, 2)->nullable();

            $table->decimal('expected_commission_amount', 15, 2)
                ->default(0);

            $table->decimal('bank_commission_amount', 15, 2)
                ->default(0);

            $table->decimal('company_commission', 15, 2)
                ->default(0);

            $table->decimal('variance_commission', 15, 2)
                ->default(0);

            // =========================================================
            // VARIANCE
            // =========================================================

            $table->decimal('variance_amount', 15, 2)
                ->default(0);

            $table->decimal('variance_cashback', 15, 2)
                ->default(0);

            $table->decimal('variance_subvention', 15, 2)
                ->default(0);

            $table->decimal('variance_docking', 15, 2)
                ->default(0);

            // =========================================================
            // EXPECTED TAX / PAYABLE
            // =========================================================

            $table->decimal('expected_tds', 15, 2)
                ->default(0);

            $table->decimal('expected_gst', 15, 2)
                ->default(0);

            $table->decimal('expected_payable_amount', 15, 2)
                ->default(0);

            // =========================================================
            // MIS TAX / PAYABLE
            // =========================================================

            $table->decimal('mis_tds', 15, 2)
                ->default(0);

            $table->decimal('mis_gst', 15, 2)
                ->default(0);

            $table->decimal('actual_payable_amount', 15, 2)
                ->default(0);

            // =========================================================
            // OPERATIONS TAX
            // =========================================================

            $table->decimal('operations_tds', 15, 2)
                ->default(0);

            $table->decimal('operations_gst', 15, 2)
                ->default(0);

            // =========================================================
            // SETTLEMENT TAX
            // =========================================================

            $table->decimal('settlement_tds', 15, 2)
                ->default(0);

            $table->decimal('settlement_gst', 15, 2)
                ->default(0);

            // =========================================================
            // TAX / PAYABLE VARIANCE
            // =========================================================

            $table->decimal('variance_tds', 15, 2)
                ->default(0);

            $table->decimal('variance_gst', 15, 2)
                ->default(0);

            $table->decimal('variance_payable_amount', 15, 2)
                ->default(0);

            // =========================================================
            // VERIFICATION
            // =========================================================

            $table->enum('status', [
                'pending',
                'verified',
                'rejected',
            ])->default('pending');

            $table->text('remarks')->nullable();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();

            // =========================================================
            // AUDIT
            // =========================================================

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // =========================================================
            // PAYMENT
            // =========================================================

            $table->date('payment_received_date')->nullable();

            $table->string('utr_number')->nullable();

            $table->string('invoice_number')->nullable();

            $table->enum('payment_status', [
                'pending',
                'partially_paid',
                'paid',
                'hold',
            ])->default('pending');

            $table->timestamps();

            // =========================================================
            // INDEXES
            // =========================================================

            $table->unique(
                ['customer_id', 'version'],
                'customer_settlements_customer_version_unique'
            );

            $table->index(
                ['customer_id', 'status'],
                'customer_settlements_customer_status_index'
            );

            $table->index(
                ['mis_batch_id', 'status'],
                'customer_settlements_batch_status_index'
            );

            $table->index(
                ['status', 'verified_at'],
                'customer_settlements_status_verified_at_index'
            );

            $table->index(
                ['mis_lan_no'],
                'customer_settlements_mis_lan_no_index'
            );

            $table->index(
                ['payment_status'],
                'customer_settlements_payment_status_index'
            );
            // $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_settlements');
    }
};
