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
        Schema::create('mis_batch_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('mis_batch_id')
                ->constrained()
                ->cascadeOnDelete();

            // Original Excel Row Number
            $table->unsignedInteger('row_number');

            // Matching
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Raw MIS Data
            $table->string('application_no')->nullable();
            $table->string('lan_no')->nullable()->index();

            $table->string('customer_name')->nullable();
            $table->string('mobile_no')->nullable()->index();
            $table->string('pan_number')->nullable()->index();

            $table->string('bank_name')->nullable();

            $table->decimal('loan_amount', 15, 2)->nullable();
            $table->decimal('cashback', 15, 2)->nullable();
            $table->decimal('subvention', 15, 2)->nullable();
            $table->decimal('docking', 15, 2)->nullable();

            $table->decimal('roi', 5, 2)->nullable();

            $table->decimal('processing_fee', 15, 2)->nullable();

            $table->date('disbursal_date')->nullable();

            // Store complete row exactly as received
            $table->json('raw_data');

            // Matching Result
            $table->enum('match_status', [
                'pending',
                'matched',
                'unmatched',
                'duplicate',
                'variance',
            ])->default('pending');

            $table->text('match_remarks')->nullable();

            $table->timestamps();
            $table->index(['mis_batch_id', 'match_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mis_batch_rows');
    }
};
