<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('demo_lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('demo_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_code');
            $table->string('customer_name');
            $table->string('mobile_no', 20);
            $table->string('email')->nullable();
            $table->string('pan_number', 15)->nullable();
            $table->string('city')->nullable();
            $table->string('company_name')->nullable();
            $table->string('company_category')->nullable();
            $table->unsignedBigInteger('salary')->default(0);
            $table->unsignedBigInteger('eligible_loan_amount')->default(0);
            $table->string('journey_status')->default('otp');
            $table->string('eligibility_status')->default('pending');
            $table->timestamps();

            $table->index(['tenant_id', 'journey_status']);
            $table->index('demo_employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_customers');
    }
};
