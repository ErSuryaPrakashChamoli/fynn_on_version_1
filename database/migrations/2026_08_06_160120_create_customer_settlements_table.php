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
            // Relationships
            $table->foreignId('customer_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('mis_batch_id')
                ->nullable()
                ->constrained('mis_batches')
                ->nullOnDelete();

            $table->unsignedInteger('version')->default(1);

            /*
            |--------------------------------------------------------------------------
            | Product Details
            |--------------------------------------------------------------------------
            */

            // Product selected during application
            $table->string('product_type')->nullable();

            // Product received from Bank MIS
            $table->string('bank_product_type')->nullable();

            // Product mismatch
            $table->boolean('product_mismatch')->default(false);

            /*
            |--------------------------------------------------------------------------
            | MIS Values
            |--------------------------------------------------------------------------
            */
            $table->decimal('mis_disbursal_amount', 15, 2)->nullable();
            $table->decimal('mis_cashback', 15, 2)->nullable();
            $table->decimal('mis_subvention', 15, 2)->nullable();
            $table->decimal('mis_docking', 15, 2)->nullable();
            $table->decimal('mis_processing_fee', 15, 2)->nullable();

            $table->decimal('mis_roi', 5, 2)->nullable();

            $table->string('mis_lan_no')->nullable()->index();

            $table->date('mis_disbursal_date')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Calculated Values
            |--------------------------------------------------------------------------
            */
            $table->decimal('company_commission', 15, 2)->default(0);

            $table->decimal('sales_incentive', 15, 2)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Variance
            |--------------------------------------------------------------------------
            */
            $table->decimal('variance_amount', 15, 2)->default(0);

            $table->decimal('variance_cashback', 15, 2)->default(0);

            $table->decimal('variance_subvention', 15, 2)->default(0);

            $table->decimal('variance_docking', 15, 2)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Verification
            |--------------------------------------------------------------------------
            */
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
            $table->timestamps();


            $table->decimal('sales_disbursal_amount', 15, 2)->nullable();
            $table->decimal('sales_cashback', 15, 2)->nullable();
            $table->decimal('sales_subvention', 15, 2)->nullable();
            $table->decimal('sales_docking', 15, 2)->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Commission
            |--------------------------------------------------------------------------
            */

            // Expected commission configured in FYNN-ON (e.g. 2.50%)
            $table->decimal('expected_commission_percentage', 5, 2)->nullable();

            // Commission percentage received from Bank MIS
            $table->decimal('bank_commission_percentage', 5, 2)->nullable();

            // Expected commission amount
            $table->decimal('expected_commission_amount', 15, 2)->default(0);

            // Actual commission amount received from bank
            $table->decimal('bank_commission_amount', 15, 2)->default(0);

            // Difference in commission amount
            $table->decimal('variance_commission', 15, 2)->default(0);


            /*
            |--------------------------------------------------------------------------
            | Tax & Settlement
            |--------------------------------------------------------------------------
            */

            // Expected values calculated by FYNN-ON
            $table->decimal('expected_tds', 15, 2)->default(0);
            $table->decimal('expected_gst', 15, 2)->default(0);
            $table->decimal('expected_payable_amount', 15, 2)->default(0);

            // Bank MIS values
            $table->decimal('mis_tds', 15, 2)->default(0);
            $table->decimal('mis_gst', 15, 2)->default(0);
            $table->decimal('actual_payable_amount', 15, 2)->default(0);

            // Operations adjustments (manual)
            $table->decimal('operations_tds', 15, 2)->default(0);
            $table->decimal('operations_gst', 15, 2)->default(0);

            // Finance settlement adjustments
            $table->decimal('settlement_tds', 15, 2)->default(0);
            $table->decimal('settlement_gst', 15, 2)->default(0);

            // Variance
            $table->decimal('variance_tds', 15, 2)->default(0);
            $table->decimal('variance_gst', 15, 2)->default(0);
            $table->decimal('variance_payable_amount', 15, 2)->default(0);


            /*
            |--------------------------------------------------------------------------
            | Payment Tracking
            |--------------------------------------------------------------------------
            */

            $table->date('payment_received_date')->nullable();

            $table->string('utr_number')->nullable();

            $table->string('invoice_number')->nullable();

            $table->enum('payment_status', [
                'pending',
                'partially_paid',
                'paid',
                'hold',
            ])->default('pending');

            // Verified By
            // $table->foreignId('verified_by')
            //     ->nullable()
            //     ->constrained('users')
            //     ->nullOnDelete();


            // $table->timestamp('verified_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index(['customer_id', 'status']);
            $table->index(['mis_batch_id', 'status']);
            $table->index(['status', 'verified_at']);
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
