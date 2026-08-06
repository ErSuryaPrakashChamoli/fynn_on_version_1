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
        Schema::create('mis_batches', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Batch Details
            |--------------------------------------------------------------------------
            */

            $table->string('batch_name');                 // Axis August Batch 1
            $table->string('batch_code')->nullable();     // AXIS-2026-08-001

            /*
            |--------------------------------------------------------------------------
            | Bank Details
            |--------------------------------------------------------------------------
            */

            // Future: Replace with bank_id FK if you create Banks Master
            $table->string('bank_name')->nullable();

            $table->string('loan_product')->nullable();   // Personal Loan / Home Loan / LAP

            /*
            |--------------------------------------------------------------------------
            | MIS Period
            |--------------------------------------------------------------------------
            */

            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');

            $table->date('settlement_month')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Import Details
            |--------------------------------------------------------------------------
            */

            $table->enum('import_type', [
                'full',
                'incremental',
                'manual',
                'correction',
            ])->default('full');

            $table->enum('source', [
                'upload',
                'email',
                'api',
                'ftp',
                'manual',
            ])->default('upload');

            /*
            |--------------------------------------------------------------------------
            | Uploaded File
            |--------------------------------------------------------------------------
            */

            $table->string('file_name');
            $table->string('file_path')->nullable();

            $table->unsignedBigInteger('file_size')->nullable();

            $table->string('mime_type')->nullable();

            $table->string('checksum', 64)->nullable(); // Prevent duplicate upload

            /*
            |--------------------------------------------------------------------------
            | Processing Statistics
            |--------------------------------------------------------------------------
            */

            $table->integer('total_records')->default(0);

            $table->integer('matched_records')->default(0);

            $table->integer('unmatched_records')->default(0);

            $table->integer('duplicate_records')->default(0);

            $table->integer('updated_records')->default(0);

            $table->integer('new_records')->default(0);

            $table->integer('variance_records')->default(0);

            $table->integer('ignored_records')->default(0);

            $table->integer('failed_records')->default(0);

            /*
            |--------------------------------------------------------------------------
            | Financial Summary
            |--------------------------------------------------------------------------
            */

            $table->decimal('total_disbursed_amount', 18, 2)->default(0);

            $table->decimal('total_cashback', 18, 2)->default(0);

            $table->decimal('total_subvention', 18, 2)->default(0);

            $table->decimal('total_docking', 18, 2)->default(0);

            $table->decimal('total_commission', 18, 2)->default(0);

            $table->decimal('total_expected_commission', 18, 2)->default(0);

            $table->decimal('total_actual_commission', 18, 2)->default(0);

            $table->decimal('total_sales_incentive', 18, 2)->default(0);

            $table->decimal('total_tds', 18, 2)->default(0);

            $table->decimal('total_gst', 18, 2)->default(0);

            $table->decimal('total_net_payable', 18, 2)->default(0);

            $table->decimal('total_variance', 18, 2)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Processing Status
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [
                'uploaded',
                'processing',
                'processed',
                'verified',
                'completed',
                'failed',
            ])->default('uploaded');

            /*
            |--------------------------------------------------------------------------
            | Upload Information
            |--------------------------------------------------------------------------
            */

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamp('uploaded_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Processing Information
            |--------------------------------------------------------------------------
            */

            $table->timestamp('started_at')->nullable();

            $table->timestamp('completed_at')->nullable();

            $table->integer('processing_time')->nullable(); // Seconds

            /*
            |--------------------------------------------------------------------------
            | Verification
            |--------------------------------------------------------------------------
            */

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Batch Lock
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_locked')->default(false);

            $table->foreignId('locked_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('locked_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Error & Remarks
            |--------------------------------------------------------------------------
            */

            $table->text('remarks')->nullable();

            $table->text('failure_reason')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index('status');

            $table->index('uploaded_by');

            $table->index(['bank_name', 'month', 'year']);

            $table->index(['status', 'month', 'year']);

            $table->index('batch_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mis_batches');
    }
};
