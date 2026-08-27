<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_sla_breaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('module');
            $table->dateTime('stage_entered_at');
            $table->dateTime('reminder_sent_at')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->foreignId('escalated_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('status', ['open', 'resolved'])->default('open');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status'], 'csb_customer_status_idx');
            $table->index(['status', 'module'], 'csb_status_module_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_sla_breaches');
    }
};
