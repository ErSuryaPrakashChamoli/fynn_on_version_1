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
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('loan_type');

            // Source (DSA / Connector / Branch / API Partner)
            // $table->string('source')->nullable();
            $table->string('payment_from')->nullable();

            // Payout Percentage
            $table->decimal('payout', 5, 2)->default(0.00);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Prevent duplicate product configuration
            $table->unique([
                'bank_name',
                'loan_type',
                'payment_from',
            ], 'bank_product_unique');
            // $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
