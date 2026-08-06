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
            // Bank
            $table->foreignId('bank_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Batch Details
            $table->string('batch_name');                 // Example: Axis August Batch 1
            $table->string('batch_code')->nullable();     // AXIS-2026-08-01

            // MIS Period
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');

            // Uploaded File
            $table->string('file_name');
            $table->string('file_path')->nullable();

            // File Statistics
            $table->integer('total_records')->default(0);
            $table->integer('matched_records')->default(0);
            $table->integer('unmatched_records')->default(0);
            $table->integer('duplicate_records')->default(0);
            $table->integer('updated_records')->default(0);
            $table->integer('new_records')->default(0);

            // Financial Summary
            $table->decimal('total_disbursed_amount', 18, 2)->default(0);
            $table->decimal('total_cashback', 18, 2)->default(0);
            $table->decimal('total_subvention', 18, 2)->default(0);
            $table->decimal('total_docking', 18, 2)->default(0);
            $table->decimal('total_commission', 18, 2)->default(0);

            // Processing Status
            $table->enum('status', [
                'uploaded',
                'processing',
                'processed',
                'verified',
                'completed',
                'failed',
            ])->default('uploaded');

            // Upload Information
            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamp('uploaded_at')->nullable();

            // Verification
            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();

            // Remarks
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(['bank_id', 'month', 'year']);
            $table->index('status');
            $table->index('uploaded_by');

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
