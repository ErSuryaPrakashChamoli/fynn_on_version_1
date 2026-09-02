<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_journey_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegating_manager_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('acting_manager_id')->constrained('employees')->cascadeOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->json('modules');
            $table->text('reason');
            $table->enum('status', ['pending', 'active', 'cancelled', 'rejected'])->default('pending');
            $table->boolean('requires_approval')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            // Short explicit index names — MySQL enforces a 64-char identifier limit.
            $table->index(['acting_manager_id', 'status'], 'cjd_acting_status_idx');
            $table->index(['delegating_manager_id', 'status'], 'cjd_delegating_status_idx');
            $table->index(['status', 'start_at', 'end_at'], 'cjd_status_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_journey_delegations');
    }
};
