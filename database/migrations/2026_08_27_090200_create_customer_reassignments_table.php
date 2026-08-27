<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_reassignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('previous_owner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('new_owner_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('reassigned_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->dateTime('reassigned_at');
            $table->timestamps();

            $table->index(['customer_id', 'created_at'], 'cr_customer_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_reassignments');
    }
};
