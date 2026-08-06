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
        Schema::create('customer_financial_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')
                ->constrained()
                ->cascadeOnDelete();

            // Snapshot source
            $table->enum('source', [
                'sales',
                'mis',
                'finance'
            ]);

            // Snapshot version
            $table->unsignedInteger('version')->default(1);

            // Bank Details
            $table->string('bank_name')->nullable();
            $table->string('lan_no')->nullable();

            // Loan Details
            $table->decimal('sanctioned_loan_amount', 15, 2)->nullable();
            $table->decimal('disbursed_amount', 15, 2)->nullable();

            // Revenue Components
            $table->decimal('cashback', 15, 2)->default(0);
            $table->decimal('subvention', 15, 2)->default(0);
            $table->decimal('docking', 15, 2)->default(0);

            // Commission
            $table->decimal('gross_commission', 15, 2)->default(0);
            $table->decimal('net_commission', 15, 2)->default(0);

            // Bank Charges
            $table->decimal('processing_fee', 15, 2)->nullable();
            $table->decimal('roi', 5, 2)->nullable();

            // Dates
            $table->date('disbursal_date')->nullable();

            // MIS Information
            $table->string('mis_batch')->nullable();

            // Remarks
            $table->text('remarks')->nullable();

            // User who created snapshot
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['customer_id', 'source']);
            $table->index(['customer_id', 'version']);
            $table->index('lan_no');


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_financial_snapshots');
    }
};
