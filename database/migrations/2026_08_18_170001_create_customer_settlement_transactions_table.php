<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_settlement_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_settlement_id')->constrained('customer_settlements')->cascadeOnDelete();
            $table->enum('type', ['payment', 'advance', 'recovery', 'adjustment', 'refund', 'surplus']);
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date')->nullable();
            $table->string('reference_no')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['customer_settlement_id', 'type'],
                'cst_settlement_type_idx'
            );
            $table->index(
                'transaction_date',
                'cst_transaction_date_idx'
            );

            // $table->index(
            //     'utr_number',
            //     'cst_utr_idx'
            // );
            // $table->index(['transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_settlement_transactions');
    }
};
