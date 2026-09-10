<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('demo_lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('demo_customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('demo_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('call');
            $table->dateTime('scheduled_at');
            $table->string('status')->default('pending');
            $table->string('outcome')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_follow_ups');
    }
};
