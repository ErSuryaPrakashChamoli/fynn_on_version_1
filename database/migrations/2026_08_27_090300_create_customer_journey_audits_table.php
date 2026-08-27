<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_journey_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('journey_stage');
            $table->string('module')->nullable();
            $table->string('action');
            $table->foreignId('original_owner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('acting_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('access_type', [
                'normal',
                'temporary_delegation',
                'emergency_takeover',
                'permanent_reassignment',
                'escalation',
            ]);
            $table->foreignId('delegation_id')->nullable()->constrained('customer_journey_delegations')->nullOnDelete();
            $table->foreignId('takeover_id')->nullable()->constrained('journey_takeovers')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('performed_at');
            $table->timestamps();

            $table->index(['customer_id', 'performed_at'], 'cja_customer_performed_idx');
            $table->index(['access_type', 'performed_at'], 'cja_access_performed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_journey_audits');
    }
};
