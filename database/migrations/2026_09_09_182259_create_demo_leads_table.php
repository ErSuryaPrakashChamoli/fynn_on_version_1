<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('demo_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('demo_bank_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('demo_loan_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lead_code');
            $table->string('customer_name');
            $table->string('mobile_no', 20);
            $table->string('email')->nullable();
            $table->string('pan_number', 15)->nullable();
            $table->string('city')->nullable();
            $table->string('source')->default('tele-calling');
            $table->unsignedBigInteger('salary')->default(0);
            $table->unsignedBigInteger('requested_amount')->default(0);
            $table->string('status')->default('new');
            $table->date('follow_up_date')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_converted')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('demo_employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_leads');
    }
};
