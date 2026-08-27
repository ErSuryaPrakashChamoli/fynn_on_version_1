<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_takeovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('original_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('takeover_by_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('takeover_type', [
                'manager_unavailable',
                'emergency',
                'sla_breach',
                'manager_on_leave',
                'manager_resigned',
                'manager_terminated',
                'escalation',
                'other',
            ]);
            $table->text('reason');
            $table->json('modules')->nullable();
            $table->enum('status', ['active', 'ended', 'cancelled'])->default('active');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status'], 'jt_customer_status_idx');
            $table->index(['takeover_by_id', 'status'], 'jt_takeover_by_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_takeovers');
    }
};
