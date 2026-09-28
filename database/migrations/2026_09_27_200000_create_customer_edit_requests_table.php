<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests to change details on a customer file (Request → Customer Edit
 * Requests): one section per request, one row per field asked for. The
 * item rows are also the log — they keep the value at the time of the
 * request, the value asked for, and the value actually overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_edit_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('section');
            $table->text('reason');
            $table->string('status')->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_edit_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_edit_request_id')->constrained('customer_edit_requests')->cascadeOnDelete();
            $table->string('field');
            $table->text('current_value')->nullable();
            $table->text('requested_value')->nullable();
            $table->text('overwritten_value')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_edit_request_items');
        Schema::dropIfExists('customer_edit_requests');
    }
};
