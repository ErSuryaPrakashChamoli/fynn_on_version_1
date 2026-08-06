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

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Verified By
            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();


            // Sales Snapshot
            $table->decimal('sales_disbursal_amount', 15, 2)->nullable();
            $table->decimal('sales_cashback', 15, 2)->nullable();
            $table->decimal('sales_subvention', 15, 2)->nullable();
            $table->decimal('sales_docking', 15, 2)->nullable();

            $table->timestamps();

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
