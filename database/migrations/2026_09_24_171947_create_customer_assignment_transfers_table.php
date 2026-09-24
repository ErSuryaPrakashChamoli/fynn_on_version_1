<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_assignment_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_assignment_id')->constrained('customer_assignments')->cascadeOnDelete();
            $table->foreignId('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('transferred_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('reason')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_assignment_transfers');
    }
};
