<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('demo_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('demo_bank_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('demo_loan_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('demo_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('application_no');
            $table->string('lan_no')->nullable();
            $table->unsignedBigInteger('applied_amount')->default(0);
            $table->unsignedBigInteger('sanctioned_amount')->default(0);
            $table->unsignedBigInteger('disbursed_amount')->default(0);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->unsignedSmallInteger('tenure_months')->default(36);
            $table->string('status')->default('login');
            $table->date('applied_on')->nullable();
            $table->date('sanctioned_on')->nullable();
            $table->date('disbursed_on')->nullable();
            $table->decimal('payout_rate', 5, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('demo_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_applications');
    }
};
